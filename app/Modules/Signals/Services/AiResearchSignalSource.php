<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\AiPlatform\Contracts\AiResearcher;
use App\Modules\AiPlatform\Data\AiWebSearch;
use App\Modules\AiPlatform\Exceptions\AiTransportException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Support\SellerBrief;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Claude, searching the web, as a signal source (Signals v3 P4, ADR 0039).
 *
 * The other sources take a query we composed and return what a search index has. This one is
 * given the *question* — this kind of event, in these places, for this seller — and lets the
 * model write its own searches. That is what makes it useful where a keyword template returns
 * nothing, and it is also what makes it the most expensive source by two orders of magnitude.
 *
 * Four rules, each of which is the design:
 *
 * - **Grounded, with no recall path** (ADR 0035's rule, applied here). The model returns events
 *   with the URL it found each on; an event whose URL is not among the pages the search tool
 *   actually returned *in this call* is dropped in code, and the link stored is the tool's own.
 *   A model that "knows" a company raised money last spring cannot put it in the feed.
 * - **It asserts nothing.** What comes back is a {@see SourceItem} like any other source's, with
 *   `classification: null`: the run classifies it against the team's types, judges the company,
 *   applies the floors, de-duplicates, clusters and resolves the jurisdiction itself. Only code
 *   we wrote against an authoritative registry may skip that (ADR 0023 §6), and a language model
 *   is not one.
 * - **It is an enhancement, so it degrades and never fails a run.** A timeout, a refusal, an
 *   exhausted allowance or an unusable answer returns nothing and is logged. Throwing would fail
 *   the watch run and make the job retry — and a retry re-bills the Serper searches the run had
 *   already paid for. This is `SignalArticleEnricher`'s argument, and the one deliberate
 *   departure from {@see SignalSourceProvider::fetch()}'s "must throw on failure".
 * - **Bounded three ways:** searches per call (`max_searches`), calls per run (the run's own
 *   gate, which also only calls this where the other sources came back thin), and money per
 *   month (`SignalSpendGuard::remainingAiResearchMicros()`, counted over `ai_requests`).
 *
 * It is definition-addressed (ADR 0023's third shape): one call per signal type, never per
 * template or per place, because multiplying the dearest source by the query plan would be the
 * cost bug that ADR exists to prevent.
 */
final class AiResearchSignalSource implements SignalSourceProvider
{
    public const string PROMPT_KEY = 'signals.research_events';

    private const string BREAKER_KEY = 'signals_ai_research';

    private const int MAX_SNIPPET = 400;

    public function __construct(
        /** @var Closure(): AiResearcher */
        private readonly Closure $researcher,
        private readonly TeamContext $teamContext,
        private readonly SignalSpendGuard $guard,
        private readonly CircuitBreaker $breaker,
        private readonly SignalItemNormaliser $normaliser = new SignalItemNormaliser,
    ) {}

    public function kind(): SignalSourceKind
    {
        return SignalSourceKind::AiResearch;
    }

    public function supports(SignalDefinition $definition): bool
    {
        return (bool) config('signals.sources.ai_research.enabled', false) && trim((string) $definition->name) !== '';
    }

    /**
     * @return list<SourceItem>
     */
    public function fetch(SignalSourceQuery $query): array
    {
        $team = $this->teamContext->team();

        // A preview is a cheap sample of a type's templates; this is neither cheap nor a template.
        if ($query->preview || ! $team instanceof Team || ! (bool) config('signals.sources.ai_research.enabled', false)) {
            return [];
        }

        $definition = SignalDefinition::query()
            ->where('team_id', $team->getKey())
            ->whereKey((int) ($query->config['definition_id'] ?? 0))
            ->first();

        // From here a "no" is a query that was **not attempted**, and it is said as a skip rather
        // than as an empty answer: an empty answer is booked as a search that ran, with its cost,
        // and a search nobody made must not appear on a run's bill (review).
        if (! $definition instanceof SignalDefinition || ! $this->breaker->allows(self::BREAKER_KEY)) {
            throw SignalsException::sourceAddressNotFound($this->kind()->value);
        }

        $remaining = $this->guard->remainingAiResearchMicros($team);

        if ($remaining !== null && $remaining < $this->kind()->costMicrosPerRequest()) {
            // The month's allowance is spent. The team keeps every other source.
            throw SignalsException::sourceAddressNotFound($this->kind()->value);
        }

        try {
            $response = ($this->researcher)()->research(self::PROMPT_KEY, [
                'places' => implode(', ', array_slice(array_map(strval(...), (array) ($query->config['places'] ?? [])), 0, 5)) ?: $query->region->label(),
                'country' => $query->region->label(),
                'today' => Carbon::now()->toDateString(),
                'days' => (string) max(1, (int) config('signals.sources.ai_research.window_days', 14)),
                // The type is the team's own words, and the seller description is partly read
                // off its website: data, never instructions.
                'event' => UntrustedContent::from($this->eventBlock($definition)),
                'business_profile' => UntrustedContent::from(
                    SellerBrief::for(BusinessProfile::current()) ?? 'No business profile on record.',
                ),
                'max' => (string) $this->maxItems(),
            ], new AiWebSearch(
                maxSearches: max(1, (int) config('signals.sources.ai_research.max_searches', 3)),
                country: $this->searchCountry($query->gl),
            ));
        } catch (Throwable $e) {
            // Only a transport failure says anything about the provider's health. A team out of
            // AI credit, an over-long prompt or an answer that would not validate is about that
            // team or that call, and must not open a breaker every other team shares (review).
            if ($e instanceof AiTransportException) {
                $this->breaker->recordFailure(self::BREAKER_KEY);
            }

            // IDs and the exception class; never the prompt, the answer or a page.
            Log::channel('signals')->warning('AI research returned nothing usable; the run carries on without it.', [
                'team_id' => $team->getKey(),
                'signal_definition_id' => $definition->getKey(),
                'exception' => $e::class,
            ]);

            return [];
        }

        $this->breaker->recordSuccess(self::BREAKER_KEY);

        $items = $this->grounded((array) $response->get('events', []), $response->sources);

        Log::channel('signals')->info('AI research finished.', [
            'team_id' => $team->getKey(),
            'signal_definition_id' => $definition->getKey(),
            'searches' => $response->webSearches,
            'pages_returned' => count($response->sources),
            'events_offered' => count((array) $response->get('events', [])),
            'events_grounded' => count($items),
            'cost_micros' => $response->costMicros,
        ]);

        return $items;
    }

    public function estimatedCostMicros(int $queries): int
    {
        return max(0, $queries) * $this->kind()->costMicrosPerRequest();
    }

    /**
     * The events whose link is a page the search tool returned in this call, as source items.
     *
     * The match is on the canonical URL's hash, the same identity the rest of the pipeline uses,
     * so a trailing slash or a tracking parameter the model dropped does not lose a real page —
     * and nothing else gets one in.
     *
     * @param  array<array-key, mixed>  $events
     * @param  list<array{url: string, title: ?string, page_age: ?string}>  $sources
     * @return list<SourceItem>
     */
    private function grounded(array $events, array $sources): array
    {
        $returned = [];

        foreach ($sources as $source) {
            $hash = $this->normaliser->urlHash($source['url']);

            if ($hash !== null) {
                $returned[$hash] ??= $source;
            }
        }

        $items = [];

        foreach (array_slice($events, 0, $this->maxItems()) as $event) {
            if (! is_array($event) || ! is_string($event['source_url'] ?? null)) {
                continue;
            }

            $hash = $this->normaliser->urlHash($event['source_url']);
            $page = $hash === null ? null : ($returned[$hash] ?? null);

            // Not a page the tool returned: the model's own memory, or an invented link.
            if ($hash === null || $page === null || isset($items[$hash])) {
                continue;
            }

            $title = Str::limit(Str::squish((string) ($event['headline'] ?? $page['title'] ?? '')), 255, '');
            $snippet = Str::limit(Str::squish((string) ($event['summary'] ?? '')), self::MAX_SNIPPET, '');
            $outlet = is_string($event['outlet'] ?? null) && trim($event['outlet']) !== ''
                ? Str::limit(trim($event['outlet']), 120, '')
                : (parse_url($page['url'], PHP_URL_HOST) ?: null);

            if ($title === '') {
                continue;
            }

            $items[$hash] = new SourceItem(
                title: $title,
                snippet: $snippet,
                // The tool's own URL, never the model's rendering of it.
                link: $page['url'],
                urlHash: $hash,
                source: is_string($outlet) ? $outlet : null,
                publishedAt: $this->date($event['published'] ?? null),
                raw: ['title' => $title, 'link' => $page['url'], 'source' => $outlet],
                contentHash: $this->normaliser->contentHash($title, is_string($outlet) ? $outlet : null),
                dedupeHash: $this->normaliser->dedupeHash($page['url'], $title, is_string($outlet) ? $outlet : null),
                kind: $this->kind(),
            );
        }

        return array_values($items);
    }

    private function eventBlock(SignalDefinition $definition): string
    {
        /** @var array{includes?: list<string>, excludes?: list<string>} $rules */
        $rules = (array) $definition->classification_rules;

        return implode("\n", array_filter([
            'Name: '.Str::limit((string) $definition->name, 120, ''),
            'What it is: '.Str::limit((string) $definition->description, 255, ''),
            ($rules['includes'] ?? []) === [] ? null : 'Counts: '.Str::limit(implode('; ', $rules['includes']), 600, ''),
            ($rules['excludes'] ?? []) === [] ? null : 'Does not count: '.Str::limit(implode('; ', $rules['excludes']), 600, ''),
        ]));
    }

    /**
     * A date the model read off a page, if it is one and it is not in the future.
     */
    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date instanceof Carbon && ! $date->isFuture() ? $date : null;
    }

    /**
     * The country to localise the provider's search to, or null to leave it unset.
     *
     * The provider refuses a request outright for a country it does not localise for — `AE` is
     * one, found on the first live run — so only the ones known to be accepted are sent. The
     * prompt names the country and the places either way, which is what steers the searches.
     */
    private function searchCountry(?string $gl): ?string
    {
        $country = $gl === null ? null : Str::upper($gl);

        return in_array($country, (array) config('signals.sources.ai_research.location_countries', ['GB']), true) ? $country : null;
    }

    private function maxItems(): int
    {
        return max(1, min(10, (int) config('signals.sources.ai_research.max_items', 8)));
    }
}
