<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Jobs\EnrichLeadJob;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Data\ConvertSignalData;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Events\SignalConverted;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Http\Resources\SignalResource;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A signal on the given team, in the team's context.
 *
 * @param  array<string, mixed>  $attributes
 */
function signalFor(Team $team, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, function () use ($team, $attributes): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            ...$attributes,
        ]);
    });
}

/**
 * Convert a signal the way a controller would: inside the team's context.
 */
function convertSignal(Team $team, Signal $signal, User $actor, ?ConvertSignalData $data = null): Lead
{
    return app(TeamContext::class)->run($team, fn (): Lead => app(ConvertSignalToLeadAction::class)
        ->handle($signal, $actor, $data ?? ConvertSignalData::prefillFrom($signal)));
}

it('creates a lead and a contact with no email or phone', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);

    expect($lead->source)->toBe(LeadSource::Signal)
        ->and($lead->company_name)->toBe('Acme DMCC')
        ->and($lead->locality)->toBe('Dubai')
        ->and($lead->country)->toBe('AE')
        ->and($lead->source_url)->toBe($signal->source_url)
        ->and($lead->collected_at)->not->toBeNull()
        ->and($lead->has_personal_data)->toBeTrue()
        ->and($lead->raw['signal_id'])->toBe($signal->id)
        ->and($lead->raw['event_type'])->toBe(SignalPresetCatalogue::FUNDING_ROUND)
        ->and($lead->raw['headline'])->toBe($signal->headline);

    $contact = $lead->contacts()->sole();

    // Signals never holds either, so there is nothing to carry across (ADR 0011).
    expect($contact->name)->toBe('Jane Founder')
        ->and($contact->title)->toBe('Founder & CEO')
        ->and($contact->email)->toBeNull()
        ->and($contact->phone)->toBeNull()
        ->and($contact->is_personal)->toBeTrue()
        ->and($contact->is_role_based)->toBeFalse()
        ->and($contact->source)->toBe(ConvertSignalData::CONTACT_SOURCE)
        ->and($contact->method)->toBe(ConvertSignalData::CONTACT_METHOD)
        ->and($contact->source_url)->toBe($signal->source_url);
});

it('marks the signal converted and points it at the lead', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);

    $signal->refresh();

    expect($signal->status)->toBe(SignalStatus::Converted)
        ->and($signal->lead_id)->toBe($lead->id)
        ->and($signal->actioned_by)->toBe($actor->id)
        ->and($signal->actioned_at)->not->toBeNull();
});

it('returns the same lead when the same signal is converted twice', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $first = convertSignal($team, $signal, $actor);
    $second = convertSignal($team, $signal->refresh(), $actor);

    expect($second->id)->toBe($first->id)
        // A double-click must not leave the team with two leads or two contacts.
        ->and(Lead::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1)
        ->and($first->contacts()->count())->toBe(1);

    // The repeat is not a conversion, so it fires nothing and queues nothing a second time.
    Event::assertDispatchedTimes(SignalConverted::class, 1);
    Queue::assertPushed(EnrichLeadJob::class, 1);
});

it('attaches to a company the team already has instead of duplicating it', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // The team imported this company themselves, with a company number the article doesn't mention.
    $existing = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::Import,
        'company_name' => 'Acme DMCC (Holdings)',
        'company_number' => 'DMCC-99',
        'website' => null,
        'locality' => null,
        'country' => null,
        'dedupe_key' => 'name:signal:acme-dmcc:'.substr(sha1('acme-dmcc'), 0, 12),
        'raw' => ['imported' => true],
    ]));

    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);

    expect($lead->id)->toBe($existing->id)
        ->and(Lead::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1)
        // Fill in, never overwrite: the team's own name for the company stands, the gaps are filled.
        ->and($lead->company_name)->toBe('Acme DMCC (Holdings)')
        ->and($lead->source)->toBe(LeadSource::Import)
        ->and($lead->locality)->toBe('Dubai')
        ->and($lead->country)->toBe('AE')
        // The existing `raw` survives, with the signal's keys merged in rather than replacing it.
        ->and($lead->raw['imported'])->toBeTrue()
        ->and($lead->raw['signal_id'])->toBe($signal->id);

    expect($lead->contacts()->sole()->name)->toBe('Jane Founder');

    Event::assertDispatched(SignalConverted::class, fn (SignalConverted $e): bool => $e->attached === true);
});

