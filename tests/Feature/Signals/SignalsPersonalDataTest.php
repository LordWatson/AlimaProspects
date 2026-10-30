<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
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
        ->and(exporter()->erase($team, 'J'))->toBe(['erased' => 0, 'retained' => 0, 'retained_signal_ids' => []])
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
