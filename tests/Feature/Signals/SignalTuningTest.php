<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\ApplySignalTuningSuggestionAction;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalTuningKind;
use App\Modules\Signals\Enums\SignalTuningStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Models\SignalTuningSuggestion;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalSourceLedger;
use App\Modules\Signals\Services\SignalTuningAdvisor;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Billing;

/*
 * Learning from what the team does, and **proposing** rather than applying (S12).
 *
 * The single most important assertion in this file is the one that says nothing changed: a feature
 * that silently retunes itself is a feature nobody can debug. Everything else is about the evidence
 * being good enough to be worth a member's attention, and about asking once.
 */

uses(RefreshDatabase::class);

function tuningAdvisor(): SignalTuningAdvisor
{
    return app(SignalTuningAdvisor::class);
}

function sourceLedger(): SignalSourceLedger
{
    return app(SignalSourceLedger::class);
}

/** A signal from a named outlet, saved, inside the team's context. */
function signalFrom(Team $team, string $source, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'source_name' => $source,
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
    ], $attributes)));
}

// --- The ledger ---------------------------------------------------------------------------------

it('counts a keep, a dismissal and a conversion against the outlet', function (): void {
    $team = Team::factory()->create();
    $signal = signalFrom($team, 'Gulf Business');

    app(TeamContext::class)->run($team, function () use ($signal): void {
        sourceLedger()->recordKept([$signal]);
        sourceLedger()->recordDismissed($signal, SignalDismissReason::NotRelevant);
        sourceLedger()->recordConverted($signal);
    });

    $stat = SignalSourceStat::query()->withoutGlobalScopes()->firstOrFail();

    expect($stat->source_key)->toBe('gulf-business')
        ->and($stat->kept_count)->toBe(1)
        ->and($stat->dismissed_count)->toBe(1)
        ->and($stat->converted_count)->toBe(1)
        // Only the reason that says the classifier was wrong moves this one.
        ->and($stat->not_an_event_count)->toBe(0);
});

it('counts "not an event" separately, because it blames the classifier', function (): void {
    $team = Team::factory()->create();
    $signal = signalFrom($team, 'Rumour Mill');

    app(TeamContext::class)->run($team, fn () => sourceLedger()->recordDismissed($signal, SignalDismissReason::NotAnEvent));

    $stat = SignalSourceStat::query()->withoutGlobalScopes()->firstOrFail();

    // A subset of the dismissals, not a third outcome: "not an event" says the extraction was
    // wrong, where "not relevant" says the team was not interested. Different levers.
    expect($stat->dismissed_count)->toBe(1)->and($stat->not_an_event_count)->toBe(1);
});

it('takes a dismissal back on a restore', function (): void {
    $team = Team::factory()->create();
    $signal = signalFrom($team, 'Gulf Business');

    app(TeamContext::class)->run($team, function () use ($signal): void {
        sourceLedger()->recordDismissed($signal, SignalDismissReason::NotAnEvent);
        sourceLedger()->recordRestored($signal, SignalDismissReason::NotAnEvent);
    });

    $stat = SignalSourceStat::query()->withoutGlobalScopes()->firstOrFail();

    // A mis-click that taught the product an outlet was worthless, corrected on screen a second
    // later, must not leave the lesson behind — the advisor would propose excluding a source on
    // dismissals the team had already taken back.
    expect($stat->dismissed_count)->toBe(0)->and($stat->not_an_event_count)->toBe(0);
});

it('never counts below zero', function (): void {
    $team = Team::factory()->create();
    $signal = signalFrom($team, 'Gulf Business');

    // A restore with no dismissal behind it — two tabs, or a replayed event.
    app(TeamContext::class)->run($team, fn () => sourceLedger()->recordRestored($signal, null));

    $stat = SignalSourceStat::query()->withoutGlobalScopes()->first();

    // A negative counter would make a rate greater than one and an authority above a hundred.
    expect($stat?->dismissed_count ?? 0)->toBe(0);
});

