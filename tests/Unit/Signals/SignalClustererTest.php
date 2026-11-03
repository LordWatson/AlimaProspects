<?php

declare(strict_types=1);

use App\Modules\Signals\Data\NewsItem;
use App\Modules\Signals\Services\SignalClusterer;
use App\Modules\Signals\Services\SignalItemNormaliser;
use App\Modules\Signals\Testing\FakeNewsSearchClient;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/*
 * The grouping rule and the cluster key, with no database in sight (S11).
 *
 * `uses(Tests\TestCase::class)` because both halves read configuration — the similarity threshold
 * and the redirector list — which is where those numbers are meant to be tunable from.
 */
uses(TestCase::class);

beforeEach(function (): void {
    $this->news = new FakeNewsSearchClient;
    $this->clusterer = new SignalClusterer(new SignalItemNormaliser);
});

/**
 * The canonical fixture: one Dubai acquisition, as eight outlets wrote it.
 *
 * @return list<NewsItem>
 */
function zephyrCoverage(FakeNewsSearchClient $news): array
{
    $headlines = [
        ['Regional Holdings acquires Dubai logistics firm Zephyr', 'Gulf News'],
        ['Regional Holdings acquires Dubai logistics firm Zephyr in cash deal', 'Khaleej Times'],
        ['Regional Holdings acquires Dubai logistics firm Zephyr for undisclosed sum', 'Zawya'],
        ['Regional Holdings acquires Dubai logistics group Zephyr', 'Arabian Business'],
        ['Regional Holdings acquires Zephyr, the Dubai logistics firm', 'The National'],
        ['Dubai logistics firm Zephyr acquired by Regional Holdings', 'Gulf Business'],
        ['Regional Holdings seals acquisition of Dubai logistics firm Zephyr', 'MEED'],
        ['Zephyr acquired: Regional Holdings adds Dubai logistics firm', 'Logistics Middle East'],
    ];

    $items = [];

    foreach ($headlines as $index => [$title, $source]) {
        $items[] = $news->item(
            title: $title,
            link: 'https://example.test/zephyr-'.$index,
            snippet: 'Regional Holdings has agreed to buy Zephyr.',
            source: $source,
        );
    }

    return $items;
}

it('groups one event covered by eight outlets into a single story', function (): void {
    $groups = $this->clusterer->group(zephyrCoverage($this->news));

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toHaveCount(8);
});

it('sends the fullest article as the representative', function (): void {
    $groups = $this->clusterer->group(zephyrCoverage($this->news));

    // The longest title + snippet, because the classifier reads text and the fullest version of a
    // story is the one most likely to name the amount and the counterparty.
    expect($groups[0][0]->title)
        ->toBe('Regional Holdings acquires Dubai logistics firm Zephyr for undisclosed sum');
});

it('keeps two different acquisitions in the same week apart', function (): void {
    $groups = $this->clusterer->group([
        $this->news->item('Regional Holdings acquires Dubai logistics firm Zephyr', 'https://example.test/a'),
        $this->news->item('Emaar acquires Dubai hospitality firm Jumeirah Group', 'https://example.test/b'),
    ]);

    // Three words in common ("acquires", "dubai", "firm") is the shape of a false merge, and a
    // false merge *before* the AI means one event is written down as the other.
    expect($groups)->toHaveCount(2);
});

it('never groups a headline with too few significant words', function (): void {
    $groups = $this->clusterer->group([
        $this->news->item('Deal', 'https://example.test/a'),
        $this->news->item('Deal', 'https://example.test/b'),
    ]);

    // Identical, and still two groups: there is not enough in "Deal" to be sure of anything, and
    // the floor is what says so.
    expect($groups)->toHaveCount(2);
});

it('groups nothing when cluster_before_ai is off', function (): void {
    config()->set('signals.cluster_before_ai', false);

    $groups = $this->clusterer->group(zephyrCoverage($this->news));

    // The way back from a regression: every article is its own story and the run behaves as v1 did.
    expect($groups)->toHaveCount(8);
});

it('is deterministic: the same items in the same order give the same groups', function (): void {
    $first = $this->clusterer->group(zephyrCoverage($this->news));
    $second = $this->clusterer->group(zephyrCoverage($this->news));

    expect(array_map(static fn (array $group): int => count($group), $first))
        ->toBe(array_map(static fn (array $group): int => count($group), $second))
        ->and($first[0][0]->dedupeKey())->toBe($second[0][0]->dedupeKey());
});

// --- The subject fingerprint ---------------------------------------------------------------------

it('uses the same company-name rule as the lead matcher', function (): void {
    // `CompanyNameKey::bare()` - legal forms set aside, and nothing else. The one rule, reused.
    expect($this->clusterer->subjectFingerprint('Zephyr Logistics LLC'))->toBe('zephyr-logistics')
        ->and($this->clusterer->subjectFingerprint('Zephyr Logistics'))->toBe('zephyr-logistics');
});

it('refuses to cluster an event that names no company', function (): void {
    expect($this->clusterer->subjectFingerprint(null))->toBe('')
        ->and($this->clusterer->clusterKeyFor('relocation', '', CarbonImmutable::parse('2026-11-03')))
        ->toBeNull();
});

// --- The cluster key ----------------------------------------------------------------------------

it('gives one key per event type, subject and week', function (): void {
    $monday = CarbonImmutable::parse('2026-11-02 09:00:00');
    $wednesday = CarbonImmutable::parse('2026-11-04 09:00:00');

    $a = $this->clusterer->clusterKeyFor('acquisition', 'zephyr-logistics', $monday);
    $b = $this->clusterer->clusterKeyFor('acquisition', 'zephyr-logistics', $wednesday);

    expect($a)->toBe($b);
});

it('separates a funding round from an acquisition for the same company', function (): void {
    $day = CarbonImmutable::parse('2026-11-03');

    expect($this->clusterer->clusterKeyFor('acquisition', 'acme', $day))
        ->not->toBe($this->clusterer->clusterKeyFor('funding_round', 'acme', $day));
});

it('offers the previous week as a candidate, so a weekend story is one event', function (): void {
    // Sunday 1 November 2026 and Monday 2 November fall in different ISO weeks. A story that breaks
    // on the Sunday and is covered on the Monday would be two cards if the bucket were read
    // literally - and that is the event most likely to be well covered.
    $sunday = CarbonImmutable::parse('2026-11-01 22:00:00');
    $monday = CarbonImmutable::parse('2026-11-02 07:00:00');

    $sundayKey = $this->clusterer->clusterKeyFor('acquisition', 'zephyr-logistics', $sunday);
    $mondayCandidates = $this->clusterer->candidateKeysFor('acquisition', 'zephyr-logistics', $monday);

    expect($sundayKey)->not->toBe($mondayCandidates[0])
        ->and($mondayCandidates)->toContain($sundayKey);
});

it('buckets by UTC, so two teams reading the same article agree', function (): void {
    // One instant, two zones. `DateRange::constrain()` exists in Reporting for the same reason.
    $dubai = CarbonImmutable::parse('2026-11-02 01:00:00', 'Asia/Dubai');
    $london = $dubai->setTimezone('Europe/London');

    expect($this->clusterer->clusterKeyFor('acquisition', 'acme', $dubai))
        ->toBe($this->clusterer->clusterKeyFor('acquisition', 'acme', $london));
});
