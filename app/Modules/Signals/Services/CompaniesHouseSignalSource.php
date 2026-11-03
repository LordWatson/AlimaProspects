<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Services\CompaniesHouseProvider;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Support\CompaniesHouseFiling;
use DateTimeInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Companies House filings as signals (S13b, ADR 0023 §6) — the v1 backlog's headline item.
 *
 * **The cheapest and most certain source in the product.** The API is free, and a filing needs no AI
 * at all: the registry has already parsed the form, so `description_values` hands over the officer's
 * name and the date and `transaction_id` is a stable identity to dedupe on. Every item this source
 * returns therefore carries its own {@see ExtractedSignal} and skips the extraction batch — see
 * {@see CompaniesHouseFiling} for what the registry actually gives us, and
 * `RunSignalWatchAction::extract()` for the partition.
 *
 * **Entity-scoped only, and that is a spike finding rather than a simplification.** The plan asked
 * for filings polled "by SIC code + region for a topical watch". There is no cross-company filings
 * endpoint — `/filing-history` and `/search/filings` both 404 — so that cannot be built. What
 * advanced search *can* answer is new incorporations and dissolutions, and the spike measured 2,422
 * new London software companies in a single month for one SIC code: a list, not a trigger event
 * about a prospect. So this source runs on **watchlist** watches, where a filing is exactly the
 * event the product promises — "a director was appointed at a company you are working" — and a
 * topical watch gets no Companies House queries at all, because it has no entities. The table is in
 * `docs/modules/signals.md`.
 *
 * **It reuses Lead Sourcing's credential and its breaker, deliberately** (the plan: "do not open a
 * second integration"). The credential is the same key under
 * `lead-sourcing.providers.companies_house.*`, and the breaker is Lead Sourcing's own rather than a
 * `signals:cb` one — which is the opposite of what the Serper sources do, for a concrete reason:
 * Companies House rate-limits **per API key** (600 requests per five minutes), so discovery and
 * Signals genuinely share one quota, and a trip earned by one should shed load from the other. Two
 * breakers over one quota would let each spend the other's budget while reporting itself healthy.
 */
final class CompaniesHouseSignalSource extends AbstractHttpSignalSourceProvider
{
    public function __construct(
        HttpFactory $http,
        CircuitBreaker $breaker,
        private readonly SignalItemNormaliser $normaliser,
    ) {
        parent::__construct($http, $breaker);
    }

    public function kind(): SignalSourceKind
    {
        return SignalSourceKind::CompaniesHouse;
    }

    /**
     * **Lead Sourcing's breaker key, not a Signals one.** See the class docblock: one API key, one
     * quota, one breaker. `Admin\Enums\PlatformProvider::CompaniesHouse` already describes it, so an
     * open circuit is visible on the provider-health screen without a second row claiming to watch
     * the same thing.
     */
    protected function breakerKey(): string
    {
        return LeadSource::CompaniesHouse->value;
    }

    /**
     * Any definition can run on the registry; whether it *finds* anything depends on its filing map.
     *
     * A definition with no `companies_house.filings` configuration matches no filing, which is a
     * quiet no-op rather than an error — the same way a definition with no templates produces no
     * searches. `supports()` cannot decline on the grounds that the **watch** has no watchlist,
     * because it is handed a definition and not a watch; the builder's "no entities, no queries" is
     * what makes a topical watch contribute nothing.
     */
    public function supports(SignalDefinition $definition): bool
    {
        return true;
    }

    /**
     * Free. Companies House does not bill, which is why this is the one source whose configured
     * cost is zero and why `signal_runs.source_breakdown` will show it finding events for nothing.
     */
    public function estimatedCostMicros(int $queries): int
    {
        return 0;
    }

