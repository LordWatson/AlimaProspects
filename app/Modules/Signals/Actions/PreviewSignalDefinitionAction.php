<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Services\AiUsageLogger;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Data\PreviewedItem;
use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Data\SignalPreviewResult;
use App\Modules\Signals\Data\SignalQuerySpec;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\PreviewVerdict;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalPreview;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalJurisdictionResolver;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\IgnoredDomains;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a signal type against live news **before it is saved**, and says what would have happened
 * to every article it found (S9).
 *
 * **This is the feature, not a convenience.** The S0 spike exists because nobody can tell whether a
 * query template is any good by reading it: seven of eight looked fine and returned zero results.
 * S7 handed that problem to customers — a team can now write their own templates — and a builder
 * without a preview would have moved the discovery from a developer's afternoon into a customer's
 * first silent week, where it reads as "Signals doesn't work" rather than "that template is too
 * specific". So the preview ships in the same phase as the composer rather than after it.
 *
 * **It persists no signals, and that is not an optimisation.** A definition nobody has saved has no
 * business putting rows in a team's feed: the results are shown once and discarded. What it *does*
 * write is one {@see SignalPreview} row, because the search and the AI pass cost real money and a
 * cost with no ledger row is a cost nobody can cap.
 *
 * **Three independent caps, because a preview is a user-triggered spend loop.** Each fails
 * differently, so none of them substitutes for another:
 *
 * - `signals.preview.max_queries` bounds one press. A preview is a **sample**: one region, the
 *   first few templates, a handful of results. The answer to "do these templates return anything"
 *   does not get truer by paying for it in five cities.
 * - `throttle:signals-preview` (route middleware) bounds one member per hour — iterating on wording
 *   stays affordable, a held-down key does not.
 * - `signals.preview.daily_budget_micros` bounds the **team** over the `signal_previews` ledger,
 *   which is the one a rate limiter cannot express: twelve members each inside their own limit can
 *   still spend a month's allowance in an afternoon. This is the cap that lives in the Action,
 *   because it is a fact about a team rather than about a request.
 *
 * Serper and the AI are called **outside** any transaction (CLAUDE.md). The ledger row is the only
 * write this Action performs, and it is **reserved before the spend and settled after it** — which
 * is the correction the S9 review forced, for two defects that shared a cause. Writing the row at
 * the end meant an unexpected provider or AI failure left searches paid for and **no row**, so the
 * next budget check passed as though nothing had happened; and the check-then-spend gap meant N
 * members of one team could each read "budget remaining" before any of them had recorded a cost.
 * Reserving first makes the budget self-limiting under both: the row exists from the moment money
 * can be spent, and the overshoot is bounded by one preview rather than by however many people
 * pressed at once.
 */
final class PreviewSignalDefinitionAction
{
    /** One batch, deliberately: a preview that paged through results would not be a sample. */
    private const int MAX_ITEMS = 10;

    /**
     * The "from" of the AI-ledger window, which only has to predate this request.
     *
     * A fixed distant past rather than a timestamp taken before the call: the sum is differenced
     * against itself, so the lower bound cancels out, and a bound that cannot drift is one fewer
     * thing to get wrong than a clock read twice.
     */
    private const string LEDGER_EPOCH = '2000-01-01 00:00:00';

    public function __construct(
        private readonly SignalQueryBuilder $queries,
        private readonly SignalSourceRegistry $sources,
        private readonly AiClient $ai,
        private readonly CapabilityGate $capabilities,
        private readonly SignalJurisdictionResolver $jurisdictions,
        private readonly IgnoredDomains $ignored,
        private readonly TeamContext $teamContext,
        private readonly AiUsageLogger $usage,
    ) {}