it('does not add a second contact for a person the lead already has', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $first = signalFor($team);
    $lead = convertSignal($team, $first, $actor);

    // A second article about the same founder and the same company.
    $second = signalFor($team, ['headline' => 'Acme DMCC founder exits in a trade sale']);
    $again = convertSignal($team, $second, $actor);

    expect($again->id)->toBe($lead->id)
        ->and($again->contacts()->count())->toBe(1);
});

it('matches an existing contact name regardless of case', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);

    $shouty = signalFor($team, ['subject_person_name' => 'JANE FOUNDER']);
    convertSignal($team, $shouty, $actor);

    expect($lead->refresh()->contacts()->count())->toBe(1);
});

it('queues enrichment only after the transaction commits', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);

    Queue::assertPushed(
        EnrichLeadJob::class,
        fn (EnrichLeadJob $job): bool => $job->teamId === $team->id && $job->leadId === $lead->id,
    );
});

it('does not re-enrich a company the team has already enriched', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::CompaniesHouse,
        'company_name' => 'Acme DMCC',
        'enrichment_status' => EnrichmentStatus::Enriched,
        'enriched_at' => now(),
        'dedupe_key' => 'name:signal:acme-dmcc:'.substr(sha1('acme-dmcc'), 0, 12),
    ]));

    convertSignal($team, signalFor($team), $actor);

    Queue::assertNotPushed(EnrichLeadJob::class);
});

it('flags a UAE lead as needing prior consent before outreach', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $lead = convertSignal($team, signalFor($team), $actor);

    expect($lead->raw[OutreachRestriction::RAW_KEY])->toBe(OutreachRestriction::UaePriorConsent->value)
        ->and(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent)
        // Phase 11 reads this to refuse a cold email outright.
        ->and(OutreachRestriction::UaePriorConsent->allowsDirectMarketing())->toBeFalse();
});

it('leaves the outreach flag off a signal found in a market with no such rule', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // A UK signal under a UK watch: no restriction, because ADR 0007 puts the UK on legitimate
    // interest assessed per team (the LIA) rather than a blanket per-lead limit. The four cases
    // below are the ones S8 added, where the signal and the watch disagree.
    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()->create([
            'team_id' => $team->getKey(),
            'market' => Market::Uk,
            'locations' => ['London'],
            'regions' => ['gb'],
        ]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'location' => 'London',
        ]);
    });

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme Ltd',
        personName: 'Jane Founder',
        locality: 'London',
        country: 'GB',
    ));

    expect($lead->raw)->not->toHaveKey(OutreachRestriction::RAW_KEY)
        ->and(OutreachRestriction::fromRaw($lead->raw))->toBeNull();
});

/*
 * The four cases S8 exists for (ADR 0019 §2): **the restriction follows the subject, not the
 * watch**. Until S8 it came from `$signal->watch->market`, which was sound while every watch was
 * UAE and wrong in both directions the moment one could span places.
 */

it('puts no UAE restriction on a London event found by a UAE watch', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // Over-restrictive is the milder direction, and it is still wrong: a British founder held to a
    // rule that does not apply to them, and a team told not to email somebody they lawfully may.
    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()
            ->covering(['Dubai', 'London'], [SearchRegion::Ae, SearchRegion::Gb])
            ->create(['team_id' => $team->getKey(), 'market' => Market::Uae]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'location' => 'London',
            'jurisdiction' => 'GB',
            'resolved_market' => Market::Uk->value,
        ]);
    });

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme Ltd',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBeNull()
        // And the lead can say where that answer came from.
        ->and($lead->raw['jurisdiction'])->toBe('GB');
});

it('puts the UAE restriction on a Dubai event found by a UK watch', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // **The serious direction.** Before S8 this lead got no restriction at all, so Outreach would
    // have seen no reason to refuse a cold email that UAE PDPL forbids and that nothing published
    // can supply consent for (`docs/compliance.md` §3.3, §5a).
    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()
            ->uk()
            ->covering(['London', 'Dubai'], [SearchRegion::Gb, SearchRegion::Ae])
            ->create(['team_id' => $team->getKey(), 'market' => Market::Uk]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'location' => 'Dubai',
            'jurisdiction' => 'AE',
            'resolved_market' => Market::Uae->value,
        ]);
    });

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent)
        ->and($lead->raw['jurisdiction'])->toBe('AE')
        ->and(OutreachRestriction::UaePriorConsent->allowsDirectMarketing())->toBeFalse();
});