it('judges an outlet per event type', function (): void {
    $team = Team::factory()->create();

    $funding = signalFrom($team, 'Gulf Business');
    $relocation = signalFrom($team, 'Gulf Business', ['event_key' => SignalPresetCatalogue::RELOCATION]);

    app(TeamContext::class)->run($team, function () use ($funding, $relocation): void {
        sourceLedger()->recordKept([$funding]);
        sourceLedger()->recordDismissed($relocation, SignalDismissReason::NotRelevant);
    });

    // Two rows, because an outlet is not good or bad in general.
    expect(SignalSourceStat::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('records nothing when the provider named no outlet', function (): void {
    $team = Team::factory()->create();
    $signal = signalFrom($team, '', ['source_name' => null]);

    app(TeamContext::class)->run($team, fn () => sourceLedger()->recordKept([$signal]));

    expect(SignalSourceStat::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('records an outcome when a member dismisses through the screen', function (): void {
    [$user, $team] = Billing::team();
    $signal = signalFrom($team, 'Rumour Mill');

    $this->actingAs($user)
        ->post('/signals/'.$signal->getKey().'/dismiss', ['reason' => 'not_an_event'])
        ->assertRedirect();

    // End to end: the Action fires the event, the listener writes the tally. Nothing about the
    // member's own click waited for it.
    $stat = SignalSourceStat::query()->withoutGlobalScopes()->firstOrFail();

    expect($stat->dismissed_count)->toBe(1)->and($stat->not_an_event_count)->toBe(1);
});

// --- The advisor proposes ----------------------------------------------------------------------

it('proposes ignoring an outlet the team dismisses nearly everything from', function (): void {
    config()->set('signals.tuning.min_observations', 8);
    config()->set('signals.tuning.min_rate', 0.7);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, fn () => SignalSourceStat::factory()->distrusted()->create([
        'team_id' => $team->getKey(),
        'source_key' => 'rumour-mill',
        'source_name' => 'Rumour Mill',
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
    ]));

    $open = app(TeamContext::class)->run($team, fn (): int => tuningAdvisor()->advise($team));

    $suggestion = SignalTuningSuggestion::query()->withoutGlobalScopes()->firstOrFail();

    expect($open)->toBe(1)
        ->and($suggestion->kind)->toBe(SignalTuningKind::IgnoreSource)
        ->and($suggestion->status)->toBe(SignalTuningStatus::Proposed)
        ->and($suggestion->payload['source_name'])->toBe('Rumour Mill')
        // The evidence travels with it: a suggestion a member cannot check is one they can only
        // obey or ignore.
        ->and($suggestion->evidence['dismissed'])->toBe(11)
        ->and($suggestion->evidence['observations'])->toBe(12);
});

it('never proposes silencing an outlet that has produced a lead', function (): void {
    config()->set('signals.tuning.min_observations', 8);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, fn () => SignalSourceStat::factory()->distrusted()->create([
        'team_id' => $team->getKey(),
        'source_key' => 'rumour-mill',
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
        // One conversion among the noise.
        'converted_count' => 1,
    ]));

    app(TeamContext::class)->run($team, fn () => tuningAdvisor()->advise($team));

    // One good lead pays for a lot of dismissing, and a product that offered to silence the source
    // that found it would be wrong in a way the member would remember.
    expect(SignalTuningSuggestion::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('wants evidence before it has an opinion', function (): void {
    config()->set('signals.tuning.min_observations', 8);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, fn () => SignalSourceStat::factory()->create([
        'team_id' => $team->getKey(),
        'source_key' => 'rumour-mill',
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
        'kept_count' => 0,
        'dismissed_count' => 3,
    ]));

    app(TeamContext::class)->run($team, fn () => tuningAdvisor()->advise($team));

    // Three dismissals is not a pattern. The threshold is what keeps this a suggestion rather
    // than a nag.
    expect(SignalTuningSuggestion::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('proposes raising confidence when the dismissals blame the classifier', function (): void {
    config()->set('signals.tuning.min_observations', 8);
    config()->set('signals.tuning.min_rate', 0.7);

    $team = Team::factory()->create();

    $definition = app(TeamContext::class)->run($team, function () use ($team): SignalDefinition {
        $definition = SignalDefinition::factory()->create([
            'team_id' => $team->getKey(),
            'key' => SignalPresetCatalogue::FUNDING_ROUND,
            'thresholds' => ['min_confidence' => 60, 'min_relevance' => 40],
        ]);

        // `kept_count` is how many reached the feed and the rest are **subsets** of it, so a
        // fixture with more dismissals than keeps is a state production cannot produce — which is
        // how the first version of this suite hid the double-count that made the advisor dead code.
        SignalSourceStat::factory()->forDefinition($definition)->create([
            'source_key' => 'rumour-mill',
            'kept_count' => 15,
            'dismissed_count' => 12,
            'not_an_event_count' => 12,
        ]);

        return $definition;
    });

    app(TeamContext::class)->run($team, fn () => tuningAdvisor()->advise($team));

    $suggestion = SignalTuningSuggestion::query()->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RaiseConfidence->value)
        ->firstOrFail();

    // "Not an event" says the classifier is too loose, and **confidence** is the lever for that.
    expect($suggestion->signal_definition_id)->toBe($definition->getKey())
        ->and($suggestion->payload['threshold'])->toBe('min_confidence')
        ->and($suggestion->payload['from'])->toBe(60)
        // A nudge, not a target: a floor raised to 95 on one bad week stops the type finding
        // anything and the team cannot tell whether the tuning or the market went quiet.
        ->and($suggestion->payload['to'])->toBe(70);
});

it('proposes raising relevance when the events are real and not wanted', function (): void {
    config()->set('signals.tuning.min_observations', 8);
    config()->set('signals.tuning.min_rate', 0.7);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $definition = SignalDefinition::factory()->create([
            'team_id' => $team->getKey(),
            'key' => SignalPresetCatalogue::FUNDING_ROUND,
            'thresholds' => ['min_confidence' => 60, 'min_relevance' => 40],
        ]);

        SignalSourceStat::factory()->forDefinition($definition)->create([
            'source_key' => 'gulf-business',
            'kept_count' => 15,
            'dismissed_count' => 12,
            // Real events, every one of them. The team simply does not want them.
            'not_an_event_count' => 0,
        ]);
    });

    app(TeamContext::class)->run($team, fn () => tuningAdvisor()->advise($team));

    $suggestion = SignalTuningSuggestion::query()->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::RaiseRelevance->value)
        ->firstOrFail();

    // The two reasons point at different levers, and a product that offered one knob for both
    // would have teams tightening the wrong screw for a month.
    expect($suggestion->payload['threshold'])->toBe('min_relevance')
        // And it proposes the **source** change too, because the same evidence supports both:
        // twelve dismissals from one outlet is a reason to raise the floor *and* a reason to offer
        // to stop showing it. Two proposals, each with its own evidence, each declinable on its
        // own — which is the point of them being rows rather than one "we have tuned this" action.
        ->and(SignalTuningSuggestion::query()->withoutGlobalScopes()
            ->where('kind', SignalTuningKind::IgnoreSource->value)
            ->exists())->toBeTrue();
});

it('is idempotent: running twice refreshes rather than stacks', function (): void {
    config()->set('signals.tuning.min_observations', 8);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, fn () => SignalSourceStat::factory()->distrusted()->create([
        'team_id' => $team->getKey(),
        'source_key' => 'rumour-mill',
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
    ]));

    app(TeamContext::class)->run($team, function () use ($team): void {
        tuningAdvisor()->advise($team);
        tuningAdvisor()->advise($team);
    });

    expect(SignalTuningSuggestion::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('never raises a suggestion the team has declined', function (): void {
    config()->set('signals.tuning.min_observations', 8);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, fn () => SignalSourceStat::factory()->distrusted()->create([
        'team_id' => $team->getKey(),
        'source_key' => 'rumour-mill',
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
    ]));

    app(TeamContext::class)->run($team, fn () => tuningAdvisor()->advise($team));

    SignalTuningSuggestion::query()->withoutGlobalScopes()
        ->update(['status' => SignalTuningStatus::Declined->value]);

    app(TeamContext::class)->run($team, fn () => tuningAdvisor()->advise($team));

    // The whole reason a declined row is kept rather than deleted. Asking every week is the
    // difference between a suggestion and a nag.
    expect(SignalTuningSuggestion::query()->withoutGlobalScopes()
        ->where('status', SignalTuningStatus::Proposed->value)
        ->count())->toBe(0);
});

it('never reads one team’s dismissals to advise another', function (): void {
    config()->set('signals.tuning.min_observations', 8);

    $noisy = Team::factory()->create();
    $quiet = Team::factory()->create();

    app(TeamContext::class)->run($noisy, fn () => SignalSourceStat::factory()->distrusted()->create([
        'team_id' => $noisy->getKey(),
        'source_key' => 'rumour-mill',
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
    ]));

    app(TeamContext::class)->run($quiet, fn () => tuningAdvisor()->advise($quiet));

    // A proposal built from another tenant's dismissals would be the worst kind of wrong:
    // plausible, specific, and about somebody else.
    expect(SignalTuningSuggestion::query()->withoutGlobalScopes()->count())->toBe(0);
});

// --- Accepting and declining -------------------------------------------------------------------

it('applies an accepted ignore-source to every watch, audited', function (): void {
    [$user, $team] = Billing::team();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
        'ignore_domains' => ['bayut.com'],
    ]));

    $suggestion = app(TeamContext::class)->run($team, fn (): SignalTuningSuggestion => SignalTuningSuggestion::factory()->create([
        'team_id' => $team->getKey(),
        'payload' => ['source_key' => 'rumour-mill', 'source_name' => 'Rumour Mill'],
    ]));

    $this->actingAs($user)
        ->post('/signals/tuning/'.$suggestion->getKey().'/accept')
        ->assertRedirect();

    expect($watch->refresh()->ignore_domains)->toContain('rumour mill')
        // The entry the team already had is untouched: accepting adds, it does not replace.
        ->and($watch->ignore_domains)->toContain('bayut.com')
        ->and($suggestion->refresh()->status)->toBe(SignalTuningStatus::Accepted)
        ->and($suggestion->actioned_by)->toBe($user->getKey());

    // Audited as the ordinary watch edit it is — the same entry `SaveSignalWatchAction` writes.
    $this->assertDatabaseHas('audit_logs', ['action' => 'signals.watch_updated']);
});

it('applies an accepted threshold nudge to the definition, keeping the other floor', function (): void {
    [$user, $team] = Billing::team();

    $definition = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'thresholds' => ['min_confidence' => 60, 'min_relevance' => 45],
        'version' => 3,
    ]));

    $suggestion = app(TeamContext::class)->run($team, fn (): SignalTuningSuggestion => SignalTuningSuggestion::factory()
        ->raiseConfidence($definition, from: 60, to: 70)
        ->create());

    $this->actingAs($user)
        ->post('/signals/tuning/'.$suggestion->getKey().'/accept')
        ->assertRedirect();

    $definition->refresh();

    expect($definition->thresholds['min_confidence'])->toBe(70)
        // A suggestion about confidence must not reset relevance to the global default, which is
        // what writing the column wholesale from the payload would have done.
        ->and($definition->thresholds['min_relevance'])->toBe(45)
        // Bumped like any other save, so a later phase can still say which version classified
        // which signal.
        ->and($definition->version)->toBe(4);

    $this->assertDatabaseHas('audit_logs', ['action' => 'signals.definition_updated']);
});