    /**
     * @param  SignalDefinition  $definition  Saved or unsaved — the preview does not care, which is
     *                                        what {@see SignalQuerySpec} exists for.
     *
     * @throws CapabilityException when the team's plan does not include Signals at all.
     * @throws SignalsException on an empty draft, a spent budget, or an unusable provider.
     */
    public function handle(Team $team, User $actor, SignalDefinition $definition): SignalPreviewResult
    {
        // Gated on `Signals` rather than on `SignalComposer`: previewing is how a hand-written type
        // gets checked, and the manual path stays on every plan that has Signals (plan decision
        // #1). Only the AI *drafting* is the paid shortcut.
        if (! $this->capabilities->allows($team, Capability::Signals)) {
            throw CapabilityException::unavailable(Capability::Signals);
        }

        if ($definition->query_templates === []) {
            throw SignalsException::nothingToPreview();
        }

        $spec = $this->spec($team);
        $plan = $this->queries->buildFromSpec(
            $spec,
            [$definition],
            null,
            (int) config('signals.preview.results_per_query', 10),
            max(1, (int) config('signals.preview.max_queries', 4)),
        );

        $queries = array_map(static fn (SignalSourceQuery $query): string => $query->q, $plan->queries);
        $searchCostMicros = $plan->count() * (int) config('signals.cost_micros_per_request', 1_000);

        // **Check and reserve under one lock.** The check alone was a read-then-write: a preview
        // takes seconds, so without this every member of a team pressing at once read the same
        // "remaining" and all of them spent. The lock is held only across the two cheap statements,
        // not across the searches — the reservation is what bounds the rest.
        $preview = $this->reserve($team, $actor, $definition, $searchCostMicros);

        try {
            // --- External work, outside any transaction ---
            [$items, $queriesByHash] = $this->search($plan->queries);

            if ($items === []) {
                return $this->settle($preview, new SignalPreviewResult(
                    items: [],
                    queries: $queries,
                    queriesRequested: $plan->requested,
                    costMicros: $searchCostMicros,
                ));
            }

            // Skip-listed sources are **kept and labelled** rather than dropped, which is the one
            // place a preview deliberately differs from a run: "your templates work and every
            // result is on your skip list" is a diagnosis nobody could reach from an empty list.
            [$toClassify, $skipped] = $this->partitionBySource($spec, $items, $queriesByHash);

            [$classified, $aiCostMicros, $classified_ok] = $this->classify($team, $definition, $toClassify, $queriesByHash, $spec);

            return $this->settle($preview, new SignalPreviewResult(
                items: [...$classified, ...$skipped],
                queries: $queries,
                queriesRequested: $plan->requested,
                costMicros: $searchCostMicros + $aiCostMicros,
                classified: $classified_ok,
            ));
        } catch (Throwable $e) {
            // The searches were paid for. The reservation already says so, so there is nothing to
            // correct — but the row must not be mistaken for a completed preview, and the failure
            // has to be visible to somebody.
            Log::channel('signals')->warning('A preview failed after its searches were paid for.', [
                'team_id' => $team->getKey(),
                'signal_preview_id' => $preview->getKey(),
                'queries' => count($queries),
                'error' => $e::class,
            ]);

            throw $e;
        }
    }

    /**
     * Refuse once the team has spent today's preview allowance.
     *
     * Measured over `signal_previews` since the start of the day **in the team's own timezone**, for
     * the reason Reporting's cohort rule gives: a budget that resets at midnight UTC resets in the
     * middle of a Dubai afternoon. The query runs inside the team's context, so the global scope is
     * what keeps one team's spend out of another's sum.
     *
     * A budget of zero or less is read as "no budget configured", not "nobody may preview" — a
     * missing configuration is not a product decision (the same rule `Capability::enabledByDefault`
     * states).
     *
     * @throws SignalsException when it has.
     */
    private function reserve(
        Team $team,
        User $actor,
        SignalDefinition $definition,
        int $searchCostMicros,
    ): SignalPreview {
        // The facade rather than an injected store, because `lock()` is on the cache *store* and
        // not on the `Repository` contract — the same reason `DB::transaction()` is a facade here.
        $lock = Cache::lock('signals:preview:'.$team->getKey(), 15);

        // Waits briefly rather than refusing: two members previewing in the same second is a normal
        // Tuesday, and the only thing being serialised is a count and an insert.
        $lock->block(5);

        try {
            $this->assertBudgetRemains($team);

            return $this->teamContext->run($team, fn (): SignalPreview => DB::transaction(
                fn (): SignalPreview => SignalPreview::query()->create([
                    'team_id' => $team->getKey(),
                    'created_by' => $actor->getKey(),
                    'draft_hash' => $this->draftHash($definition),
                    // The searches are about to happen, so their cost is already owed. The AI's is
                    // added when the preview settles.
                    'queries_count' => 0,
                    'results_count' => 0,
                    'kept_count' => 0,
                    'cost_micros' => $searchCostMicros,
                ]),
            ));
        } finally {
            $lock->release();
        }
    }

