<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalInboundEvent;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Services\SignalsPersonalDataExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function personSignal(Team $team, string $name, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, function () use ($team, $name, $attributes): Signal {
        $watch = SignalWatch::query()->where('team_id', $team->getKey())->first()
            ?? SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'subject_person_name' => $name,
            ...$attributes,
        ]);
    });
}

function exporter(): SignalsPersonalDataExporter
{
    return app(SignalsPersonalDataExporter::class);
}

it('reports everything Signals holds about a named person', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $signal = personSignal($team, 'Jane Founder');
    personSignal($team, 'Someone Else');

    $rows = exporter()->export($team, 'Jane Founder');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['signal_id'])->toBe($signal->id)
        ->and($rows[0]['subject_person_name'])->toBe('Jane Founder')
        ->and($rows[0]['subject_person_role'])->toBe('Founder & CEO')
        // The public article the record came from is the lawful basis for holding it, and the
        // first thing a subject will ask about.
        ->and($rows[0]['source_url'])->toBe($signal->source_url)
        ->and($rows[0]['erasable'])->toBeTrue()
        // There is no contact detail to report, because the module never stores one (ADR 0011).
        ->and($rows[0])->not->toHaveKey('email')
        ->and($rows[0])->not->toHaveKey('phone');
});

it('matches a name regardless of case and surrounding text', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    personSignal($team, 'Dr Jane Founder OBE');

    expect(exporter()->export($team, 'jane founder'))->toHaveCount(1);
});

it('never reaches into another team’s signals', function (): void {
    $other = createUserWithTeam();
    personSignal($other->currentTeam()->firstOrFail(), 'Jane Founder');

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    personSignal($team, 'Jane Founder');

    // A data-subject request is answered per controller; one team's records are not another's to
    // search.
    expect(exporter()->export($team, 'Jane Founder'))->toHaveCount(1)
        ->and(exporter()->erase($team, 'Jane Founder')['erased'])->toBe(1)
        ->and(Signal::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses a name too short to mean anything', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    personSignal($team, 'Jane Founder');

    // A one- or two-letter query would match half the feed and erase it.
    expect(exporter()->export($team, 'J'))->toBe([])
        ->and(exporter()->erase($team, 'J'))->toBe([
            'erased' => 0,
            'retained' => 0,
            'retained_signal_ids' => [],
            // S16's two surfaces are reported beside the signals, and a refused name erases from
            // none of the three.
            'watchlist_entries_erased' => 0,
            'inbound_events_erased' => 0,
        ])
        ->and(Signal::withoutGlobalScopes()->count())->toBe(1);
});

it('searches for a wildcard rather than with one', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    personSignal($team, 'Jane Founder');

    // `%` must match a literal percent, not everything.
    expect(exporter()->export($team, '%ane%'))->toBe([]);
});

it('erases the erasable and retains a converted signal, saying which', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'dedupe_key' => 'no:dsar-1',
    ]));

    $erasable = personSignal($team, 'Jane Founder');
    $converted = personSignal($team, 'Jane Founder', [
        'status' => SignalStatus::Converted,
        'lead_id' => $lead->getKey(),
    ]);

    $result = exporter()->erase($team, 'Jane Founder');

    // Tearing the origin out from under a lead someone is working would leave the team unable to
    // say where it came from — the opposite of the accountability the request is exercising. So it
    // is reported, not quietly kept.
    expect($result['erased'])->toBe(1)
        ->and($result['retained'])->toBe(1)
        ->and($result['retained_signal_ids'])->toBe([(int) $converted->getKey()])
        ->and(Signal::withoutGlobalScopes()->whereKey($erasable->getKey())->exists())->toBeFalse()
        ->and(Signal::withoutGlobalScopes()->whereKey($converted->getKey())->exists())->toBeTrue();
});

