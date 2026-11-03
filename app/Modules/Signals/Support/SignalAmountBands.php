<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Services\SignalLeadMatcher;
use App\Modules\Signals\Services\SignalScorer;

/**
 * Where a deal size sits against **this team's own** history (S12).
 *
 * A £2m exit is the biggest thing a boutique has seen all year and a rounding error to a growth
 * fund, so the amount component of {@see SignalScorer} ranks rather than measures: the answer is a
 * percentile against the amounts this team's watches have already found, not a figure against a
 * constant somebody chose.
 *
 * Two deliberate properties:
 *
 * - **It abstains rather than guesses.** Below `signals.scoring.amount_history_minimum` recorded
 *   amounts there is no distribution to rank against, so {@see self::percentileFor()} returns null
 *   and the scorer drops the component's weight from both sides of its average. Four data points
 *   are not a history.
 * - **Currencies are not converted.** The distribution is built per currency, because ranking
 *   40,000,000 AED against 2,000,000 GBP would rank the exchange rate. A currency with too little
 *   history abstains like any other, which is the right answer for a team's first deal in a new
 *   market.
 *
 * Read **once per team per scoring pass** and held, like {@see SignalLeadMatcher}'s
 * lead index: fifty signals scored against one query each would be fifty passes over the same
 * table for an answer that cannot change mid-run.
 */
final class SignalAmountBands
{
    /**
     * Sorted amount lists, by team and currency.
     *
     * @var array<int, array<string, list<int>>>
     */
    private array $byTeam = [];

    /**
     * One signal's own amount, so it can be taken out of its own distribution.
     *
     * @var array<int, int|null>
     */
    private array $amountById = [];

    /**
     * This amount's percentile rank in the team's history, 0–100, or null when there is not enough
     * history in that currency to say.
     *
     * The rank is "what share of the amounts this team has seen are at or below this one", so the
     * largest deal a team has ever had scores 100 and the median scores 50. An amount the team has
     * seen before lands on the upper edge of its own band, which is the generous reading and the
     * right one: a deal matching the team's biggest is their biggest.
     */
    public function percentileFor(int $teamId, ?int $amountMinor, ?string $currency, ?int $excluding = null): ?int
    {
        if ($amountMinor === null || $amountMinor <= 0 || $currency === null || $currency === '') {
            return null;
        }

        $history = $this->historyFor($teamId, $currency, $excluding);
        $minimum = max(2, (int) config('signals.scoring.amount_history_minimum', 5));

        if (count($history) < $minimum) {
            return null;
        }

        $atOrBelow = 0;

        foreach ($history as $seen) {
            if ($seen <= $amountMinor) {
                $atOrBelow++;

                continue;
            }

            // The list is sorted, so the first amount above this one ends the count.
            break;
        }

        return (int) round($atOrBelow / count($history) * 100);
    }

    /**
     * How many amounts this team has recorded in a currency — the evidence line beside the band.
     */
    public function historyCount(int $teamId, ?string $currency, ?int $excluding = null): int
    {
        return $currency === null || $currency === ''
            ? 0
            : count($this->historyFor($teamId, $currency, $excluding));
    }

    /**
     * Every amount this team has recorded in one currency, ascending.
     *
     * Scoped twice — the global scope and an explicit `team_id` — because the scorer runs from a
     * queued run with no request behind it, and a distribution built from another tenant's deals
     * would rank this team's signals against numbers they have never seen.
     *
     * @return list<int>
     */
    private function historyFor(int $teamId, string $currency, ?int $excluding = null): array
    {
        if (! isset($this->byTeam[$teamId][$currency])) {
            /** @var list<int> $amounts */
            $amounts = Signal::query()
                ->where('team_id', $teamId)
                ->where('currency', $currency)
                // **Events, not articles** (S11). Eight outlets covering one $40m round would
                // otherwise put that amount into the distribution eight times, and a well-syndicated
                // deal would inflate its own percentile (found by `laravel-module-reviewer`).
                ->representatives()
                ->whereNotNull('amount_minor')
                ->where('amount_minor', '>', 0)
                ->orderBy('amount_minor')
                ->pluck('amount_minor')
                ->map(static fn (mixed $amount): int => (int) $amount)
                ->all();

            $this->byTeam[$teamId][$currency] = $amounts;
        }

        $history = $this->byTeam[$teamId][$currency];

        if ($excluding === null) {
            return $history;
        }

        // The signal being scored is not part of its own history. Left in, a team's first large
        // deal ranks at 100 against a distribution it is the top of by construction — true but
        // circular, and it makes every new record-breaking deal look maximally significant on the
        // strength of its own existence.
        $self = $this->amountOf($teamId, $excluding);

        if ($self === null) {
            return $history;
        }

        $index = array_search($self, $history, strict: true);

        if ($index === false) {
            return $history;
        }

        unset($history[$index]);

        return array_values($history);
    }

    /**
     * One signal's stored amount, read once and cached — so excluding it from its own distribution
     * costs one query per scored signal at worst and none at all for an unsaved one.
     */
    private function amountOf(int $teamId, int $signalId): ?int
    {
        if (array_key_exists($signalId, $this->amountById)) {
            return $this->amountById[$signalId];
        }

        $amount = Signal::query()
            ->where('team_id', $teamId)
            ->whereKey($signalId)
            ->value('amount_minor');

        return $this->amountById[$signalId] = is_numeric($amount) ? (int) $amount : null;
    }
}