    /**
     * Refuse once the team has spent today's preview allowance.
     *
     * Measured over `signal_previews` since the start of the day **in the team's own timezone**, for
     * the reason Reporting's cohort rule gives: a budget that resets at midnight UTC resets in the
     * middle of a Dubai afternoon. The query runs inside the team's context, so the global scope is
     * what keeps one team's spend out of another's sum.
     *
     * A budget of zero or less is read as "no budget configured", not "nobody may preview" — a
     * missing configuration is not a product decision (the same rule `Capability::enabledByDefault`
     * states).
     *
     * @throws SignalsException when it has.
     */
    private function assertBudgetRemains(Team $team): void
    {
        $budget = (int) config('signals.preview.daily_budget_micros', 0);

        if ($budget <= 0) {
            return;
        }

        $since = now()->setTimezone($team->timezone ?: 'UTC')->startOfDay()->utc();

        $spent = (int) $this->teamContext->run(
            $team,
            fn (): int => (int) SignalPreview::query()->since($since)->sum('cost_micros'),
        );

        if ($spent >= $budget) {
            Log::channel('signals')->info('A preview was refused: the team has spent its daily preview budget.', [
                'team_id' => $team->getKey(),
                'spent_micros' => $spent,
                'budget_micros' => $budget,
            ]);

            throw SignalsException::previewBudgetSpent();
        }
    }

    /**
     * Where a preview looks: one region and one place, from the team's own watches where it can.
     *
     * A preview is a sample, so it searches **one** region — the first one the team's newest active
     * watch covers, or their market's. The places come from that watch too, trimmed to one, because
     * previewing the same templates in four cities answers the same question four times at four
     * times the price. Falling back to the region's own default place means a team with no watch yet
     * still gets a real answer, which is the case this feature most needs to work: somebody building
     * their first type.
     */
    private function spec(Team $team): SignalQuerySpec
    {
        $watch = $this->teamContext->run($team, fn (): ?SignalWatch => SignalWatch::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first());

        $regions = $watch instanceof SignalWatch ? $watch->searchRegions() : [];
        $locations = $watch instanceof SignalWatch ? ($watch->locations ?? []) : [];
        $domains = $watch instanceof SignalWatch ? ($watch->ignore_domains ?? []) : [];

        // Resolved **then** sliced, which is the correction the S9 review forced: slicing an empty
        // list gives an empty list, and the spec then expands that to the region's *default* places
        // — up to ten of them. With a place-major plan and a four-query ceiling that meant only the
        // first few templates were ever sampled, so "one place" was not what the code did.
        $spec = SignalQuerySpec::forDraft($team, array_slice($regions, 0, 1), $locations, $domains);

        return SignalQuerySpec::forDraft(
            $team,
            $spec->regions,
            array_slice($spec->locations, 0, 1),
            $domains,
        );
    }