it('marks a converted signal as not erasable in the export', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'dedupe_key' => 'no:dsar-2',
    ]));

    personSignal($team, 'Jane Founder', [
        'status' => SignalStatus::Converted,
        'lead_id' => $lead->getKey(),
    ]);

    $rows = exporter()->export($team, 'Jane Founder');

    expect($rows[0]['erasable'])->toBeFalse()
        ->and($rows[0]['converted_to_lead_id'])->toBe($lead->id);
});

it('reports nothing for a person it holds nothing about', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    personSignal($team, 'Jane Founder');

    expect(exporter()->export($team, 'Nobody Here'))->toBe([])
        ->and(exporter()->erase($team, 'Nobody Here')['erased'])->toBe(0);
});

// --- S16: the two surfaces the feature grew after S6, and the clusters an erasure breaks --------

/** A named individual on one of the team's watchlists (S10). */
function watchedPerson(Team $team, string $name, ?array $aliases = null): SignalWatchlistEntry
{
    return app(TeamContext::class)->run($team, function () use ($team, $name, $aliases): SignalWatchlistEntry {
        $list = SignalWatchlist::query()->where('team_id', $team->getKey())->first()
            ?? SignalWatchlist::factory()->create(['team_id' => $team->getKey(), 'name' => 'People I watch']);

        return SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Person,
            'name' => $name,
            'aliases' => $aliases,
        ]);
    });
}

it('finds a person on a watchlist, and reports it as a different kind of record', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    watchedPerson($team, 'Jane Founder');

    $rows = exporter()->export($team, 'Jane Founder');

    // A signal is something we **found**; this is something we are **looking for**, which is the
    // difference a subject would care about most — erasing the signals and leaving this would mean
    // the next run went and found them again.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['record'])->toBe('watchlist_entry')
        ->and($rows[0]['subject_person_name'])->toBe('Jane Founder')
        ->and($rows[0]['watchlist_name'])->toBe('People I watch')
        ->and($rows[0]['erasable'])->toBeTrue();
});

it('finds a person held only under an alias', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    watchedPerson($team, 'J. Founder', ['Jane Founder']);

    // A subject whose record we hold only under the name this team gave them is exactly the case a
    // subject access request exists to surface.
    expect(exporter()->export($team, 'Jane Founder'))->toHaveCount(1);
});

it('leaves a company entry alone on a person’s request', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // "J. Okafor Consulting" is a business whose name happens to contain a person's; erasing it on
    // a name match would take a team's watch off a company for a request that was not about it.
    app(TeamContext::class)->run($team, function () use ($team): void {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey(), 'name' => 'Accounts']);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Company,
            'name' => 'J. Okafor Consulting Ltd',
        ]);
    });

    expect(exporter()->export($team, 'Okafor'))->toBe([])
        ->and(exporter()->erase($team, 'Okafor')['watchlist_entries_erased'])->toBe(0)
        ->and(SignalWatchlistEntry::withoutGlobalScopes()->count())->toBe(1);
});

it('erases a watched person and reports it separately from the signals', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $entry = watchedPerson($team, 'Jane Founder');
    personSignal($team, 'Jane Founder');

    $result = exporter()->erase($team, 'Jane Founder');

    expect($result['erased'])->toBe(1)
        ->and($result['watchlist_entries_erased'])->toBe(1)
        ->and(SignalWatchlistEntry::withoutGlobalScopes()->whereKey($entry->getKey())->exists())->toBeFalse();
});

it('finds and erases a person named in a pushed event body', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $definition = signalDefinition($team);

    $event = app(TeamContext::class)->run($team, fn (): SignalInboundEvent => SignalInboundEvent::factory()
        ->create([
            'team_id' => $team->getKey(),
            'signal_definition_id' => $definition->getKey(),
            'title' => 'A leadership change at Acme',
            'summary' => 'Our CRM recorded a new CFO.',
            // The name sits only in a nested key, which is the case the payload clause exists for:
            // Alima did not choose what is in this body, which is why the whole of it is searched.
            'payload' => ['event_type' => 'custom_event', 'person' => ['full_name' => 'Jane Founder']],
        ]));

    $rows = exporter()->export($team, 'Jane Founder');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['record'])->toBe('inbound_event')
        // Reported whole: it is the customer's own assertion about this person, and unlike a signal
        // there is no published article to point at instead.
        ->and($rows[0]['pushed_payload'])->toBe(['event_type' => 'custom_event', 'person' => ['full_name' => 'Jane Founder']]);

    expect(exporter()->erase($team, 'Jane Founder')['inbound_events_erased'])->toBe(1)
        ->and(SignalInboundEvent::withoutGlobalScopes()->whereKey($event->getKey())->exists())->toBeFalse();
});