it('falls back to the watch market when the signal names nowhere it knows', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // Null jurisdiction is not "unrestricted": the watch's own declared regime answers next.
    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()->create([
            'team_id' => $team->getKey(),
            'market' => Market::Uae,
        ]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'location' => 'Somewhere',
            'jurisdiction' => null,
            'resolved_market' => null,
        ]);
    });

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent)
        // No jurisdiction to report, so the key is absent rather than null — a lead should not
        // carry an empty claim about where somebody is.
        ->and($lead->raw)->not->toHaveKey('jurisdiction');
});

it('takes the most restrictive of a multi-region watch when the signal names nowhere', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // A **UK-market** watch covering London and Dubai, whose signal names neither. The watch's own
    // market says "no restriction"; one of its regions says "prior consent". The stricter answer
    // wins, because the direction to fail in is obvious — and because a restriction may be added
    // but never dropped (`docs/compliance.md` §5a).
    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()
            ->covering(['London', 'Dubai'], [SearchRegion::Gb, SearchRegion::Ae])
            ->create(['team_id' => $team->getKey(), 'market' => Market::Uk]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'location' => 'remote',
            'jurisdiction' => null,
            'resolved_market' => null,
        ]);
    });

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme Ltd',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent);
});

it('converts a signal whose watch has been deleted, using the signal own jurisdiction', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // `signals.signal_watch_id` is `nullOnDelete` (S6 migration) and a converted signal outlives
    // its watch, so a signal with no watch at all is reachable. Steps 2 and 3 have nothing to read;
    // step 1 is all there is, and it must not be a null dereference.
    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $signal = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => null,
            'signal_run_id' => null,
            'location' => 'Dubai',
            'jurisdiction' => 'AE',
            'resolved_market' => Market::Uae->value,
        ]);

        return $signal->fresh();
    });

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent);
});

it('never drops an outreach restriction a lead already carried', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $existing = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::GooglePlaces,
        'company_name' => 'Acme Ltd',
        'country' => 'GB',
        'dedupe_key' => 'name:signal:acme-ltd:'.substr(sha1('acme-ltd'), 0, 12),
        'raw' => [OutreachRestriction::RAW_KEY => OutreachRestriction::UaePriorConsent->value],
    ]));

    // This conversion imposes no restriction of its own — a UK watch — but a compliance limit may
    // only ever be added to a lead, never dropped from one.
    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()->create([
            'team_id' => $team->getKey(),
            'market' => Market::Uk,
            'locations' => ['London'],
            'regions' => ['gb'],
        ]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);
    });

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme Ltd',
        locality: 'London',
        country: 'GB',
    ));

    expect($lead->id)->toBe($existing->id)
        ->and($lead->raw[OutreachRestriction::RAW_KEY])->toBe(OutreachRestriction::UaePriorConsent->value);
});

it('records the conversion on the timeline and in the audit trail', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);

    $activity = LeadActivity::withoutGlobalScopes()
        ->where('lead_id', $lead->id)
        ->where('type', LeadActivityType::SignalConverted->value)
        ->sole();

    expect($activity->user_id)->toBe($actor->id)
        ->and($activity->properties['signal_id'])->toBe($signal->id)
        ->and($activity->properties['event_type'])->toBe(SignalPresetCatalogue::FUNDING_ROUND)
        ->and($activity->properties['attached'])->toBeFalse()
        ->and($activity->properties['headline'])->toBe($signal->headline);

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'signals.converted')->sole();

    expect($audit->properties['lead_id'])->toBe($lead->id)
        ->and($audit->properties['attached'])->toBeFalse()
        ->and($audit->properties['has_contact'])->toBeTrue()
        // The audit trail records that a person was captured, never who.
        ->and($audit->properties)->not->toHaveKey('person_name');
});

it('writes the member’s note onto the lead', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        personName: 'Jane Founder',
        note: "Met her brother at GITEX.\nWorth a warm intro.",
    ));

    expect(LeadNote::withoutGlobalScopes()->where('lead_id', $lead->id)->sole()->body)
        ->toBe("Met her brother at GITEX.\nWorth a warm intro.");
});

it('refuses to convert a signal with no company to name', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // A relocation signal often names a person and no company at all.
    $signal = signalFor($team, [
        'event_key' => SignalPresetCatalogue::RELOCATION,
        'subject_company_name' => null,
    ]);

    expect(fn (): Lead => convertSignal($team, $signal, $actor))
        ->toThrow(SignalsException::class);

    expect($signal->refresh()->status)->toBe(SignalStatus::New)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(0);
});