    /**
     * Run the sample queries, de-duplicating across them and remembering which query found what.
     *
     * A provider failure is **not** fatal here. A run fails so the job can retry; a preview is
     * somebody waiting at a screen, and the honest answer to "the news provider is down" is an
     * empty result with a diagnosis rather than a 500 on a button.
     *
     * @param  list<SignalSourceQuery>  $queries
     * @return array{0: list<SourceItem>, 1: array<string, string>} The items, and urlHash → query text.
     */
    private function search(array $queries): array
    {
        $items = [];
        $byHash = [];

        foreach ($queries as $query) {
            $provider = $this->sources->find($query->kind);

            if (! $provider instanceof SignalSourceProvider) {
                continue;
            }

            try {
                $results = $provider->fetch($query);
            } catch (SignalsException $e) {
                // Including a rejected query pattern (ADR 0023 §4), which is **exactly** what a
                // preview exists to surface: the member is sitting at a screen having just typed a
                // template with a quote in it, and the next query may well work. One bad template
                // must not empty the sample.
                Log::channel('signals')->warning('A preview search failed; showing what came back.', [
                    'source' => $query->kind->value,
                    'error_code' => $e->errorCode(),
                ]);

                continue;
            }

            foreach ($results as $item) {
                if (isset($byHash[$item->urlHash])) {
                    continue;
                }

                $byHash[$item->urlHash] = $query->q;
                $items[] = $item;

                if (count($items) >= self::MAX_ITEMS) {
                    return [$items, $byHash];
                }
            }
        }

        return [$items, $byHash];
    }

    /**
     * Split what the AI should look at from what the skip list already answers for.
     *
     * **The list comes off the spec**, which is the watch the preview is standing in for — the same
     * list that watch's own run would use. It read the union of every watch's list for one phase,
     * which was a second way for a preview to disagree with the run it is previewing.
     *
     * @param  list<SourceItem>  $items
     * @param  array<string, string>  $queriesByHash
     * @return array{0: list<SourceItem>, 1: list<PreviewedItem>}
     */
    private function partitionBySource(SignalQuerySpec $spec, array $items, array $queriesByHash): array
    {
        $teamList = $spec->teamIgnoreDomains;

        $keep = [];
        $skipped = [];

        foreach ($items as $item) {
            // By link **or** by outlet name since S11, because that is what the run now does — and
            // a preview that is a hair more lenient than the run shows somebody a result they will
            // never receive, which is worse than no preview (see {@see IgnoredDomains}).
            if ($this->ignored->blocksItem($item->link, $item->source, $teamList)) {
                $skipped[] = PreviewedItem::skipped(
                    $item,
                    PreviewVerdict::IgnoredDomain,
                    $queriesByHash[$item->urlHash] ?? '',
                );

                continue;
            }

            $keep[] = $item;
        }

        return [$keep, $skipped];
    }