it('gives a cluster a new primary when the erasure takes the one it was showing', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    [$cluster, $survivor] = app(TeamContext::class)->run($team, function () use ($team): array {
        $cluster = SignalCluster::factory()->create(['team_id' => $team->getKey()]);

        $named = personSignal($team, 'Jane Founder', ['signal_cluster_id' => $cluster->getKey()]);
        $other = personSignal($team, 'Someone Else', ['signal_cluster_id' => $cluster->getKey()]);

        $cluster->forceFill(['primary_signal_id' => $named->getKey(), 'sources_count' => 2])->save();

        return [$cluster, $other];
    });

    exporter()->erase($team, 'Jane Founder');

    // Left alone the feed would show every remaining member as a separate card until the nightly
    // sweep ran, and a DSAR is answered to a statutory deadline rather than overnight.
    expect($cluster->refresh()->primary_signal_id)->toBe((int) $survivor->getKey())
        ->and($cluster->sources_count)->toBe(1);
});

it('deletes a cluster the erasure emptied', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $cluster = app(TeamContext::class)->run($team, function () use ($team): SignalCluster {
        $cluster = SignalCluster::factory()->create(['team_id' => $team->getKey()]);

        $only = personSignal($team, 'Jane Founder', ['signal_cluster_id' => $cluster->getKey()]);
        $cluster->forceFill(['primary_signal_id' => $only->getKey(), 'sources_count' => 1])->save();

        return $cluster;
    });

    exporter()->erase($team, 'Jane Founder');

    // The row would otherwise keep its `subject_fingerprint` with nothing left to explain it, which
    // is the one part of a cluster derived from the rows that are gone.
    expect(SignalCluster::withoutGlobalScopes()->whereKey($cluster->getKey())->exists())->toBeFalse();
});

it('keeps one team’s records out of another team’s request', function (): void {
    $userA = createUserWithTeam();
    $teamA = $userA->currentTeam()->firstOrFail();
    $userB = createUserWithTeam();
    $teamB = $userB->currentTeam()->firstOrFail();

    $theirs = watchedPerson($teamB, 'Jane Founder');

    // A DSAR is answered per controller; one team's records are not another's to search or erase.
    expect(exporter()->export($teamA, 'Jane Founder'))->toBe([])
        ->and(exporter()->erase($teamA, 'Jane Founder')['watchlist_entries_erased'])->toBe(0)
        ->and(SignalWatchlistEntry::withoutGlobalScopes()->whereKey($theirs->getKey())->exists())->toBeTrue();
});

it('keeps the watchlist\'s cached count honest after an erasure', function (): void {
    // `signal_watchlists.entries_count` is a cached figure every other delete path maintains, and
    // this one did not (`/code-review`): the Accounts screen would show "2 / 100" over one row for
    // ever on a manual list, and `SignalWatchController` filters the lists it offers by
    // `entries_count > 0`.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = app(TeamContext::class)->run($team, function () use ($team): SignalWatchlist {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey(), 'name' => 'People']);

        foreach (['Jane Founder', 'Someone Else'] as $name) {
            SignalWatchlistEntry::factory()->create([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $list->getKey(),
                'entity_type' => WatchlistEntityType::Person,
                'name' => $name,
            ]);
        }

        $list->forceFill(['entries_count' => 2])->save();

        return $list;
    });

    exporter()->erase($team, 'Jane Founder');

    expect($list->refresh()->entries_count)->toBe(1);
});
