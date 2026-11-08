<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Data\LeadConfidenceResult;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Services\LeadConfidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
 * The confidence arithmetic (PL1), with no database behind it.
 *
 * Unit rather than feature on purpose: this is the one piece of the phase that is pure, it is the
 * number the whole list is ordered and filtered by, and the four ways it could be made to lie —
 * summing instead of maxing, counting rows as corroboration, scoring an unknown as zero, and
 * letting a company fact set the timing score — are all provable here in a line each.
 *
 * `uses(Tests\TestCase::class)` because the calculator reads `config/evidence.php` - which is
 * where every one of these numbers is meant to be tunable from - and because an Eloquent model
 * needs a booted application even when nothing is saved. No `RefreshDatabase`: nothing here
 * touches a table.
 */
uses(TestCase::class);

/**
 * An unsaved evidence row. `make()`-free on purpose: the calculator reads six attributes and a
 * cast, and a factory would drag a lead, a team and a database in for arithmetic that needs none.
 *
 * @param  array<string, mixed>  $attributes
 */
function evidence(array $attributes = []): LeadEvidence
{
    $row = new LeadEvidence;

    $row->forceFill([
        'id' => $attributes['id'] ?? random_int(1, PHP_INT_MAX),
        'kind' => $attributes['kind'] ?? EvidenceKind::RegisterRecord,
        'source' => $attributes['source'] ?? 'companies_house',
        'reliability' => $attributes['reliability'] ?? 90,
        // `array_key_exists`, not `??`: an explicit null means 'nobody stated a date', which is
        // the case the decay has its own rule for, and `?? now()` would silently test the opposite.
        'observed_at' => array_key_exists('observed_at', $attributes)
            ? $attributes['observed_at']
            : Carbon::now(),
        'captured_at' => $attributes['captured_at'] ?? Carbon::now(),
        'is_disputed' => $attributes['is_disputed'] ?? false,
    ]);

    return $row;
}

/**
 * @param  array<int, LeadEvidence>  $items
 */
function confidenceOf(array $items, ?Team $team = null): LeadConfidenceResult
{
    return app(LeadConfidence::class)->fromEvidence(new Collection($items), $team);
}

it('gives a lead with no evidence no confidence at all, and does not hide it', function (): void {
    $result = confidenceOf([]);

    // Null, not zero. "Not judged yet" and "judged and found worthless" are different states, and
    // suppressing the first would make a fresh discovery run look like it found nothing.
    expect($result->confidence)->toBeNull()
        ->and($result->timingScore)->toBeNull()
        ->and($result->suppressed)->toBeFalse();
});

it('takes the strongest item rather than adding them up', function (): void {
    $one = confidenceOf([evidence(['reliability' => 90])]);

    // Four web hits from the same source must not out-argue one registry filing.
    $many = confidenceOf([
        evidence(['reliability' => 40, 'source' => 'web_search']),
        evidence(['reliability' => 40, 'source' => 'web_search']),
        evidence(['reliability' => 40, 'source' => 'web_search']),
        evidence(['reliability' => 40, 'source' => 'web_search']),
    ]);

    expect($one->confidence)->toBe(90)
        ->and($many->confidence)->toBe(40);
});

it('does not lower a strong lead when a weak reason is added', function (): void {
    $strong = confidenceOf([evidence(['reliability' => 95])]);

    $both = confidenceOf([
        evidence(['reliability' => 95]),
        evidence(['reliability' => 10, 'source' => 'ai', 'kind' => EvidenceKind::AiInference]),
    ]);

    // An average would have punished the team for finding out more.
    expect($both->confidence)->toBeGreaterThanOrEqual($strong->confidence);
});

it('pays the corroboration bonus per distinct source, never per row', function (): void {
    config(['evidence.corroboration_bonus' => 10, 'evidence.corroboration_max' => 20]);

    $oneSourceTwice = confidenceOf([
        evidence(['reliability' => 50, 'source' => 'web_search']),
        evidence(['reliability' => 50, 'source' => 'web_search']),
    ]);

    $twoSources = confidenceOf([
        evidence(['reliability' => 50, 'source' => 'web_search']),
        evidence(['reliability' => 50, 'source' => 'news']),
    ]);

    $fourSources = confidenceOf([
        evidence(['reliability' => 50, 'source' => 'web_search']),
        evidence(['reliability' => 50, 'source' => 'news']),
        evidence(['reliability' => 50, 'source' => 'google_places']),
        evidence(['reliability' => 50, 'source' => 'feed']),
    ]);

    expect($oneSourceTwice->confidence)->toBe(50)
        ->and($twoSources->confidence)->toBe(60)
        // Capped: three extra sources would be +30 without the ceiling.
        ->and($fourSources->confidence)->toBe(70);
});

it('clamps to 100', function (): void {
    $result = confidenceOf([
        evidence(['reliability' => 95, 'source' => 'companies_house']),
        evidence(['reliability' => 95, 'source' => 'news']),
        evidence(['reliability' => 95, 'source' => 'feed']),
    ]);

    expect($result->confidence)->toBe(100);
});

it('thins a reason by its age, from when the event happened', function (): void {
    config(['evidence.recency_half_life_days' => [EvidenceKind::Signal->value => 30]]);

    $fresh = confidenceOf([evidence([
        'kind' => EvidenceKind::Signal,
        'source' => 'news',
        'reliability' => 80,
        'observed_at' => Carbon::now(),
    ])]);

    $oneHalfLife = confidenceOf([evidence([
        'kind' => EvidenceKind::Signal,
        'source' => 'news',
        'reliability' => 80,
        'observed_at' => Carbon::now()->subDays(30),
    ])]);

    expect($fresh->confidence)->toBe(80)
        ->and($oneHalfLife->confidence)->toBe(40);
});