it('never lowers a floor a member has since raised by hand', function (): void {
    [$user, $team] = Billing::team();

    $definition = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        // The member went further than the suggestion did, after it was written.
        'thresholds' => ['min_confidence' => 85, 'min_relevance' => 45],
        'version' => 3,
    ]));

    $suggestion = app(TeamContext::class)->run($team, fn (): SignalTuningSuggestion => SignalTuningSuggestion::factory()
        ->raiseConfidence($definition, from: 60, to: 70)
        ->create());

    $this->actingAs($user)
        ->post('/signals/tuning/'.$suggestion->getKey().'/accept')
        ->assertRedirect();

    $definition->refresh();

    // Accepting a suggestion called "raise this floor" must never **lower** one. The first version
    // wrote the payload's value unconditionally, so a member who had tightened the type by hand
    // would have had it loosened by agreeing with the product — with an audit entry reading like an
    // improvement.
    expect($definition->thresholds['min_confidence'])->toBe(85)
        ->and($definition->version)->toBe(3);

    // Reported as stale rather than silently ignored, so the member is sent back to the advisor
    // instead of believing a change was made.
    expect($suggestion->refresh()->status)->toBe(SignalTuningStatus::Proposed);

    $this->assertDatabaseMissing('audit_logs', ['action' => 'signals.definition_updated']);
});