    public function fetch(SignalSourceQuery $query): array
    {
        $entity = $query->subject();

        // Nothing to ask. A person, an account not on the UK register, or one the resolver has not
        // reached yet — all three are ordinary states, not failures.
        if ($entity === null || ! $entity->hasCompanyNumber()) {
            return [];
        }

        $number = (string) $entity->companyNumber;
        $map = $this->filingMap($query);

        // A run that offered no Companies House definitions has nothing this source could file a
        // filing under, so it must not spend a request to find that out.
        if ($map === []) {
            return [];
        }

        $response = $this->request($number);

        $payload = $response->json();

        if (! is_array($payload)) {
            Log::channel('signals')->warning('Companies House returned a non-JSON body.', [
                'source' => $this->kind()->value,
                'status' => $response->status(),
            ]);

            throw SignalsException::sourceFailed($this->kind()->value);
        }

        $items = $payload['items'] ?? [];

        if (! is_array($items)) {
            Log::channel('signals')->warning('Companies House returned an unexpected payload shape.', [
                'source' => $this->kind()->value,
                'status' => $response->status(),
            ]);

            throw SignalsException::sourceFailed($this->kind()->value);
        }

        return $this->mapFilings($items, $entity->primaryName(), $number, $map);
    }

    /**
     * The guarded GET. Basic auth with the key as the username and an empty password, which is what
     * {@see CompaniesHouseProvider} already does — the same credential, read from the same config.
     */
    private function request(string $number): Response
    {
        $apiKey = $this->requireApiKey('lead-sourcing.providers.companies_house.api_key');
        $baseUrl = rtrim((string) config('lead-sourcing.providers.companies_house.base_url'), '/');
        $perPage = max(1, min((int) config('signals.sources.companies_house.filings_per_entity', 25), 100));

        return $this->guarded(fn (): Response => $this->http
            ->baseUrl($baseUrl)
            ->withBasicAuth($apiKey, '')
            ->timeout((int) config('lead-sourcing.providers.companies_house.timeout', 10))
            ->connectTimeout((int) config('lead-sourcing.providers.companies_house.connect_timeout', 5))
            ->retry(
                (int) config('lead-sourcing.providers.companies_house.retry_times', 2),
                (int) config('lead-sourcing.providers.companies_house.retry_sleep_ms', 250),
                $this->retryWhen(),
                throw: false,
            )
            ->acceptJson()
            // Newest first is the API's default for filing history, which is what a recency window
            // wants; `items_per_page` is the only bound worth sending.
            ->get('/company/'.rawurlencode($number).'/filing-history', [
                'items_per_page' => $perPage,
            ]));
    }

    /**
     * Map the registry's items to pre-classified source items.
     *
     * Three filters, in cost order: a filing nothing maps to is dropped before anything else is
     * computed, then one outside the recency window, then one with no readable identity.
     *
     * @param  array<array-key, mixed>  $items
     * @param  array<string, string>  $map
     * @return list<SourceItem>
     */
    private function mapFilings(array $items, string $companyName, string $number, array $map): array
    {
        $since = $this->recencyFloor();
        $link = CompaniesHouseFiling::recordUrl($number);
        $out = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $filing = CompaniesHouseFiling::fromItem($item);

            if ($filing === null) {
                continue;
            }

            $eventKey = $filing->eventKeyFrom($map);

            if ($eventKey === null) {
                continue;
            }

            // Outside the window. A filing history goes back decades, so without this the first run
            // of a watch would mint a signal for every appointment since 1990.
            if ($filing->filedOn === null || $filing->filedOn->lessThan($since)) {
                continue;
            }

            // `transaction_id` is the registry's own identity for the filing: stable, unique, and
            // immune to the redirect problem that made S11 invent a content hash. Hashed only so the
            // column stays a fixed-width sha1 like every other dedupe key.
            $dedupe = sha1('companies_house:'.$filing->transactionId);

            if (isset($seen[$dedupe])) {
                continue;
            }

            $seen[$dedupe] = true;
            $out[] = $this->toItem($filing, $eventKey, $companyName, $link, $dedupe);
        }