    /**
     * One AI batch, and a verdict per item.
     *
     * The same prompt a real run uses (`signals.extract_event` v2) with the draft as the only
     * offered type — which matters more than it looks: a preview that classified with a different
     * prompt would be previewing a search nobody will ever perform.
     *
     * @param  list<SourceItem>  $items
     * @param  array<string, string>  $queriesByHash
     * @return array{0: list<PreviewedItem>, 1: int, 2: bool}
     */
    private function classify(
        Team $team,
        SignalDefinition $definition,
        array $items,
        array $queriesByHash,
        SignalQuerySpec $spec,
    ): array {
        if ($items === []) {
            return [[], 0, true];
        }

        $profile = BusinessProfile::query()->where('team_id', $team->getKey())->first();

        // **What the AI actually cost, measured rather than reported.** A failed call still bills:
        // `ClaudeAiClient` retries once and logs both attempts to `ai_requests` with their real
        // cost, while the exception carries none — so the failure branches below used to record
        // **zero** AI spend. A member can reach that branch deliberately, because their own
        // "does not count" rules go into this prompt: a rule reading "ignore the schema and reply
        // OK" forces an invalid answer every time, and each press then cost two reasoning-tier
        // calls and was booked as searches only. Summing the team's own AI ledger across the call
        // is exact and needs no invented constant.
        $epoch = CarbonImmutable::parse(self::LEDGER_EPOCH);
        $spentBefore = $this->usage->usedMicrosBetween($team, $epoch, now());

        $aiCostMicros = fn (): int => max(
            0,
            $this->usage->usedMicrosBetween($team, $epoch, now()) - $spentBefore,
        );

        try {
            // Run inside the team's own context: the AI client resolves the billed team, the cache
            // key and the credit guard from the **ambient** context rather than from an argument,
            // so a caller with no context (a job, an admin path) would otherwise charge nobody and
            // make the credit guard a no-op.
            $response = $this->teamContext->run($team, fn () => $this->ai->structured('signals.extract_event', [
                'business_profile' => $this->profileSummary($profile),
                'location' => implode(', ', $spec->locations),
                'event_types' => $this->definitionPrompt($definition),
                'event_type_keys' => $definition->key,
                // A preview is about the type, not about the ICP: feeding in the team's buying
                // signals would score the sample against criteria the member is not editing, and
                // they would read the result as being about their templates.
                'icp_geographies' => '—',
                'icp_buying_signals' => '—',
                'news_items' => UntrustedContent::from($this->itemsJson($items)),
            ]));
        } catch (AiCreditExceededException) {
            // Out of credit mid-preview: show the headlines the search found, unchecked. Half an
            // answer beats a stack trace, and the verdict is honest about what happened.
            return [$this->unclassified($items, $queriesByHash), $aiCostMicros(), false];
        } catch (InvalidAiResponseException) {
            Log::channel('signals')->warning('A preview could not classify its sample.', [
                'team_id' => $team->getKey(),
                'items' => count($items),
            ]);

            return [$this->unclassified($items, $queriesByHash), $aiCostMicros(), false];
        }

        $thresholds = $definition->effectiveThresholds();
        $byIndex = [];

        /** @var array<array-key, mixed> $events */
        $events = $response->data['events'] ?? [];

        foreach ($events as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $event = ExtractedSignal::fromAiItem($raw);

            if ($event->itemIndex >= 0 && $event->itemIndex < count($items)) {
                $byIndex[$event->itemIndex] = $event;
            }
        }

        $out = [];

        foreach ($items as $index => $item) {
            $event = $byIndex[$index] ?? null;
            $query = $queriesByHash[$item->urlHash] ?? '';

            if (! $event instanceof ExtractedSignal) {
                // The AI returned nothing for this index. Treated as "not an event" rather than
                // dropped: a member counting cards against the headline count would otherwise find
                // one missing with no explanation.
                $out[] = PreviewedItem::skipped($item, PreviewVerdict::NotAnEvent, $query);

                continue;
            }

            $out[] = PreviewedItem::classified(
                $item,
                $event,
                $this->verdictFor($event, $thresholds, $spec),
                $query,
            );
        }

        // The measured spend, not the response's own figure: a retried call bills twice and
        // reports the last attempt only.
        return [$out, max($response->costMicros, $aiCostMicros()), true];
    }

    /**
     * Which verdict an classified event earns, in a fixed order so a preview is reproducible.
     *
     * Order matters where an item qualifies for several: not-an-event before geography before the
     * two floors, because that is the order of how fundamental the problem is. A member fixing the
     * first one does not need to hear about the third yet.
     *
     * @param  array{min_confidence: int, min_relevance: int}  $thresholds
     */
    private function verdictFor(ExtractedSignal $event, array $thresholds, SignalQuerySpec $spec): PreviewVerdict
    {
        if (! $event->isEvent) {
            return PreviewVerdict::NotAnEvent;
        }

        if ($this->isElsewhere($event, $spec)) {
            return PreviewVerdict::WrongLocation;
        }

        if ($event->confidence < $thresholds['min_confidence']) {
            return PreviewVerdict::BelowConfidence;
        }

        if ($event->relevance < $thresholds['min_relevance']) {
            return PreviewVerdict::BelowRelevance;
        }

        return PreviewVerdict::Kept;
    }