it('tells the member plainly when an earlier conversion’s lead has been deleted', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);
    app(TeamContext::class)->run($team, fn () => $lead->delete());

    // `signals.lead_id` nulls on delete (the FK is one-way), so there is no lead to hand back and
    // nothing to re-create for a signal that already reads as done.
    expect($signal->refresh()->lead_id)->toBeNull();

    expect(fn (): Lead => convertSignal($team, $signal, $actor))
        ->toThrow(SignalsException::class);
});

it('places the lead in the stage the member picked', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $stage = app(TeamContext::class)->run($team, fn (): PipelineStage => PipelineStage::factory()->create([
        'team_id' => $team->getKey(),
        'name' => 'Warm',
        'slug' => 'warm',
        'is_default' => false,
    ]));

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        pipelineStageId: (int) $stage->getKey(),
    ));

    expect($lead->pipeline_stage_id)->toBe($stage->id);
});

it('ignores a pipeline stage belonging to another team', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();
    $foreign = app(TeamContext::class)->run($otherTeam, fn (): PipelineStage => PipelineStage::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'name' => 'Theirs',
        'slug' => 'theirs',
        'is_default' => false,
    ]));

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        pipelineStageId: (int) $foreign->getKey(),
    ));

    // Dropped, not refused: the conversion was legitimate, so it lands in the team's own default
    // column rather than failing.
    expect($lead->pipeline_stage_id)->not->toBe($foreign->id);

    $stage = PipelineStage::withoutGlobalScopes()->whereKey($lead->pipeline_stage_id)->sole();

    expect($stage->team_id)->toBe($team->id)
        ->and($stage->is_default)->toBeTrue();
});

it('never attaches to another team’s lead with the same company', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();
    app(TeamContext::class)->run($otherTeam, fn (): Lead => Lead::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'source' => LeadSource::Import,
        'company_name' => 'Acme DMCC',
        'dedupe_key' => 'name:signal:acme-dmcc:'.substr(sha1('acme-dmcc'), 0, 12),
    ]));

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $lead = convertSignal($team, signalFor($team), $actor);

    expect($lead->team_id)->toBe($team->id)
        ->and(Lead::withoutGlobalScopes()->where('team_id', $otherTeam->getKey())->count())->toBe(1)
        ->and(Lead::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1);
});

it('converts without a person when the member clears the name', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        personName: null,
    ));

    expect($lead->contacts()->count())->toBe(0)
        // Still flagged: the headline kept in `raw` is the article's own words, and an article
        // about a founder's exit names the founder. The flag answers "is a person in here".
        ->and($lead->has_personal_data)->toBeTrue();
});

it('leaves the personal-data flag off a signal that names no person', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $signal = signalFor($team, [
        'headline' => 'Acme DMCC closes a Series A funding round',
        'subject_person_name' => null,
        'subject_person_role' => null,
    ]);

    $lead = convertSignal($team, $signal, $actor);

    expect($lead->contacts()->count())->toBe(0)
        ->and($lead->has_personal_data)->toBeFalse();
});

it('keeps the UAE restriction when attaching to a lead registered elsewhere', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // The company is on the UK register; the founder the signal is about is in Dubai.
    $existing = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::CompaniesHouse,
        'company_name' => 'Acme DMCC',
        'country' => 'GB',
        'dedupe_key' => 'name:signal:acme-dmcc:'.substr(sha1('acme-dmcc'), 0, 12),
        'raw' => ['registry' => 'uk'],
    ]));

    $lead = convertSignal($team, signalFor($team), $actor);

    // The restriction protects the person, who is where the watch was looking — so it must survive
    // the lead being filed under a company registered somewhere with no such rule.
    expect($lead->id)->toBe($existing->id)
        ->and($lead->country)->toBe('GB')
        ->and($lead->raw[OutreachRestriction::RAW_KEY])->toBe(OutreachRestriction::UaePriorConsent->value);
});