it('never decays a reason to nothing', function (): void {
    config([
        'evidence.min_decay' => 0.1,
        'evidence.recency_half_life_days' => [EvidenceKind::Signal->value => 30],
    ]);

    // Ten years old. A register record from 2015 is still why we believe the company exists, so
    // the floor keeps it in the lead's own history rather than erasing it.
    $result = confidenceOf([evidence([
        'kind' => EvidenceKind::Signal,
        'source' => 'news',
        'reliability' => 80,
        'observed_at' => Carbon::now()->subYears(10),
    ])]);

    expect($result->confidence)->toBe(8);
});

it('treats an item dated in the future as today rather than as worth more than today', function (): void {
    $result = confidenceOf([evidence([
        'reliability' => 90,
        'observed_at' => Carbon::now()->addYear(),
    ])]);

    expect($result->confidence)->toBe(90);
});

it('scores timing from trigger events only', function (): void {
    $companyFactsOnly = confidenceOf([
        evidence(['kind' => EvidenceKind::Firmographic, 'reliability' => 95]),
        evidence(['kind' => EvidenceKind::RegisterRecord, 'reliability' => 95, 'source' => 'news']),
    ]);

    $withEvent = confidenceOf([
        evidence(['kind' => EvidenceKind::Firmographic, 'reliability' => 95]),
        evidence(['kind' => EvidenceKind::Signal, 'reliability' => 60, 'source' => 'news']),
    ]);

    // A company's sector is as true next quarter as it is today; counting it as timing would make
    // every enriched lead look urgent.
    expect($companyFactsOnly->timingScore)->toBeNull()
        ->and($withEvent->timingScore)->toBe(60)
        // And no corroboration bonus on timing: two outlets covering one round is not two reasons.
        ->and($withEvent->confidence)->toBe(100);
});

it('drops a disputed reason entirely, including from corroboration', function (): void {
    config(['evidence.corroboration_bonus' => 10]);

    $result = confidenceOf([
        evidence(['reliability' => 50, 'source' => 'web_search']),
        evidence(['reliability' => 95, 'source' => 'companies_house', 'is_disputed' => true]),
    ]);

    // Not 95, and not 60 either: a flagged fact must not still count as a second source, or
    // disputing it would have *raised* the lead's confidence by agreeing it exists.
    expect($result->confidence)->toBe(50)
        ->and($result->sourceCount)->toBe(1);
});

it('leaves a lead unjudged when every reason has been disputed', function (): void {
    $result = confidenceOf([evidence(['is_disputed' => true])]);

    expect($result->confidence)->toBeNull()
        ->and($result->suppressed)->toBeFalse();
});

it('suppresses against the team’s own threshold, falling back to the default', function (): void {
    config(['evidence.default_min_confidence' => 50]);

    $weak = [evidence(['reliability' => 40, 'source' => 'web_search'])];

    expect(confidenceOf($weak)->suppressed)->toBeTrue();

    $tolerant = new Team;
    $tolerant->forceFill(['min_lead_confidence' => 30]);

    expect(confidenceOf($weak, $tolerant)->suppressed)->toBeFalse();

    $strict = new Team;
    $strict->forceFill(['min_lead_confidence' => 90]);

    expect(confidenceOf([evidence(['reliability' => 80])], $strict)->suppressed)->toBeTrue();
});

it('clamps a team threshold into the range the settings screen offers', function (): void {
    config(['evidence.min_threshold' => 30, 'evidence.max_threshold' => 90]);

    $team = new Team;
    $team->forceFill(['min_lead_confidence' => 100]);

    // A hand-edited or stale value must not be able to hide a whole team's leads.
    expect(app(LeadConfidence::class)->thresholdFor($team))->toBe(90);

    $team->forceFill(['min_lead_confidence' => 1]);

    expect(app(LeadConfidence::class)->thresholdFor($team))->toBe(30);
});

it('bands a confidence in words, and says nothing about a null', function (): void {
    config(['evidence.bands.high' => 75, 'evidence.bands.medium' => 50]);

    $confidence = app(LeadConfidence::class);

    expect($confidence->band(null))->toBeNull()
        ->and($confidence->band(90))->toBe('high')
        ->and($confidence->band(75))->toBe('high')
        ->and($confidence->band(50))->toBe('medium')
        ->and($confidence->band(49))->toBe('low')
        ->and($confidence->band(0))->toBe('low');
});

it('hashes the evidence set by identity and dispute state, not by order', function (): void {
    $a = evidence(['id' => 1]);
    $b = evidence(['id' => 2]);

    $forward = app(LeadConfidence::class)->fromEvidence(new Collection([$a, $b]));
    $reversed = app(LeadConfidence::class)->fromEvidence(new Collection([$b, $a]));

    expect($forward->evidenceHash)->toBe($reversed->evidenceHash);

    $disputed = evidence(['id' => 2, 'is_disputed' => true]);
    $changed = app(LeadConfidence::class)->fromEvidence(new Collection([$a, $disputed]));

    // Disputing a fact has to invalidate the cached summary — the sentence was written from it.
    expect($changed->evidenceHash)->not->toBe($forward->evidenceHash);
});

it('uses the capture date when nobody stated when the event happened', function (): void {
    config(['evidence.recency_half_life_days' => [EvidenceKind::Signal->value => 30]]);

    $result = confidenceOf([evidence([
        'kind' => EvidenceKind::Signal,
        'source' => 'news',
        'reliability' => 80,
        'observed_at' => null,
        'captured_at' => Carbon::now()->subDays(30),
    ])]);

    // An undated item is not treated as fresh: we know when *we* found it, and that is the
    // honest floor on its age.
    expect($result->confidence)->toBe(40);
});