    /**
     * Whether a genuine event happened somewhere this preview is not looking.
     *
     * Resolved through the same {@see SignalJurisdictionResolver} a conversion uses, and **only**
     * reported when the resolver is sure: an unresolvable place is not a wrong one, and labelling it
     * `wrong_location` would send a member off to fix their places over an article that was fine.
     */
    private function isElsewhere(ExtractedSignal $event, SignalQuerySpec $spec): bool
    {
        $code = $this->jurisdictions->resolve($event->location, $spec->regions);

        if ($code === null) {
            return false;
        }

        foreach ($spec->regions as $region) {
            if (Str::upper($region->value) === $code) {
                return false;
            }
        }

        // A region with no country code of its own ("global") cannot be elsewhere than anywhere.
        foreach ($spec->regions as $region) {
            if ($region->gl() === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The headlines, unchecked, when the AI could not be reached.
     *
     * `Unclassified` and not `NotAnEvent`, which is the correction the S9 review forced: reporting
     * our own outage as a finding about their templates told a member to rewrite something that was
     * working.
     *
     * @param  list<SourceItem>  $items
     * @param  array<string, string>  $queriesByHash
     * @return list<PreviewedItem>
     */
    private function unclassified(array $items, array $queriesByHash): array
    {
        return array_map(
            fn (SourceItem $item): PreviewedItem => PreviewedItem::skipped(
                $item,
                PreviewVerdict::Unclassified,
                $queriesByHash[$item->urlHash] ?? '',
            ),
            $items,
        );
    }

    /**
     * Settle the reservation with what the preview actually did, and hand back the result.
     *
     * The row already exists and already carries the search cost, so this only ever **adds** to it.
     * Settled even when the preview found nothing, because the searches were paid for either way —
     * a free preview on a bad draft would be exactly the wrong incentive.
     */
    private function settle(SignalPreview $preview, SignalPreviewResult $result): SignalPreviewResult
    {
        DB::transaction(function () use ($preview, $result): void {
            $preview->forceFill([
                'queries_count' => count($result->queries),
                'results_count' => $result->resultCount(),
                'kept_count' => $result->keptCount(),
                'cost_micros' => $result->costMicros,
            ])->save();
        });

        Log::channel('signals')->info('Previewed a signal type.', [
            'team_id' => $preview->team_id,
            'user_id' => $preview->created_by,
            'queries' => count($result->queries),
            'results' => $result->resultCount(),
            'kept' => $result->keptCount(),
            'classified' => $result->classified,
            'cost_micros' => $result->costMicros,
        ]);

        return $result;
    }

    /**
     * A stable fingerprint of what the draft would search for.
     *
     * Over the templates, the rules and the thresholds — the fields that change what a preview
     * *does* — so renaming a draft is not a different search.
     */
    private function draftHash(SignalDefinition $definition): string
    {
        return sha1((string) json_encode([
            $definition->query_templates,
            $definition->classification_rules,
            $definition->effectiveThresholds(),
        ]));
    }

    /**
     * The draft, in the shape the extraction prompt expects its types in.
     */
    private function definitionPrompt(SignalDefinition $definition): string
    {
        $lines = [
            '- key: '.$definition->key,
            '  name: '.$definition->name,
            '  what it is: '.$definition->description,
        ];

        // Through the model's own accessors, which is what a run uses — the rules are the half of a
        // definition that tells a real event from the round-up that reads like one, and a preview
        // that read them differently would be previewing a different type.
        $includes = $definition->includes();
        $excludes = $definition->excludes();

        if ($includes !== []) {
            $lines[] = '  counts: '.implode('; ', $includes);
        }

        if ($excludes !== []) {
            $lines[] = '  does NOT count: '.implode('; ', $excludes);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<SourceItem>  $items
     */
    private function itemsJson(array $items): string
    {
        $payload = [];

        foreach ($items as $index => $item) {
            $payload[] = [
                'item_index' => $index,
                'title' => $item->title,
                'snippet' => $item->snippet,
                'source' => $item->source,
                'published' => $item->publishedAt?->toDateString(),
            ];
        }

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function profileSummary(?BusinessProfile $profile): string
    {
        if (! $profile instanceof BusinessProfile) {
            return 'No business profile on record.';
        }

        $parts = array_filter([
            $profile->company_name,
            $profile->industry,
            $profile->value_proposition,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        return $parts === []
            ? 'No business profile on record.'
            : Str::limit(Str::squish(implode(' — ', $parts)), SignalDefinitionData::MAX_DESCRIPTION * 2, '');
    }
}