it('merges into a company already on file under a different identity key', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // Keyed by domain, where the conversion keys by name — so only the de-duplicator can catch it.
    $existing = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::CompaniesHouse,
        'company_name' => 'Acme DMCC',
        'company_number' => 'DMCC-1',
        'website' => 'https://acme.ae',
        'locality' => null,
        'dedupe_key' => 'domain:acme.ae',
        'raw' => null,
    ]));

    $lead = convertSignal($team, signalFor($team), $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        website: 'https://www.acme.ae',
        personName: 'Jane Founder',
        locality: 'Dubai',
        country: 'AE',
    ));

    // Our freshly-created row lost the merge to the registry record and the signal points at the
    // survivor — the team is left with one lead, not two.
    expect($lead->id)->toBe($existing->id)
        ->and($lead->source)->toBe(LeadSource::CompaniesHouse)
        ->and($lead->company_number)->toBe('DMCC-1')
        ->and(Lead::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1)
        // The origin pointer and the restriction survive the merge, which is what `stampOrigin` is for.
        ->and($lead->raw['signal_id'])->toBeInt()
        ->and($lead->raw[OutreachRestriction::RAW_KEY])->toBe(OutreachRestriction::UaePriorConsent->value)
        ->and($lead->contacts()->sole()->name)->toBe('Jane Founder');

    Event::assertDispatched(SignalConverted::class, fn (SignalConverted $e): bool => $e->attached === true);
});

it('keeps the first signal’s pointer when a second is converted onto the same lead', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $first = signalFor($team);
    $lead = convertSignal($team, $first, $actor);

    $second = signalFor($team, ['headline' => 'Acme DMCC founder exits in a trade sale']);
    $again = convertSignal($team, $second, $actor);

    // The three origin keys move as one unit or not at all, so the panel can't end up describing
    // this event while the timeline says the lead came from the other one.
    expect($again->id)->toBe($lead->id)
        ->and($again->raw['signal_id'])->toBe($first->id)
        ->and($again->raw['headline'])->toBe($first->headline);

    // Both signals still record their own conversion and both point at the lead.
    expect($first->refresh()->lead_id)->toBe($lead->id)
        ->and($second->refresh()->lead_id)->toBe($lead->id);
});

it('records where a filled-in field came from', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::Import,
        'company_name' => 'Acme DMCC',
        'locality' => null,
        'country' => null,
        'dedupe_key' => 'name:signal:acme-dmcc:'.substr(sha1('acme-dmcc'), 0, 12),
    ]));

    $lead = convertSignal($team, signalFor($team), $actor);

    // ADR 0008: every field can say where it came from — and a value that arrived from a news
    // article is exactly the kind a reviewer will later want to challenge.
    $source = $lead->field_sources['locality'] ?? null;

    expect($source)->not->toBeNull()
        ->and($source->source)->toBe(LeadSource::Signal->value)
        ->and($source->method)->toBe(ConvertSignalData::CONTACT_METHOD)
        ->and($source->sourceUrl)->not->toBeNull();
});

it('refreshes the caller’s signal so it reflects the conversion', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);

    // The action works on its own locked copy, so without this the caller — an S5 controller about
    // to redirect — would still be holding a signal that says New.
    expect($signal->status)->toBe(SignalStatus::Converted)
        ->and($signal->lead_id)->toBe($lead->id);
});

it('attaches to the winner when it loses the insert race', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $dedupeKey = 'name:signal:acme-dmcc:'.substr(sha1('acme-dmcc'), 0, 12);
    $raced = false;

    // Stand in for the other member's insert landing between our lock read and our own insert: the
    // row lock can't hold a row that doesn't exist yet, so the unique index is what catches us.
    Lead::creating(function (Lead $lead) use (&$raced, $team, $dedupeKey): void {
        if ($raced || $lead->dedupe_key !== $dedupeKey) {
            return;
        }

        $raced = true;

        Lead::query()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::Import,
            'company_name' => 'Acme DMCC',
            'dedupe_key' => $dedupeKey,
        ]);
    });

    $lead = convertSignal($team, $signal, $actor);

    // The conversion survives the race rather than 500-ing, and the team is left with one lead.
    expect($raced)->toBeTrue()
        ->and($lead->dedupe_key)->toBe($dedupeKey)
        ->and(Lead::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1)
        ->and($signal->refresh()->lead_id)->toBe($lead->id);
});

