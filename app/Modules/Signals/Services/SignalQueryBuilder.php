<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a {@see SignalWatch} (plus the team's active ICP, if any) into the set of Serper `/news`
 * queries a run executes. Deterministic — no AI. The watch's `location` overrides the ICP geography;
 * its `event_types` select the config templates (loosened plain-keyword templates, S0 spike); and an
 * ICP Buying-signal criterion is **appended as an extra query** only when it maps to a watched event
 * type — otherwise it is ignored and the reason logged (IDs/counts only, never names).
 */
final class SignalQueryBuilder
{
    private const int MAX_QUERY = 200;

    /**
     * Keyword fragments (lower-case, substring match) that map an ICP buying signal to an event type.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        SignalEventType::Acquisition->value => ['acqui', 'sold', 'exit', 'merger', 'm&a', 'buyout', 'takeover', 'stake sale'],
        SignalEventType::FundingRound->value => ['fund', 'rais', 'series ', 'seed', 'investment', 'venture'],
        SignalEventType::Relocation->value => ['relocat', 'moving', 'moves to', 'move to', 'family office', 'headquarter', 'opens office'],
    ];

    /**
     * @return list<SignalQuery>
     */
    public function build(SignalWatch $watch, ?Icp $icp = null): array
    {
        $market = $watch->market->value;
        $location = $this->resolveLocation($watch, $market);
        $gl = (string) config("signals.markets.{$market}.gl", 'ae');
        $hl = (string) config('signals.hl', 'en');
        $tbs = (string) config('signals.recency', 'qdr:w');
        $num = max(1, min((int) config('signals.results_per_query', 20), 100));

        /** @var array<string, mixed> $templatesByType */
        $templatesByType = (array) config("signals.templates.{$market}", []);

        /** @var array<string, SignalQuery> $queries keyed by eventType|q to dedupe */
        $queries = [];

        $watchedTypes = $this->watchedEventTypes($watch);

        foreach ($watchedTypes as $eventType) {
            /** @var list<string> $templates */
            $templates = (array) ($templatesByType[$eventType->value] ?? []);

            foreach ($templates as $template) {
                $q = $this->compose((string) $template, $location);
                $this->push($queries, $eventType, $q, $gl, $hl, $tbs, $num);
            }
        }

        if ($icp instanceof Icp) {
            $this->appendBuyingSignalQueries($watch, $icp, $watchedTypes, $location, $gl, $hl, $tbs, $num, $queries);
        }

        return array_values($queries);
    }

    /**
     * The watch's location, falling back to the market default when it is blank.
     */
    private function resolveLocation(SignalWatch $watch, string $market): string
    {
        $location = trim($watch->location);

        return $location !== '' ? $location : (string) config("signals.markets.{$market}.default_location", '');
    }

    /**
     * The watch's event types as enum cases, ignoring any stored value that is no longer a case.
     *
     * @return list<SignalEventType>
     */
    private function watchedEventTypes(SignalWatch $watch): array
    {
        $types = [];
        foreach ($watch->event_types as $value) {
            $type = SignalEventType::tryFrom((string) $value);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Append an extra query per ICP buying signal that maps to a watched event type.
     *
     * @param  list<SignalEventType>  $watchedTypes
     * @param  array<string, SignalQuery>  $queries
     */
    private function appendBuyingSignalQueries(
        SignalWatch $watch,
        Icp $icp,
        array $watchedTypes,
        string $location,
        string $gl,
        string $hl,
        string $tbs,
        int $num,
        array &$queries,
    ): void {
        $watchedValues = array_map(static fn (SignalEventType $t): string => $t->value, $watchedTypes);

        foreach ($this->buyingSignals($icp) as $signal) {
            $eventType = $this->matchEventType($signal);

            if ($eventType === null) {
                Log::channel('signals')->debug('ICP buying signal ignored for query building: no event keyword matched.', [
                    'signal_watch_id' => $watch->getKey(),
                ]);

                continue;
            }

            if (! in_array($eventType->value, $watchedValues, strict: true)) {
                Log::channel('signals')->debug('ICP buying signal maps to an event type this watch does not watch; ignored.', [
                    'signal_watch_id' => $watch->getKey(),
                    'event_type' => $eventType->value,
                ]);

                continue;
            }

            $q = $this->compose(trim($location.' '.$signal), $location);
            $this->push($queries, $eventType, $q, $gl, $hl, $tbs, $num);
        }
    }

    /**
     * The non-empty Buying-signal criterion values of an ICP.
     *
     * @return list<string>
     */
    private function buyingSignals(Icp $icp): array
    {
        $icp->loadMissing('criteria');

        return array_values($icp->criteria
            ->filter(static fn (IcpCriterion $c): bool => $c->type === CriterionType::BuyingSignal)
            ->map(static fn (IcpCriterion $c): string => trim($c->value))
            ->filter(static fn (string $v): bool => $v !== '')
            ->all());
    }

    /**
     * Map a buying-signal phrase to an event type by keyword, or null when none matches.
     */
    private function matchEventType(string $text): ?SignalEventType
    {
        $haystack = Str::lower($text);

        foreach (self::KEYWORDS as $eventType => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($haystack, $keyword)) {
                    return SignalEventType::from($eventType);
                }
            }
        }

        return null;
    }

    /**
     * Substitute `{location}` and tidy whitespace, clamped to the query length.
     */
    private function compose(string $template, string $location): string
    {
        $q = str_replace('{location}', $location, $template);
        $q = trim((string) preg_replace('/\s+/', ' ', $q));

        return Str::limit($q, self::MAX_QUERY, '');
    }

    /**
     * Add a query, de-duplicating identical (event type + text) pairs.
     *
     * @param  array<string, SignalQuery>  $queries
     */
    private function push(array &$queries, SignalEventType $eventType, string $q, string $gl, string $hl, string $tbs, int $num): void
    {
        if ($q === '') {
            return;
        }

        $key = $eventType->value.'|'.$q;

        if (isset($queries[$key])) {
            return;
        }

        $queries[$key] = new SignalQuery(
            eventType: $eventType,
            q: $q,
            gl: $gl,
            hl: $hl,
            tbs: $tbs,
            num: $num,
        );
    }
}