it('changes nothing at all when the team declines', function (): void {
    [$user, $team] = Billing::team();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
        'ignore_domains' => null,
    ]));

    $suggestion = app(TeamContext::class)->run($team, fn (): SignalTuningSuggestion => SignalTuningSuggestion::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $this->actingAs($user)
        ->post('/signals/tuning/'.$suggestion->getKey().'/decline')
        ->assertRedirect();

    // **The most important assertion in this file.** Nothing retunes itself.
    expect($watch->refresh()->ignore_domains)->toBeNull()
        ->and($suggestion->refresh()->status)->toBe(SignalTuningStatus::Declined);
});

it('refuses to apply the same suggestion twice', function (): void {
    [$user, $team] = Billing::team();

    $definition = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'thresholds' => ['min_confidence' => 60],
    ]));

    $suggestion = app(TeamContext::class)->run($team, fn (): SignalTuningSuggestion => SignalTuningSuggestion::factory()
        ->raiseConfidence($definition)
        ->create());

    app(TeamContext::class)->run($team, function () use ($suggestion, $user): void {
        app(ApplySignalTuningSuggestionAction::class)->accept($suggestion, $user);
    });

    // Applying a ten-point nudge twice is a twenty-point change nobody asked for.
    app(TeamContext::class)->run($team, function () use ($suggestion, $user): void {
        expect(fn () => app(ApplySignalTuningSuggestionAction::class)->accept($suggestion->refresh(), $user))
            ->toThrow(SignalsException::class);
    });
});