it('keeps a long article URL whole on the lead and on its contact', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // Serper answers some articles with a Google redirect instead of the publisher's own link, and
    // those run well past the 255 characters these two columns used to allow. Converting one threw
    // `SQLSTATE[22001]` on the contact insert — so the signals a member most wanted were the ones
    // that could not be converted — while the lead beside it kept a silently truncated link.
    $url = 'https://google.com/goto?url='.str_repeat('A', 260);
    $signal = signalFor($team, ['source_url' => $url]);

    $lead = convertSignal($team, $signal, $actor);
    $contact = $lead->contacts()->firstOrFail();

    // Both citations of the article agree and neither is cut, so provenance survives the trip from
    // signal to lead to contact. (`field_sources` is stamped only when a conversion fills an
    // existing lead's gaps — see the attach test above.)
    expect(strlen($url))->toBeGreaterThan(255)
        ->and($lead->source_url)->toBe($url)
        ->and($contact->source_url)->toBe($url);
});

/*
 * Found by the S8 review. `offered` is a compliance switch over what a watch may **search**; it was
 * also deciding what a lead was **restricted** by, because both read the same accessor. Switching a
 * region off — a config change made for an entirely unrelated reason — therefore relaxed the
 * restriction on signals that region had already produced, which is the fail-permissive direction.
 */

it('still restricts a lead from a region the business has since switched off', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()
            ->covering(['London', 'Dubai'], [SearchRegion::Gb, SearchRegion::Ae])
            ->create(['team_id' => $team->getKey(), 'market' => Market::Uk]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'location' => 'remote',
            'jurisdiction' => null,
            'resolved_market' => null,
        ]);
    });

    // The UAE is withdrawn *after* the signal was found. The watch may no longer search it; the
    // signal it already produced is still a signal that watch found there.
    config()->set('signals.regions.ae.offered', false);

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme Ltd',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent);
});

it('restricts a lead whose article named two places, the stricter one winning', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    // The extraction resolved the article to the UAE end, which is what the resolver now does with
    // an ambiguous place. This asserts the end-to-end consequence: the lead carries the
    // restriction, on a watch whose own market imposes none.
    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()
            ->covering(['London', 'Dubai'], [SearchRegion::Gb, SearchRegion::Ae])
            ->create(['team_id' => $team->getKey(), 'market' => Market::Uk]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'location' => 'UK founder relocates to Dubai',
            'jurisdiction' => 'AE',
            'resolved_market' => Market::Uae->value,
        ]);
    });

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme Ltd',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent)
        ->and($lead->raw['jurisdiction'])->toBe('AE');
});

/*
 * **What the convert dialog says is what the save applies.**
 *
 * The dialog used to state the UAE PDPL restriction unconditionally. That was true while Signals
 * was UAE-only (ADR 0011) and became a false statement of law the moment S8 made the geography
 * plural — a UK founder shown a consent requirement that does not apply to them, on the one screen
 * where a member decides how to approach somebody. The fix was to send the resolved restriction
 * rather than let a screen infer it from `resolved_market`, which is one step of three.
 *
 * These assert the *agreement* rather than either answer on its own, because agreement is the
 * property that keeps them from drifting apart again.
 */

it('tells the convert dialog there is no restriction on a UK signal', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $signal = app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => SignalWatch::factory()
            ->covering(['London'], [SearchRegion::Gb])
            ->create(['team_id' => $team->getKey(), 'market' => Market::Uk])
            ->getKey(),
        'location' => 'London',
        'jurisdiction' => 'GB',
        'resolved_market' => Market::Uk->value,
    ]));

    $payload = app(TeamContext::class)->run(
        $team,
        fn (): array => SignalResource::make($signal->fresh())->resolve(request()),
    );

    // Null, so the panel does not render at all — rather than rendering with softer words.
    expect($payload['outreach_restriction'])->toBeNull();

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme Ltd',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBeNull();
});

it('tells the convert dialog the restriction a UAE signal carries, in the words the lead will use', function (): void {
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $signal = app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => SignalWatch::factory()
            ->covering(['London'], [SearchRegion::Gb])
            ->create(['team_id' => $team->getKey(), 'market' => Market::Uk])
            ->getKey(),
        'location' => 'Dubai',
        'jurisdiction' => 'AE',
        'resolved_market' => Market::Uae->value,
    ]));

    $payload = app(TeamContext::class)->run(
        $team,
        fn (): array => SignalResource::make($signal->fresh())->resolve(request()),
    );

    expect($payload['outreach_restriction'])->not->toBeNull()
        ->and($payload['outreach_restriction']['value'])
        ->toBe(OutreachRestriction::UaePriorConsent->value)
        ->and($payload['outreach_restriction']['allows_direct_marketing'])->toBeFalse()
        // Translation keys, not finished sentences — the dialog runs them through `t()`.
        ->and($payload['outreach_restriction']['label'])
        ->toBe(OutreachRestriction::UaePriorConsent->label())
        ->and($payload['outreach_restriction']['guidance'])
        ->toBe(OutreachRestriction::UaePriorConsent->guidance());

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme DMCC',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent);
});