        return $out;
    }

    /**
     * One filing as a {@see SourceItem} that is **already classified**.
     *
     * The two scores are asserted rather than judged, and both are defensible for an entity-scoped
     * registry filing:
     *
     * - **confidence 100** — this is not an inference about an article, it is a form the company
     *   filed with the registrar. If it says a director was appointed, one was.
     * - **relevance 100** — relevance asks "does this fit your business", and for a watchlist event
     *   the member has already answered: they put this account on the list. That is a stronger
     *   statement of relevance than any model's reading of a news snippet.
     *
     * Both still pass through the definition's own floors in the run (ADR 0023 §6), so a team that
     * has raised a type's confidence floor is obeyed — these values simply clear it. Worth knowing:
     * a floor raised because the *news* for a type is noisy will not filter its filings, which is
     * the right behaviour and an easy thing to be surprised by.
     *
     * `location` is **'United Kingdom'** and that is a fact rather than a guess: Companies House is
     * the UK registry, so `SignalJurisdictionResolver` resolves GB and a converted lead carries the
     * UK restriction (ADR 0019 §4). It is the one source that cannot have an unresolvable place.
     */
    private function toItem(
        CompaniesHouseFiling $filing,
        string $eventKey,
        string $companyName,
        string $link,
        string $dedupe,
    ): SourceItem {
        $headline = $filing->headline($companyName);

        $classification = ExtractedSignal::structured(
            eventKey: $eventKey,
            subjectCompanyName: $companyName,
            subjectPersonName: $filing->personName(),
            subjectPersonRole: $filing->personRole(),
            location: 'United Kingdom',
            summary: $headline.'. Filed at Companies House on '.$filing->filedOn?->toDateString().'.',
        );

        return new SourceItem(
            title: $headline,
            // The registry's description code, kept as the snippet: it is not prose, and it is the
            // most precise statement of what the filing was that exists.
            snippet: $filing->description,
            link: $link,
            urlHash: (string) $this->normaliser->urlHash($link),
            source: 'Companies House',
            publishedAt: $filing->filedOn,
            raw: [
                'transaction_id' => $filing->transactionId,
                'type' => $filing->type,
                'category' => $filing->category,
                'subcategory' => $filing->subcategory,
                'description' => $filing->description,
                'description_values' => $filing->values,
            ],
            // **Not the URL's hash.** Every filing for one company shares the same record page, so
            // the link is not an identity here — the transaction is. This is the first source where
            // `url_hash` and `dedupe_hash` differ for a reason other than a redirector.
            contentHash: $dedupe,
            dedupeHash: $dedupe,
            kind: $this->kind(),
            classification: $classification,
        );
    }

    /**
     * The oldest filing this run will admit.
     *
     * Its own window rather than Serper's `tbs`, because a filing arrives at the registry days after
     * the thing it records and a seven-day news window would miss most of them. Config, because the
     * right answer depends on how often a team's watches run.
     *
     * Typed `DateTimeInterface`: `now()` is a `CarbonImmutable` in this app and
     * `Illuminate\Support\Carbon` is its *sibling*, not its parent, so naming either concrete class
     * here is the runtime TypeError PHPStan cannot see coming.
     */
    private function recencyFloor(): DateTimeInterface
    {
        $days = max(1, (int) config('signals.sources.companies_house.recency_days', 30));

        return now()->subDays($days)->startOfDay();
    }

    /**
     * The filing → signal-type map this run offered, read off the query's config.
     *
     * A **union across the offered definitions**, which is the one place `SignalSourceQuery::$config`
     * is not a single definition's slice — and it has to be, because one filing-history request
     * answers for every type a watch cares about. Asking per definition would multiply one HTTP
     * call per account by the number of registry types the team watches, for identical responses.
     * `SignalQueryBuilder` builds it; the run still refuses any key it did not offer.
     *
     * @return array<string, string>
     */
    private function filingMap(SignalSourceQuery $query): array
    {
        $map = $query->config['filings'] ?? [];

        if (! is_array($map)) {
            return [];
        }

        $out = [];

        foreach ($map as $filingKey => $eventKey) {
            if (is_string($filingKey) && is_string($eventKey) && $filingKey !== '' && $eventKey !== '') {
                $out[mb_strtolower($filingKey)] = $eventKey;
            }
        }

        return $out;
    }
}