it('refuses a suggestion whose type has since been deleted', function (): void {
    [$user, $team] = Billing::team();

    $definition = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'thresholds' => ['min_confidence' => 60],
    ]));

    $suggestion = app(TeamContext::class)->run($team, fn (): SignalTuningSuggestion => SignalTuningSuggestion::factory()
        ->raiseConfidence($definition)
        ->create());

    $definition->delete();

    // The honest answer is to refuse and let the advisor propose again from what is true now,
    // rather than apply a change built on evidence about a thing that no longer exists.
    app(TeamContext::class)->run($team, function () use ($suggestion, $user): void {
        expect(fn () => app(ApplySignalTuningSuggestionAction::class)->accept($suggestion->fresh(), $user))
            ->toThrow(SignalsException::class);
    });
});

it('hides another team’s suggestion behind a 404', function (): void {
    [$user] = Billing::team();
    $other = Team::factory()->create();

    $suggestion = app(TeamContext::class)->run($other, fn (): SignalTuningSuggestion => SignalTuningSuggestion::factory()->create([
        'team_id' => $other->getKey(),
    ]));

    // Read inside the team's context, so a cross-tenant request is a 404 **before** any policy
    // runs — the request cannot even learn the row exists.
    $this->actingAs($user)
        ->post('/signals/tuning/'.$suggestion->getKey().'/accept')
        ->assertNotFound();
});

it('shows the suggestions on the watch settings screen', function (): void {
    [$user, $team] = Billing::team();

    app(TeamContext::class)->run($team, fn () => SignalTuningSuggestion::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $this->actingAs($user)
        ->get('/signals/watches')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('suggestions', 1)
            ->where('suggestions.0.kind', 'ignore_source')
            // The evidence comes down with the proposal, because that is the component.
            ->has('suggestions.0.evidence'));
});

it('fires from real behaviour, with no seeded statistics at all', function (): void {
    // **The test the first version of this suite was missing**, and its absence hid a defect that
    // made the whole advisor dead code: every fixture seeded `signal_source_stats` by hand, in a
    // shape production could not reach, so nothing ever exercised the arithmetic over counters the
    // ledger had actually written. This one dismisses real signals through the real endpoint and
    // then asks the advisor, with the configured defaults.
    config()->set('signals.tuning.min_observations', 8);
    config()->set('signals.tuning.min_rate', 0.7);

    [$user, $team] = Billing::team();

    $signals = [];

    foreach (range(1, 10) as $index) {
        $signals[] = signalFrom($team, 'Rumour Mill', [
            'source_url' => 'https://rumourmill.test/story-'.$index,
            'url_hash' => sha1('rumour-'.$index),
            'dedupe_hash' => sha1('rumour-'.$index),
        ]);
    }

    // The run credited the source for each one it stored.
    app(TeamContext::class)->run($team, fn () => sourceLedger()->recordKept($signals));

    // The member dismisses nine of the ten, through the screen.
    foreach (array_slice($signals, 0, 9) as $signal) {
        $this->actingAs($user)
            ->post('/signals/'.$signal->getKey().'/dismiss', ['reason' => 'not_an_event'])
            ->assertRedirect();
    }

    $stat = SignalSourceStat::query()->withoutGlobalScopes()->firstOrFail();

    expect($stat->kept_count)->toBe(10)
        ->and($stat->dismissed_count)->toBe(9)
        // Nine of ten is 0.9, comfortably over the 0.7 threshold — which the double-counted
        // version could never have reached, because its denominator was 19.
        ->and($stat->dismissalRate())->toBeGreaterThan(0.7)
        ->and($stat->observations())->toBe(10);

    app(TeamContext::class)->run($team, fn () => tuningAdvisor()->advise($team));

    expect(SignalTuningSuggestion::query()->withoutGlobalScopes()
        ->where('kind', SignalTuningKind::IgnoreSource->value)
        ->exists())->toBeTrue();
});

it('rates an outlet the team dismisses at nought, not at half', function (): void {
    config()->set('signals.scoring.source_history_minimum', 5);

    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, fn () => SignalSourceStat::factory()->create([
        'team_id' => $team->getKey(),
        'source_key' => 'rumour-mill',
        'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
        // Every single one dismissed.
        'kept_count' => 10,
        'dismissed_count' => 10,
        'converted_count' => 0,
    ]));

    $stat = SignalSourceStat::query()->withoutGlobalScopes()->firstOrFail();

    // The double-counted version scored this 50 — "about average" for a source the team has
    // rejected without exception.
    expect($stat->authority())->toBe(0);
});