it('does not let the dialog read the jurisdiction alone, where that would be the wrong answer', function (): void {
    // The case a screen computing this from `resolved_market` would get wrong: the article named
    // nowhere, so step one has no answer and the watch's regions supply one. A dialog that showed
    // nothing here would be telling a member they may cold-email somebody they may not — the
    // dangerous direction, and the whole reason this is resolved server-side.
    Queue::fake();
    Event::fake([SignalConverted::class]);

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $signal = app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => SignalWatch::factory()
            ->covering(['London', 'Dubai'], [SearchRegion::Gb, SearchRegion::Ae])
            ->create(['team_id' => $team->getKey(), 'market' => Market::Uk])
            ->getKey(),
        'location' => 'remote',
        'jurisdiction' => null,
        'resolved_market' => null,
    ]));

    $payload = app(TeamContext::class)->run(
        $team,
        fn (): array => SignalResource::make($signal->fresh())->resolve(request()),
    );

    expect($payload['resolved_market'])->toBeNull()
        ->and($payload['outreach_restriction']['value'])
        ->toBe(OutreachRestriction::UaePriorConsent->value);

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Acme Ltd',
        personName: 'Jane Founder',
    ));

    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent);
});

it('carries the article across as the lead’s evidence (PL1)', function (): void {
    Queue::fake();

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $signal = signalFor($team, [
        'headline' => 'Zephyr Logistics raises a Series A',
        'summary' => 'The Manchester freight firm closed a round led by an institutional investor.',
        'source_name' => 'Example Wire',
        'source_url' => 'https://example.test/news/zephyr-series-a',
        'published_at' => Carbon::parse('2026-10-12'),
    ]);

    $lead = convertSignal($team, $signal, $actor, new ConvertSignalData(
        companyName: 'Zephyr Logistics Ltd',
        personName: 'Jane Founder',
    ));

    $evidence = app(TeamContext::class)->run(
        $team,
        fn () => $lead->evidence()->get(),
    );

    // Exactly one reason: the article. Not a generic "converted from a signal" row beside it,
    // which would be the same fact with a worse label and a weaker weight.
    expect($evidence)->toHaveCount(1);

    $article = $evidence->first();

    expect($article->kind)->toBe(EvidenceKind::Signal)
        // The signal's own source, not the string `signal`: a news story and a Companies House
        // filing are weighted as the different things they are.
        ->and($article->source)->toBe($signal->source_kind->value)
        ->and($article->source_label)->toBe('Example Wire')
        ->and($article->title)->toContain('Series A')
        ->and($article->source_url)->toBe('https://example.test/news/zephyr-series-a')
        // The **publication** date, not the run that found it: that is what the decay measures,
        // and it is why a week-old story outranks a six-month-old one on the same company.
        ->and($article->observed_at->toDateString())->toBe('2026-10-12')
        ->and($article->signal_id)->toBe((int) $signal->getKey())
        // The signal's own prose stays on the signal, which has a retention window. An evidence
        // payload carries ids and keys only.
        ->and($article->payload)->toHaveKeys(['signal_id', 'event_key'])
        ->and(json_encode($article->payload))->not->toContain('Manchester');

    // And the lead can now say how urgent it is, which a company-level lead never could.
    expect($lead->fresh()->timing_score)->not->toBeNull()
        ->and($lead->fresh()->confidence)->not->toBeNull();
});

it('writes the lead’s evidence in the same transaction as its timeline entry', function (): void {
    Queue::fake();

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $signal = signalFor($team);

    $lead = convertSignal($team, $signal, $actor);

    // A lead whose history claims an event its evidence panel cannot show would be a lead that
    // explains itself two different ways, so the two writes share a transaction.
    $entries = LeadActivity::query()
        ->where('lead_id', $lead->getKey())
        ->where('type', LeadActivityType::SignalConverted)
        ->count();

    expect($entries)->toBe(1)
        ->and(app(TeamContext::class)->run($team, fn (): int => $lead->evidence()->count()))->toBe(1);
});
