<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\RecordLeadActivityAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Data\SignalQueryEntity;
use App\Modules\Signals\Data\SignalQueryPlan;
use App\Modules\Signals\Data\SignalQuerySpec;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalRejectionVerdict;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Events\AccountSignalsDetected;
use App\Modules\Signals\Events\SignalRunProgressed;
use App\Modules\Signals\Events\SignalsDetected;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Exceptions\SourceAddressNotFound;
use App\Modules\Signals\Exceptions\SourceQueryRejected;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Services\SignalArticleEnricher;
use App\Modules\Signals\Services\SignalClusterer;
use App\Modules\Signals\Services\SignalJurisdictionResolver;
use App\Modules\Signals\Services\SignalLeadMatcher;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Services\SignalRejectionLedger;
use App\Modules\Signals\Services\SignalScorer;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalSourceBreakdown;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one signal watch end to end (ADR 0011, S3). Must run inside the watch's {@see TeamContext}
 * (the job does this). In order: open/claim the run (Running), resolve the watch's signal
 * definitions, build queries from them + the active ICP and search Serper, drop already-seen
 * articles, batch the rest through the `signals.extract_event` prompt (each item wrapped as
 * {@see UntrustedContent}), keep genuine events clearing their own type's confidence and relevance
 * floors, then persist the kept signals + the run totals in one transaction (idempotent via
 * `insertOrIgnore` on `(team_id, url_hash)`). Serper and the AI are **never** called inside a
 * transaction (CLAUDE.md). A non-retryable provider failure or AI-credit exhaustion fails the run
 * cleanly; a transient Serper failure bubbles so the job can retry.
 *
 * **Since S7 the event types are rows, not an enum** (ADR 0018), and two things about this Action
 * follow from that:
 *
 * 1. **It is the guard the AI schema used to be.** v1 constrained `event_type` to a JSON-schema
 *    `enum`, so a hijacked or hallucinated response could not name a type outside the set. With
 *    types as data that enum cannot exist, so v2 of the prompt bounds the string and
 *    {@see self::resolveDefinition()} refuses any key that was not offered for the batch. A prompt
 *    cannot be trusted to enforce a constraint it merely describes.
 * 2. **Thresholds are per type.** A team that finds one of their types noisy raises *its* floor;
 *    {@see SignalDefinition::effectiveThresholds()} falls back to the global `signals.*` config, so
 *    an untuned definition behaves exactly as v1 did.
 *
 * **Since S10 a watch is either topical or entity-scoped** (ADR 0021). A topical watch asks what
 * happened in a place; an entity-scoped one asks what happened to a list of named accounts, which is
 * the warm version of the same question. Two things follow:
 *
 * 1. **An entity run searches a window of the list, not all of it.** A pipeline watchlist holds up
 *    to 250 accounts and a run is capped at forty searches, because every search is money. So the
 *    run takes the next forty from where the last one stopped (`signal_watches.entity_cursor`) and
 *    comes round again tomorrow — a daily watch covers 250 accounts about every six days. Trimming
 *    to the first forty, which is what the topical trim does, would mean the eighty-ninth account
 *    was never searched at all, silently, forever.
 * 2. **Every kept signal is matched against the team's own leads** ({@see SignalLeadMatcher}),
 *    whether the watch was entity-scoped or not — a topical watch finding an event about a company
 *    already in the pipeline is exactly as useful, and the member should not have to have predicted
 *    it. A match writes `matched_lead_id`, a confidence, and one timeline entry on that lead.
 *
 * **Since S11 the run pays per event rather than per article.** Two changes, both of them about the
 * same waste:
 *
 * 1. **It classifies one representative per story.** Candidate articles are grouped by headline
 *    overlap before the AI batch ({@see SignalClusterer::group()}), the fullest one is sent, and the
 *    verdict is applied to the group — so an acquisition covered by eight outlets costs one AI call
 *    instead of eight. Every member still becomes a row; the {@see SignalCluster} is what makes
 *    them one card. `signals.cluster_before_ai` turns it off, which restores v1's behaviour exactly
 *    and is the way back from a regression without a rollback.
 * 2. **It remembers what it rejected.** A round-up inside the recency window used to cost an AI
 *    batch every single day; {@see SignalRejectionLedger} skips it for 30 days, keyed by the article
 *    *and* the set of types that were offered — because a team that adds a type has asked a
 *    different question and is owed a fresh answer.
 *
 * **Since S12 every kept signal carries a heat** ({@see SignalScorer}), scored inside the same
 * transaction as the insert and **after** the clusters are reconciled — breadth is one of the eight
 * components, so a cluster has to know how many outlets it holds first. A run that only adds
 * coverage to an existing event re-scores that event's primary for the same reason: its breadth
 * changed, and a feed ordered by heat would otherwise keep ranking a six-outlet story as the
 * one-outlet story it was yesterday.
 *
 * It also compares `dedupe_hash` rather than `url_hash` when deciding what it has already seen,
 * which is the fix for the redirect defect: behind `google.com/goto?url=<token>` a varying token
 * made the same article a new signal on every run.
 *
 * A watch whose every type has been paused produces an **empty, completed run**, not a failed one:
 * nothing went wrong, there was simply nothing to look for.
 */
final class RunSignalWatchAction
{
    private const int BATCH_SIZE = 10;

    public function __construct(
        private readonly SignalQueryBuilder $queries,
        private readonly SignalSourceRegistry $sources,
        private readonly AiClient $ai,
        private readonly CapabilityGate $capabilities,
        private readonly SignalJurisdictionResolver $jurisdictions,
        private readonly SignalLeadMatcher $matcher,
        private readonly RecordLeadActivityAction $activity,
        private readonly SignalClusterer $clusterer,
        private readonly SignalRejectionLedger $rejections,
        private readonly SignalScorer $scorer,
        private readonly SignalArticleEnricher $articles,
        private readonly ResolveWatchlistCompanyNumbersAction $companyNumbers,
    ) {}

    public function handle(SignalWatch $watch): SignalRun
    {
        // **The capability check, in the Action rather than in middleware** (Phase 19b, ADR 0015).
        // This path is reachable from a queued job and from the scheduler, so there is no request
        // to hang middleware on — ADR 0012's argument, unchanged. The route middleware beside it is
        // the courtesy that gives a member a sentence; this is what makes the refusal true.
        //
        // Before the run is claimed, deliberately: a refused run should leave no `signal_runs` row
        // at all, rather than a failed one that reads as the news provider having broken.
        $team = Team::query()->findOrFail($watch->team_id);

        if (! $this->capabilities->allows($team, Capability::Signals)) {
            throw CapabilityException::unavailable(Capability::Signals);
        }

        $run = $this->claimOrCreateRun($watch);
        $this->broadcast($run, SignalRunStatus::Running, 10, 'Searching the news');

        // Resolved through a team-scoped query rather than read off the relation, because the
        // pivot's FK is not team-qualified at the database level and this job runs with no request
        // behind it (`docs/modules/signals.md`, "Cross-team parent references").
        $definitions = $this->definitions($watch);

        if ($definitions === []) {
            // Every type paused, or the last one removed. Nothing to search for, nothing spent, and
            // a completed run is the honest record of that — a Failed one would read as the news
            // provider having broken.
            Log::channel('signals')->info('Signal run had no active signal types to look for.', [
                'team_id' => (int) $watch->team_id,
                'signal_watch_id' => (int) $watch->getKey(),
            ]);

            return $this->complete($run, $watch, [], 0, 0, 0, 0);
        }

        $icp = $this->activeIcp($watch->team_id);
        $profile = BusinessProfile::query()->where('team_id', $watch->team_id)->first();

        [$plan, $nextCursor] = $this->planFor($watch, $definitions, $icp);

        // What each source cost and found (S13, task 8). Accumulated by `search()` as it goes rather
        // than multiplied out afterwards, because a rejected query and a query that found nothing
        // are different things and only the loop can tell them apart.
        $breakdown = new SignalSourceBreakdown;

        // --- External work: the sources (outside any transaction) ---
        try {
            [$items, $resultsCount] = $this->search($plan->queries, $breakdown, $watch);
        } catch (SignalsException $e) {
            if ($e->isRetryable()) {
                throw $e; // bubble → the job retries with backoff
            }

            // Misconfig, a refused credential, a source that refused every query, or a driver that
            // can never succeed. **The spend recorded is whatever ran before the failure**, which
            // is no longer necessarily zero — a plan can span sources and the second one may be the
            // one that broke. `search()` records each source's cost in a `finally` precisely so
            // this is true rather than merely claimed (S13a review finding 2).
            return $this->fail(
                $run,
                $watch,
                $e->getMessage(),
                // What was actually attempted, not what was planned — see `totalQueriesAttempted()`.
                $breakdown->totalQueriesAttempted(),
                $breakdown->totalResults(),
                $breakdown->totalCostMicros(),
                $plan->requested,
                $breakdown,
            );
        }

        $searchCostMicros = $breakdown->totalCostMicros();

        // **The queries this run actually made**, which is what `signal_runs.queries_count` records
        // from here on. It used to be `$plan->count()` — the *planned* figure, which counted a
        // refused query and a query skipped for an unregistered source alongside the ones that ran,
        // so the column and `source_breakdown` disagreed and the admin screen showed both without
        // them summing (S13a review finding 3). `queries_requested` remains the separate "what the
        // plan wanted before the ceiling" figure.
        $queriesCount = $breakdown->totalQueriesAttempted();

        // Drop articles this team has already seen, so a re-run does no AI work on them.
        $items = $this->dropAlreadySeen($watch->team_id, $items);

        // And the ones it has already said no to, under this exact set of types (S11). The
        // fingerprint is what makes "already said no" a statement about a question rather than
        // about an article: add a type and every one of these is asked again.
        $fingerprint = $this->rejections->fingerprintFor($definitions);
        $items = $this->dropRejected($watch, $fingerprint, $items);

        $this->broadcast($run, SignalRunStatus::Running, 55, 'Analysing what we found');

        // --- External work: AI extraction (outside any transaction) ---
        [$kept, $aiCostMicros, $creditExhausted] = $this->extract($watch, $definitions, $icp, $profile, $items, $fingerprint);
        $costMicros = $searchCostMicros + $aiCostMicros;

        if ($creditExhausted) {
            // "Not half-run": don't persist partial signals, but record the spend already incurred so
            // metering is accurate and the watch backs off instead of re-dispatching hourly.
            return $this->fail(
                $run,
                $watch,
                'AI credit exhausted. Top up your allowance to run Signals again.',
                $queriesCount,
                $resultsCount,
                $costMicros,
                $plan->requested,
                $breakdown,
            );
        }

        $this->broadcast($run, SignalRunStatus::Running, 85, 'Saving new signals');

        return $this->complete(
            run: $run,
            watch: $watch,
            kept: $kept,
            queriesCount: $queriesCount,
            resultsCount: $resultsCount,
            costMicros: $costMicros,
            queriesRequested: $plan->requested,
            nextCursor: $nextCursor,
            breakdown: $breakdown,
        );
    }

    /**
     * Reuse a non-terminal run for this watch (a prior attempt of the same job) or open a new one.
     */
    private function claimOrCreateRun(SignalWatch $watch): SignalRun
    {
        return DB::transaction(function () use ($watch): SignalRun {
            $run = SignalRun::query()
                ->where('team_id', $watch->team_id)
                ->where('signal_watch_id', $watch->getKey())
                ->whereIn('status', [SignalRunStatus::Queued->value, SignalRunStatus::Running->value])
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($run instanceof SignalRun) {
                $run->forceFill([
                    'status' => SignalRunStatus::Running,
                    'started_at' => $run->started_at ?? now(),
                ])->save();

                return $run;
            }

            return SignalRun::query()->create([
                'team_id' => $watch->team_id,
                'signal_watch_id' => $watch->getKey(),
                'status' => SignalRunStatus::Running,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * The watch's **active** signal types, team-scoped, in pivot order.
     *
     * A paused type stays attached to the watch (un-pausing it should put the watch back the way it
     * was) but is not run, which is what `is_active` is for. The `team_id` constraint is explicit
     * beside the global scope for the usual reason, and a more pressing one here: a definition row
     * may belong to the platform rather than to a team, and a run must never execute a preset
     * directly — a team runs their own clone of it (ADR 0018 §3).
     *
     * @return list<SignalDefinition>
     */
    private function definitions(SignalWatch $watch): array
    {
        // Read off the pivot directly rather than through the relation, so **both** sides of the
        // join carry an explicit `team_id` and neither depends on a global scope being active.
        $ids = DB::table('signal_watch_definition')
            ->where('signal_watch_id', $watch->getKey())
            ->where('team_id', $watch->team_id)
            ->pluck('signal_definition_id');

        if ($ids->isEmpty()) {
            return [];
        }

        return array_values(SignalDefinition::query()
            ->where('team_id', $watch->team_id)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Run every query against its own source, flatten, and de-duplicate within this run.
     *
     * By the **dedupe identity**, not the URL hash, since S11: behind a redirector that varies its
     * token the same article arrives under two links in one run, and the whole point of the content
     * hash is that those are one item. The de-duplication is across **sources** as well as across
     * queries since S13 — the news and the wider web routinely return the same article, and a story
     * found twice is one signal that happens to have two ways in. First writer wins, which means the
     * source listed earlier in the enum (news) is the one attributed; that is the right bias, because
     * a news item carries an outlet name and a `/search` hit carries a hostname.
     *
     * **A rejected query does not fail the run** (ADR 0023 §4). A source that refuses a query
     * pattern — which is how Serper answers a `site:` or quoted template on a free account tier — is
     * telling us about that query, so the query is counted as rejected on the breakdown and the rest
     * of the plan proceeds. Before S13 this raised a retryable failure and cost the team the whole
     * run, three times over. A genuinely unwell source still bubbles.
     *
     * @param  list<SignalSourceQuery>  $queries
     * @return array{0: list<SourceItem>, 1: int} unique items and the raw result count across queries
     */
    private function search(array $queries, SignalSourceBreakdown $breakdown, SignalWatch $watch): array
    {
        $unique = [];
        $rawCount = 0;

        /** @var array<string, int> $executed queries actually answered, per source */
        $executed = [];

        try {
            foreach ($queries as $query) {
                $provider = $this->sources->find($query->kind);

                if (! $provider instanceof SignalSourceProvider) {
                    // A stored key with no provider: a source withdrawn between deploys, or one
                    // whose credential was pulled. Skipped rather than fatal — a scheduled watch
                    // must not die at three in the morning because a source went away (ADR 0023 §1).
                    // Not counted anywhere, because no query was made.
                    Log::channel('signals')->warning('Signal run skipped a query for an unregistered source.', [
                        'team_id' => (int) $watch->team_id,
                        'signal_watch_id' => (int) $watch->getKey(),
                        'source' => $query->kind->value,
                    ]);

                    continue;
                }

                try {
                    $items = $provider->fetch($query);
                } catch (SourceAddressNotFound) {
                    // **The addressee is not there** (S13b review, findings 1 and 2): a company
                    // number not on the register, a feed a publisher has deleted. Counted under
                    // `skipped`, which `sourcesThatAnsweredNothing()` deliberately ignores — so one
                    // struck-off account on a watchlist cannot take the rest of the run with it,
                    // and a dead feed cannot fail a run whose news queries all worked.
                    $breakdown->recordSkip($query->kind);

                    continue;
                } catch (SourceQueryRejected) {
                    // A verdict about this query. A **typed** catch rather than a comparison
                    // against an error-code string: this is the one place in the run that decides
                    // whether a failure is survivable, and a renamed code must not be able to turn
                    // it into a swallowed outage (review finding).
                    $breakdown->recordRejection($query->kind);

                    continue;
                }

                $executed[$query->kind->value] = ($executed[$query->kind->value] ?? 0) + 1;
                $breakdown->recordQuery($query->kind, count($items));

                foreach ($items as $item) {
                    $rawCount++;

                    // Attributed to the template that found it, for the explanation panel (S13; the
                    // gap S12 recorded as its second deviation). Stamped here rather than in the
                    // provider because a provider is handed one query and has no reason to hold
                    // the plan.
                    $unique[$item->dedupeKey()] ??= $item->foundBy($query->q);
                }
            }
        } finally {
            // **In a `finally`, which is the review's finding and a real one.** The cost used to be
            // recorded in a second loop after this one, so a non-retryable failure on query N left
            // `search()` before any of it was recorded — and the caller then wrote `cost_micros: 0`
            // onto a failed run that had already paid for N-1 searches, while this method's own
            // comment claimed the spend was kept.
            //
            // Asked of each source rather than multiplied here: a source knows its own pricing, one
            // of the declared ones is free, and a future one may not bill per query at all. Still
            // one call per source rather than one per query, so `estimatedCostMicros()` keeps
            // seeing a count it can price non-linearly if it ever needs to.
            foreach ($executed as $key => $count) {
                $kind = SignalSourceKind::parse($key);
                $provider = $kind === null ? null : $this->sources->find($kind);

                if ($kind instanceof SignalSourceKind && $provider instanceof SignalSourceProvider) {
                    $breakdown->recordCost($kind, $provider->estimatedCostMicros($count));
                }
            }
        }

        $this->assertSourcesAnswered($breakdown, $watch);

        return [array_values($unique), $rawCount];
    }

    /**
     * Fail the run if a source refused **everything** it was asked (review finding 1).
     *
     * ADR 0023 §4 said a run whose every query was rejected completes having found nothing, because
     * nothing is broken — we asked badly. That holds when *some* queries were refused: the rest ran,
     * the run worked, and the rejection is a note on the breakdown.
     *
     * It does not hold when a source answered **nothing at all**. Then either every template a
     * member wrote is unusable, or — the case that matters — something systemic is wrong that
     * happens to arrive as a 400: an exhausted account, a parameter we are sending wrongly, a
     * provider that has changed its rules. Completing green with "0 signals" and no explanation is
     * the "searched nothing and completed successfully" failure this module keeps legislating
     * against, and it would do it silently, every run, for ever, with the breaker deliberately shut.
     *
     * So it fails, non-retryably, with the message that tells a member what to change. A failed run
     * is visible on their own screen and on `/admin/signal-runs`; a green one is not.
     */
    private function assertSourcesAnswered(SignalSourceBreakdown $breakdown, SignalWatch $watch): void
    {
        foreach ($breakdown->sourcesThatAnsweredNothing() as $kind) {
            Log::channel('signals')->error('A signal source refused every query in a run.', [
                'team_id' => (int) $watch->team_id,
                'signal_watch_id' => (int) $watch->getKey(),
                'source' => $kind->value,
                'rejected' => $breakdown->rejectionsFor($kind),
            ]);

            throw SignalsException::sourceQueryRejected($kind->value);
        }
    }

    /**
     * Remove items whose identity already exists for this team, so a re-run never re-processes an
     * article. Scoped explicitly by `team_id` (belt-and-braces beside the global scope), so one team
     * can never dedupe against another's signals even if ever called outside TeamContext.
     *
     * **Compares `dedupe_hash` since S11, and that is the redirect fix.** It used to compare
     * `url_hash`, which for a `google.com/goto?url=<token>` link hashes the token — so a token that
     * varied between requests re-admitted the same article on every run, at the price of a fresh
     * Serper query and a fresh AI batch each time (`docs/modules/signals.md`, "Known limitations",
     * consequence 1).
     *
     * @param  list<SourceItem>  $items
     * @return list<SourceItem>
     */
    private function dropAlreadySeen(int $teamId, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $hashes = array_map(static fn (SourceItem $i): string => $i->dedupeKey(), $items);

        $seen = Signal::query()
            ->where('team_id', $teamId)
            ->whereIn('dedupe_hash', $hashes)
            ->pluck('dedupe_hash')
            ->all();

        if ($seen === []) {
            return $items;
        }

        $seen = array_flip($seen);

        return array_values(array_filter($items, static fn (SourceItem $i): bool => ! isset($seen[$i->dedupeKey()])));
    }

    /**
     * Remove items this team's classifier has already rejected under this same set of types (S11).
     *
     * The S3 trade-off (b), closed: a non-event was forgotten, so a weekly round-up that sits inside
     * the recency window cost an AI batch every day for as long as it stayed there. Logged as a
     * **count**, which is also the measure of whether the memory is earning its keep.
     *
     * @param  list<SourceItem>  $items
     * @return list<SourceItem>
     */
    private function dropRejected(SignalWatch $watch, string $fingerprint, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $rejected = $this->rejections->rejected(
            (int) $watch->team_id,
            $fingerprint,
            array_map(static fn (SourceItem $i): string => $i->dedupeKey(), $items),
        );

        if ($rejected === []) {
            return $items;
        }

        $kept = array_values(array_filter(
            $items,
            static fn (SourceItem $i): bool => ! isset($rejected[$i->dedupeKey()]),
        ));

        Log::channel('signals')->info('Signal run skipped articles it had already rejected.', [
            'team_id' => (int) $watch->team_id,
            'signal_watch_id' => (int) $watch->getKey(),
            'skipped' => count($items) - count($kept),
        ]);

        return $kept;
    }

    /**
     * Batch the items (≤10) through the extraction prompt and keep the genuine, in-threshold events.
     * A batch whose AI response can't be made schema-valid is logged and skipped — the run continues.
     * On AI-credit exhaustion, stop batching and return what was gathered plus a `creditExhausted`
     * flag and the cost already spent, so the caller can fail the run *and record the spend*.
     *
     * **One classification per story, not per article** (S11, `signals.cluster_before_ai`). The
     * candidate items are grouped by headline overlap first ({@see SignalClusterer::group()}), one
     * representative per group goes to the model, and the verdict is applied to every member of the
     * group. This is the phase's cost win and it is a large one: eight outlets covering one
     * acquisition cost one AI call instead of eight, so spend falls roughly in proportion to how
     * well a story travels. Every member still becomes its own signal row — each is a real article
     * with a real link worth reading — and the clustering is what makes them one card.
     *
     * The thresholds are read from the **representative's** scores, which is the honest reading:
     * the model saw that article, and the members are the same event reported elsewhere. A verdict
     * is remembered against every member ({@see SignalRejectionLedger}), so a round-up's whole
     * coverage is skipped next run rather than just the one copy that was sent.
     *
     * @param  list<SignalDefinition>  $definitions  The types offered to the model for this run.
     * @param  list<SourceItem>  $items
     * @return array{0: list<array{0: ExtractedSignal, 1: SourceItem, 2: SignalDefinition}>, 1: int, 2: bool} kept triples, AI cost, credit-exhausted
     */
    private function extract(SignalWatch $watch, array $definitions, ?Icp $icp, ?BusinessProfile $profile, array $items, string $fingerprint): array
    {
        /** @var array<string, SignalDefinition> $offered */
        $offered = [];
        foreach ($definitions as $definition) {
            $offered[$definition->key] = $definition;
        }

        // **The structured partition** (S13b, ADR 0023 §6). An item from a source that already knows
        // what it is — a Companies House filing, where the registry has parsed the form and handed
        // over the officer's name and the date — skips the extraction batch entirely. Classifying a
        // registry fact with a language model would spend money to make a certainty less certain.
        //
        // Partitioned **before** the clustering, deliberately: `SignalClusterer::group()` exists to
        // pick one representative per story so the AI is paid once for eight outlets, and a
        // pre-classified item has no AI call to save. Grouping them would mean one filing's verdict
        // being applied to another filing that merely has a similar headline — two different
        // appointments at the same company read almost identically.
        [$classified, $unclassified] = $this->partitionByClassification($items);

        $kept = $this->keepClassified($watch, $offered, $classified);

        /** @var array<string, list<SourceItem>> $stories  every member, keyed by its representative */
        $stories = [];
        /** @var list<SourceItem> $representatives */
        $representatives = [];

        foreach ($this->clusterer->group($unclassified) as $story) {
            $representatives[] = $story[0];
            $stories[$story[0]->dedupeKey()] = $story;
        }

        $costMicros = 0;
        /** @var list<string> $notEvent */
        $notEvent = [];
        /** @var list<string> $belowThreshold */
        $belowThreshold = [];

        foreach (array_chunk($representatives, self::BATCH_SIZE) as $batch) {
            try {
                $response = $this->ai->structured('signals.extract_event', [
                    'business_profile' => $this->profileSummary($profile),
                    'location' => implode(', ', $this->watchedPlaces($watch)),
                    // The types, with their names, explainers and the rules that tell this kind of
                    // event from the round-ups that look like it. This is what the enum's three
                    // hard-coded descriptions used to do, now authored per team.
                    'event_types' => $this->eventTypesPrompt($definitions),
                    'event_type_keys' => implode(', ', array_keys($offered)),
                    'icp_geographies' => $this->joinIcp($icp, CriterionType::Geography),
                    'icp_buying_signals' => $this->joinIcp($icp, CriterionType::BuyingSignal),
                    'news_items' => UntrustedContent::from($this->itemsJson($batch)),
                ]);
            } catch (AiCreditExceededException) {
                // No more credit — stop here and report the spend so far. The run fails cleanly.
                Log::channel('signals')->warning('Signal run stopped: AI credit exhausted mid-run.', [
                    'signal_watch_id' => $watch->getKey(),
                ]);

                return [$kept, $costMicros, true];
            } catch (InvalidAiResponseException) {
                // The AI client already retried once; a still-invalid batch is skipped, not fatal.
                Log::channel('signals')->warning('Signal extraction skipped a batch: the AI response was not schema-valid.', [
                    'signal_watch_id' => $watch->getKey(),
                    'batch_size' => count($batch),
                ]);

                continue;
            }

            $costMicros += $response->costMicros;

            /** @var array<array-key, mixed> $events */
            $events = $response->data['events'] ?? [];

            /** @var array<int, true> $answered  the batch positions the model came back about */
            $answered = [];

            foreach ($events as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $extracted = ExtractedSignal::fromAiItem($raw);
                $item = $batch[$extracted->itemIndex] ?? null;

                if (! $item instanceof SourceItem) {
                    continue;
                }

                $answered[$extracted->itemIndex] = true;
                $story = $stories[$item->dedupeKey()] ?? [$item];

                if (! $extracted->isEvent || $extracted->eventKey === null) {
                    // Not one of this team's events at all — a round-up, a listicle. Remembered so
                    // next week's run does not pay to be told the same thing.
                    $notEvent = [...$notEvent, ...$this->keysOf($story)];

                    continue;
                }

                $definition = $this->resolveDefinition($watch, $offered, $extracted->eventKey);

                if (! $definition instanceof SignalDefinition) {
                    // A key we never offered: our prompt's problem, not a verdict about the
                    // article, so it is dropped and logged and **nothing is remembered**. Caching
                    // it would skip an item a corrected prompt would have kept.
                    continue;
                }

                $thresholds = $definition->effectiveThresholds();

                if ($extracted->meetsThreshold($thresholds['min_confidence'], $thresholds['min_relevance'])) {
                    // Every outlet that covered it becomes a row; they are clustered into one card
                    // by `complete()`, which is where the subject and the type are known.
                    foreach ($story as $member) {
                        $kept[] = [$extracted, $member, $definition];
                    }

                    continue;
                }

                $belowThreshold = [...$belowThreshold, ...$this->keysOf($story)];
            }

            // Silence counts — but only from a model that answered something. Leaving an
            // unanswered item unrecorded would have left the commonest rejection the only one this
            // phase still paid for twice; trusting silence from a response that came back **empty**
            // would be worse, because a truncated-but-schema-valid answer would then hide every
            // real event in that batch for thirty days (found in review). A response that named at
            // least one item was read; one that named none tells us nothing about any of them.
            if ($answered !== []) {
                foreach ($batch as $index => $item) {
                    if (isset($answered[$index])) {
                        continue;
                    }

                    $notEvent = [...$notEvent, ...$this->keysOf($stories[$item->dedupeKey()] ?? [$item])];
                }

                continue;
            }

            Log::channel('signals')->warning('Signal extraction returned no verdicts for a batch; remembering nothing.', [
                'team_id' => (int) $watch->team_id,
                'signal_watch_id' => (int) $watch->getKey(),
                'batch_size' => count($batch),
            ]);
        }

        // A story the model answered twice — once as a real event and once not — is a kept story,
        // and must not also be remembered as rejected: the memory would skip it on the next run
        // and quietly lose coverage of an event this run decided was real.
        $keptKeys = array_map(
            static fn (array $triple): string => $triple[1]->dedupeKey(),
            $kept,
        );

        $this->rememberRejections(
            $watch,
            $fingerprint,
            array_values(array_diff($notEvent, $keptKeys)),
            array_values(array_diff($belowThreshold, $keptKeys)),
        );

        return [$kept, $costMicros, false];
    }

    /**
     * Split the run's items into the ones that arrive classified and the ones that need the AI
     * (S13b, ADR 0023 §6).
     *
     * @param  list<SourceItem>  $items
     * @return array{0: list<SourceItem>, 1: list<SourceItem>}
     */
    private function partitionByClassification(array $items): array
    {
        $classified = [];
        $unclassified = [];

        foreach ($items as $item) {
            if ($item->isClassified()) {
                $classified[] = $item;

                continue;
            }

            $unclassified[] = $item;
        }

        return [$classified, $unclassified];
    }

    /**
     * Keep the pre-classified items that clear their own type's floors.
     *
     * **The two guards the AI path applies are applied here too, and that is the point of ADR 0023
     * §6's second rule.** A structured source asserts a confidence; it does not assert that the team
     * wants the event:
     *
     * - `resolveDefinition()` still refuses a key the run did not offer, so a source cannot file an
     *   event under a type this watch is not watching — the same guard that replaced the AI schema's
     *   enum at S7, applied to a source instead of a model.
     * - `meetsThreshold()` still runs against `SignalDefinition::effectiveThresholds()`, so a team
     *   that raised a type's floor is obeyed. A registry filing's asserted 100s clear any floor,
     *   which is correct and worth knowing: a floor raised because the *news* for a type was noisy
     *   will not filter that type's filings.
     *
     * Nothing is remembered in the rejection ledger for these. That ledger exists to avoid paying
     * for the same AI verdict twice, and there was no verdict to pay for — a filing that fails a
     * threshold today and passes it after the floor is lowered should appear, not be suppressed for
     * thirty days by a memory of a judgement nobody made.
     *
     * @param  array<string, SignalDefinition>  $offered
     * @param  list<SourceItem>  $items
     * @return list<array{0: ExtractedSignal, 1: SourceItem, 2: SignalDefinition}>
     */
    private function keepClassified(SignalWatch $watch, array $offered, array $items): array
    {
        $kept = [];
        $dropped = 0;

        foreach ($items as $item) {
            $extracted = $item->classification;

            if (! $extracted instanceof ExtractedSignal) {
                continue;
            }

            $definition = $this->resolveDefinition($watch, $offered, $extracted->eventKey);

            if (! $definition instanceof SignalDefinition) {
                continue;
            }

            $thresholds = $definition->effectiveThresholds();

            if (! $extracted->meetsThreshold($thresholds['min_confidence'], $thresholds['min_relevance'])) {
                $dropped++;

                continue;
            }

            // One item, one signal. There is no story to fan out across: a filing is not reported by
            // eight outlets, and `complete()` will still cluster these rows by subject and week like
            // any other — two appointments at one company in one week are one card.
            $kept[] = [$extracted, $item, $definition];
        }

        if ($items !== []) {
            Log::channel('signals')->info('Signal run kept pre-classified items without an AI call.', [
                'team_id' => (int) $watch->team_id,
                'signal_watch_id' => (int) $watch->getKey(),
                'offered' => count($items),
                'kept' => count($kept),
                'below_threshold' => $dropped,
            ]);
        }

        return $kept;
    }

    /**
     * The dedupe identities of a story's members.
     *
     * @param  list<SourceItem>  $story
     * @return list<string>
     */
    private function keysOf(array $story): array
    {
        return array_map(static fn (SourceItem $item): string => $item->dedupeKey(), $story);
    }

    /**
     * Write this run's verdicts to the rejection ledger (S11).
     *
     * Outside the persistence transaction, like every other non-essential write in this Action: a
     * memory that failed to save costs a repeated classification next week, and that is not worth
     * rolling back the signals this run actually found. Logged as counts.
     *
     * `below_threshold` is written after `not_event`, so an article the model called a real event in
     * one batch is not left recorded as a non-event because a different batch was silent about it.
     *
     * @param  list<string>  $notEvent
     * @param  list<string>  $belowThreshold
     */
    private function rememberRejections(SignalWatch $watch, string $fingerprint, array $notEvent, array $belowThreshold): void
    {
        if (! $this->rejections->enabled() || ($notEvent === [] && $belowThreshold === [])) {
            return;
        }

        $teamId = (int) $watch->team_id;
        $below = array_values(array_unique($belowThreshold));
        $not = array_values(array_diff(array_unique($notEvent), $below));

        $this->rejections->remember($teamId, $fingerprint, $not, SignalRejectionVerdict::NotEvent);
        $this->rejections->remember($teamId, $fingerprint, $below, SignalRejectionVerdict::BelowThreshold);

        Log::channel('signals')->info('Signal run remembered what it rejected.', [
            'team_id' => $teamId,
            'signal_watch_id' => (int) $watch->getKey(),
            'not_event' => count($not),
            'below_threshold' => count($below),
        ]);
    }

    /**
     * The definition a returned `event_type` names — **only** if it was one offered for this run.
     *
     * This is the guard that replaced the JSON schema's `enum` (ADR 0018 §7). An unoffered key is
     * either a hallucination or a response somebody steered, and in both cases the item is dropped
     * rather than written under a type the team never asked to watch for. Logged as a count with
     * the key, because a type the model keeps inventing is a signal about the prompt — but never
     * with the article's text.
     *
     * @param  array<string, SignalDefinition>  $offered
     */
    private function resolveDefinition(SignalWatch $watch, array $offered, ?string $key): ?SignalDefinition
    {
        if ($key === null) {
            return null;
        }

        if (isset($offered[$key])) {
            return $offered[$key];
        }

        Log::channel('signals')->warning('Signal extraction returned a signal type that was not offered; item dropped.', [
            'team_id' => (int) $watch->team_id,
            'signal_watch_id' => (int) $watch->getKey(),
            'event_key' => $key,
        ]);

        return null;
    }

    /**
     * The watched types as the prompt reads them: one block per type with its key, name, explainer
     * and its include/exclude rules.
     *
     * Trusted input — a team's own wording about their own business, the same trust the business
     * profile gets. The article text is the untrusted half and is wrapped separately.
     *
     * @param  list<SignalDefinition>  $definitions
     */
    private function eventTypesPrompt(array $definitions): string
    {
        $blocks = [];

        foreach ($definitions as $definition) {
            $lines = ['- '.$definition->key.' ('.$definition->name.'): '.$definition->description];

            $includes = $definition->includes();
            if ($includes !== []) {
                $lines[] = '  Counts as this type: '.implode('; ', $includes).'.';
            }

            $excludes = $definition->excludes();
            if ($excludes !== []) {
                $lines[] = '  Does NOT count: '.implode('; ', $excludes).'.';
            }

            $hint = $definition->suggested_approach_hint;
            if ($hint !== null && trim($hint) !== '') {
                $lines[] = '  Approach hint: '.trim($hint);
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n", $blocks);
    }

    /**
     * The queries this run will make, and where the next entity run should start.
     *
     * A topical watch is unchanged: definitions × places × regions, trimmed to the ceiling. An
     * entity watch takes the **next window** of its list — `signals.watchlist.window` accounts
     * starting at the watch's cursor, wrapping round — so a 250-account pipeline is covered over
     * days rather than its first forty being searched every day and the rest never (ADR 0021).
     *
     * @param  list<SignalDefinition>  $definitions
     * @return array{0: SignalQueryPlan, 1: int|null} the plan, and the cursor to store on success
     */
    private function planFor(SignalWatch $watch, array $definitions, ?Icp $icp): array
    {
        $watchlist = $watch->watchlist;

        if (! $watchlist instanceof SignalWatchlist || ! $watchlist->is_active) {
            // Topical, or pointed at a list somebody has paused. A paused list searches nothing
            // rather than falling back to places: the member said what they wanted watched.
            if ($watch->signal_watchlist_id !== null) {
                return [new SignalQueryPlan([], 0), null];
            }

            return [$this->queries->build($watch, $definitions, $icp), null];
        }

        /** @var list<SignalWatchlistEntry> $all */
        $all = $watchlist->activeEntries()->get()->all();

        if ($all === []) {
            return [new SignalQueryPlan([], 0), null];
        }

        $cursor = max(0, (int) $watch->entity_cursor) % count($all);

        // Wraps, so the list is a ring rather than a queue that empties: the 249th account is
        // searched on day six and the first again on day seven.
        $slice = array_merge(array_slice($all, $cursor), array_slice($all, 0, $cursor));

        // **Registry numbers first, and before the window is costed** (S13b). A Companies House
        // query is addressed by company number, so an entity without one contributes no query and
        // costs nothing — which means the window has to be filled *after* the numbers are known or
        // its arithmetic would reserve budget for requests that will never be made.
        //
        // **Handed the ring slice rather than the whole list** (S13b review, finding 6). Its own
        // ceiling is ten searches a run, and over `$all` it spent them on the first ten unresolved
        // entries of the list every time — which, from run two onwards, are entries this run is not
        // going to search, while the accounts in today's window went without a number and so
        // contributed no registry query at all. In ring order the searches land on the accounts
        // about to be searched, and the list resolves itself a window at a time as the cursor
        // travels. The chicken-and-egg (the window's cost depends on who has a number, and who has
        // a number depends on what we resolve) is settled the same way: resolve in the order the
        // ring will read, then cost.
        //
        // Here rather than in the builder, which does no I/O (that is what lets the cost estimator
        // and S9's preview build a real plan for nothing), and not in the provider, which must not
        // write to a tenant's tables. Only runs when a subject-addressed definition is in play, so
        // a team that watches news alone never touches the registry.
        if ($this->addressesASubject($definitions)) {
            $this->companyNumbers->handle((int) $watch->team_id, $slice);
        }

        // **Taken by cost, not by count** — a review finding, and the exact failure the ring exists
        // to prevent. One entity is one query *per region* (and, for a person, per definition), so
        // forty entities over two regions build eighty queries and the plan's trim keeps forty: the
        // last twenty entities were never searched, yet a cursor advanced by "entities taken" would
        // step past them and lose them on every lap. So the window is filled until the next entity
        // would not fit inside `max_queries_per_run`, and the cursor advances by the entities that
        // actually fit. `signals.watchlist.window` stays as an upper bound on top of that, because
        // a member who wants a cheaper watch should be able to say so.
        $entities = [];
        $window = max(1, (int) config('signals.watchlist.window', 40));

        // **What is left after the definition-addressed queries, not the whole ceiling** (S13b
        // review, finding 7). A feed and the webhook contribute one query each per definition,
        // `pushDefinitionQueries()` emits them **first**, and the plan's trim keeps the first N —
        // so a window filled to exactly `max_queries_per_run` had the last entity's queries trimmed
        // off the end while the cursor stepped past that entity anyway. Silently, on every lap:
        // precisely the failure the ring exists to prevent, arriving through the one door it did
        // not watch. Floored at 1 so a pathological number of feeds cannot stop the ring entirely.
        $budget = max(1, (int) config('signals.max_queries_per_run', 40)
            - $this->queries->definitionQueryCount($definitions));
        $regions = max(1, count(SignalQuerySpec::forEntities($watch, [])->regions));
        $spent = 0;

        foreach ($slice as $entry) {
            if (count($entities) >= $window) {
                break;
            }

            $entity = SignalQueryEntity::fromEntry($entry);
            $cost = count($entity->names) * $regions * ($entity->searchableAlone() ? 1 : max(1, count($definitions)));

            // Plus the one subject-addressed query this entity earns, where it has a number to be
            // addressed by (S13b). One per entity whatever the names and regions, because the
            // registry holds one record per company — so it is a `+1` and not a multiplier, and
            // leaving it out of the budget would let the ring over-fill and the trim bite.
            if ($entity->hasCompanyNumber() && $this->addressesASubject($definitions)) {
                $cost++;
            }

            // Always take the first one, however expensive: an entity nobody can afford would
            // otherwise block the ring forever and the list would go quiet with no explanation.
            if ($entities !== [] && $spent + $cost > $budget) {
                break;
            }

            $entities[] = $entity;
            $spent += $cost;
        }

        return [
            $this->queries->buildFromSpec(SignalQuerySpec::forEntities($watch, $entities), $definitions),
            ($cursor + count($entities)) % count($all),
        ];
    }

    /**
     * Whether any of this run's types asks for a source that addresses a **subject** rather than
     * running a search (S13b) — today, Companies House.
     *
     * Asked before the registry is touched at all, so a team watching news alone never makes a
     * Companies House request and never pays a number-resolution search.
     *
     * **Asks `addressesSubject()` and not `! isQueryBased()`**, which is what it asked first and
     * was wrong: a feed and the webhook are neither query-based nor subject-addressed, so a
     * watchlist whose types named a feed added a `+1` per entity to the window's cost for a
     * registry query that was never emitted — paying for the registry out of the entity budget
     * without using it. The same conflation put a feed query behind every template; see
     * `SignalQueryBuilder::push()`.
     *
     * @param  list<SignalDefinition>  $definitions
     */
    private function addressesASubject(array $definitions): bool
    {
        foreach ($definitions as $definition) {
            foreach ($definition->sourceKinds() as $kind) {
                if ($kind->addressesSubject() && $this->sources->has($kind)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Match this run's new signals to the team's own leads, and record what matched.
     *
     * Runs **inside** the transaction that inserted them, so a lead whose timeline says an event
     * happened always has the signal behind it. Scoped to this run, which makes it idempotent for
     * free: a re-run inserts nothing, so there is nothing to announce twice. Since S11 it is also
     * scoped to the rows that **represent an event**, so one event about an account is one entry on
     * that lead however many outlets ran it.
     *
     * The notification is raised after commit, aggregated per run, and addressed to the lead's
     * owners — never `$user->notify()` and never one per signal (CLAUDE.md).
     */
    private function recordMatches(SignalRun $run, SignalWatch $watch): void
    {
        /** @var list<Signal> $matched */
        $matched = Signal::query()
            ->where('signal_run_id', $run->getKey())
            ->whereNotNull('matched_lead_id')
            // **One entry per event, not per article** (S11). Eight outlets covering the sale of a
            // company the team is working all match the same lead, and without this the lead's
            // timeline would say the same thing eight times in one morning — which is the feed's
            // old defect moved somewhere a member cannot dismiss it.
            ->representatives()
            ->with('matchedLead')
            ->get()
            ->all();

        if ($matched === []) {
            return;
        }

        foreach ($matched as $signal) {
            $lead = $signal->matchedLead;

            if (! $lead instanceof Lead) {
                continue;
            }

            $this->activity->handle(
                lead: $lead,
                type: LeadActivityType::SignalMatched,
                subject: $signal,
                properties: [
                    'signal_id' => (int) $signal->getKey(),
                    'event_type' => $signal->event_key,
                    // A value, not a sentence: the wording is composed at render time (EN/AR).
                    'headline' => Str::limit($signal->headline, 160),
                    'confidence' => $signal->match_confidence?->value,
                ],
                // No actor: nobody did this. A scheduled watch found it, and a timeline entry
                // attributed to whoever happens to be logged in would be a small lie.
                actor: null,
            );
        }

        $teamId = (int) $run->team_id;
        $runId = (int) $run->getKey();
        $count = count($matched);

        DB::afterCommit(static function () use ($teamId, $runId, $count): void {
            event(new AccountSignalsDetected($teamId, $runId, $count));
        });

        Log::channel('signals')->info('Signals matched to existing leads.', [
            'team_id' => $teamId,
            'signal_watch_id' => (int) $watch->getKey(),
            'run_id' => $runId,
            'matched' => $count,
        ]);
    }

    /**
     * Persist the kept signals + the run totals in one transaction. `insertOrIgnore` on
     * `(team_id, dedupe_hash)` makes a re-run idempotent and never overwrites an existing (possibly
     * converted) signal. Fires {@see SignalsDetected} after commit.
     *
     * **The clusters are written here** (S11), because this is the first point at which an event
     * has both a type and a subject — which is what a cluster key is made of. The grouping done
     * before the AI batch was provisional and lived for one run; these rows are what make a story
     * covered again tomorrow join the card it already has.
     *
     * @param  list<array{0: ExtractedSignal, 1: SourceItem, 2: SignalDefinition}>  $kept
     */
    private function complete(SignalRun $run, SignalWatch $watch, array $kept, int $queriesCount, int $resultsCount, int $costMicros, int $queriesRequested = 0, ?int $nextCursor = null, ?SignalSourceBreakdown $breakdown = null): SignalRun
    {
        // De-duplicate by the dedupe identity within this run, keeping the highest-confidence
        // result when the model returns more than one for the same article.
        $best = [];
        foreach ($kept as [$extracted, $item, $definition]) {
            $existing = $best[$item->dedupeKey()][0] ?? null;
            if (! $existing instanceof ExtractedSignal || $extracted->confidence > $existing->confidence) {
                $best[$item->dedupeKey()] = [$extracted, $item, $definition];
            }
        }

        $rows = [];
        foreach ($best as [$extracted, $item, $definition]) {
            $rows[] = $this->toRow($run, $watch, $extracted, $item, $definition);
        }

        $created = DB::transaction(function () use ($run, $watch, $rows, $queriesCount, $resultsCount, $costMicros, $queriesRequested, $nextCursor, $breakdown): int {
            [$rows, $clusters] = $this->cluster((int) $watch->team_id, $rows);

            $created = $rows === [] ? 0 : Signal::query()->insertOrIgnore($rows);

            // The counters on each touched cluster, recomputed from the rows that now exist (S11).
            // Inside the same transaction as the insert, because a cluster saying "6 sources" over
            // a card that can only show five is the one inconsistency a member would notice.
            //
            // **Before the timeline entries, deliberately.** A freshly created cluster has no
            // primary until this runs, and `representatives()` treats a cluster with no primary as
            // "show every member" — so recording the matches first would write one entry per
            // article again, which is the defect the scope exists to prevent.
            foreach ($clusters as $cluster) {
                $this->clusterer->reconcile($cluster);
            }

            // The heat, scored once the clusters are reconciled (S12) — because breadth is one of
            // the eight components and a cluster does not know how many outlets it holds until
            // then. In the same transaction as the insert, so a signal is never visible unscored:
            // the feed's default order is heat, and a row that appeared at the bottom of it for a
            // moment and then jumped would read as a bug.
            if ($created > 0) {
                $this->scoreRun($run, $watch, $clusters);
            }

            // The timeline entries for signals matched to a lead, written **inside** the same
            // transaction as the signals themselves: a lead whose timeline says an event happened
            // and a feed with no such signal in it would be a worse state than neither.
            //
            // Scoped to this run, which is what makes it idempotent for free: a re-run inserts
            // nothing (`insertOrIgnore` on `(team_id, dedupe_hash)`), so there is nothing new to
            // announce and no second entry to write.
            if ($created > 0) {
                $this->recordMatches($run, $watch);
            }

            // **`signals_created` counts events, not rows** (S11). Eight outlets covering one
            // acquisition write eight rows and add one card, and "signals created: 8" on the watch
            // card over one card in the feed is the discrepancy this phase exists to remove — so
            // the number everybody reads is the number of cards. The raw article count is still
            // `results_count`, and what the run cost is still `cost_micros`; the row count is not
            // something anybody reads.
            //
            // Counted **after** the reconcile, which is what makes "new coverage of an event the
            // team already has" correctly count as nothing new.
            $events = $created === 0 ? 0 : Signal::query()
                ->where('team_id', $watch->team_id)
                ->where('signal_run_id', $run->getKey())
                ->representatives()
                ->count();

            $run->forceFill([
                'status' => SignalRunStatus::Completed,
                'queries_count' => $queriesCount,
                // What the plan wanted, so the watch card can say coverage was trimmed (S8). A
                // silent trim is a lie about what was searched.
                'queries_requested' => $queriesRequested,
                'results_count' => $resultsCount,
                'signals_created' => $events,
                'cost_micros' => $costMicros,
                // Per source, beneath the total (S13). Null rather than `{}` for a run that
                // searched nothing — a watch whose every type was paused, or one trimmed to
                // nothing — because an empty object reads as "we looked and spent nothing", and
                // "we did not look" is a different sentence.
                'source_breakdown' => $breakdown === null || $breakdown->isEmpty()
                    ? null
                    : $breakdown->toArray(),
                'error' => null,
                'started_at' => $run->started_at ?? now(),
                'finished_at' => now(),
            ])->save();

            // Where the next entity run starts, written **only** when this run was entity-scoped:
            // a topical run has no cursor to advance, and a freshly loaded model may not have the
            // column hydrated at all, so writing it unconditionally would try to store null in a
            // NOT NULL column. Advanced on completion only, so a failed run does not skip the
            // accounts it never got to.
            $watch->forceFill(array_filter(
                ['last_run_at' => now(), 'entity_cursor' => $nextCursor],
                static fn (mixed $value): bool => $value !== null,
            ))->save();

            $teamId = (int) $run->team_id;
            $runId = (int) $run->getKey();
            DB::afterCommit(static function () use ($teamId, $runId, $events): void {
                // Announced only when there is a **card** to look at. A run whose every new row
                // joined an event the team already has has found nothing new to tell them about,
                // and "3 new signals" leading to a feed that looks unchanged is worse than silence.
                if ($events > 0) {
                    event(new SignalsDetected($teamId, $runId, $events));
                }
            });

            return $events;
        });

        // --- External work again: the full-article read (outside the transaction) ---
        //
        // **After the commit, deliberately**, and the ordering is the whole reason this is not in
        // `complete()`'s transaction: it makes HTTP calls to publishers and an AI call per article.
        // The signals are already saved and already scored, so everything here failing leaves the
        // product behaving exactly as it did in S11 — snippet-only, which is what every signal was
        // until this phase.
        //
        // Off by default (`signals.article_fetch.enabled`); the enricher returns zero without
        // touching anything when it is.
        // Wrapped, and not because the enricher is careless: this call sits **after** the run has
        // been committed as Completed, so anything escaping it fails an already-finished job —
        // which retries, re-pays for the Serper search and alerts somebody about a run that
        // actually succeeded. The enricher guards each article itself; this guards the guard.
        try {
            $fetchCost = $this->articles->enrich($run, (int) $watch->team_id);
        } catch (Throwable $e) {
            Log::channel('signals')->warning('Article enrichment failed after the run completed.', [
                'team_id' => (int) $watch->team_id,
                'signal_run_id' => (int) $run->getKey(),
                'error' => $e::class,
            ]);

            $fetchCost = 0;
        }

        if ($fetchCost > 0) {
            // Metered onto the run like every other spend this module makes, so one number still
            // answers "what did this run cost".
            $run->forceFill(['cost_micros' => (int) $run->cost_micros + $fetchCost])->save();
        }

        $this->broadcast($run, SignalRunStatus::Completed, 100, 'Done', $created);

        return $run->refresh();
    }

    /**
     * Score this run's new signals, and re-score the primaries of the clusters it touched (S12).
     *
     * **Two sets, and the second one is the easy thing to miss.** The new rows obviously need a
     * heat. But a run that only added coverage of an event the team already has has changed that
     * event's *breadth*, which is one of the scorer's components — so the existing primary's heat
     * is now stale, and a feed ordered by heat would keep ranking a six-outlet story as the
     * one-outlet story it was yesterday. Re-scoring the touched clusters' primaries is what keeps
     * the order honest.
     *
     * The definitions are looked up once and passed in: the scorer wants the per-type weight
     * overrides, and reading them off each signal's relation would be a query per card with
     * `preventLazyLoading` on to catch it.
     *
     * @param  list<SignalCluster>  $clusters
     */
    private function scoreRun(SignalRun $run, SignalWatch $watch, array $clusters): void
    {
        $teamId = (int) $watch->team_id;

        /** @var array<int, SignalCluster> $byId */
        $byId = [];
        foreach ($clusters as $cluster) {
            $byId[(int) $cluster->getKey()] = $cluster;
        }

        /** @var array<int, SignalDefinition> $definitions */
        $definitions = [];
        foreach ($this->definitions($watch) as $definition) {
            $definitions[(int) $definition->getKey()] = $definition;
        }

        $primaries = array_values(array_filter(array_map(
            static fn (SignalCluster $cluster): ?int => $cluster->primary_signal_id === null
                ? null
                : (int) $cluster->primary_signal_id,
            $clusters,
        )));

        Signal::query()
            ->where('team_id', $teamId)
            ->where(static function ($query) use ($run, $primaries): void {
                $query->where('signal_run_id', $run->getKey());

                if ($primaries !== []) {
                    $query->orWhereIn('id', $primaries);
                }
            })
            ->chunkById(200, function ($signals) use ($byId, $definitions): void {
                foreach ($signals as $signal) {
                    $definition = $signal->signal_definition_id === null
                        ? null
                        : ($definitions[(int) $signal->signal_definition_id] ?? null);

                    $cluster = $signal->signal_cluster_id === null
                        ? null
                        : ($byId[(int) $signal->signal_cluster_id] ?? null);

                    $score = $this->scorer->score($signal, $definition, $cluster);

                    $signal->forceFill([
                        'heat' => $score->heat,
                        'score_breakdown' => $score->toBreakdown(),
                    ])->save();
                }
            });
    }

    /**
     * Point each row at the event it is coverage of, creating the clusters it needs (S11).
     *
     * **Oldest first**, which is not cosmetic. A cluster key is looked up against its own ISO week
     * *and* the week before ({@see SignalClusterer::candidateKeysFor()}), so a story that breaks on
     * a Sunday and is covered on the Monday is one event rather than two — and that only works if
     * the earlier article is filed first. Processing in publication order makes the result the same
     * whichever order the provider happened to return them in.
     *
     * A row with no clusterable subject keeps a null `signal_cluster_id` and stays its own card;
     * {@see SignalClusterer::clusterKeyFor()} is where that decision lives.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<SignalCluster>}
     */
    private function cluster(int $teamId, array $rows): array
    {
        if ($rows === []) {
            return [[], []];
        }

        usort($rows, static fn (array $a, array $b): int => ($a['published_at'] <=> $b['published_at']));

        /** @var array<int, SignalCluster> $clusters */
        $clusters = [];

        foreach ($rows as $index => $row) {
            $published = $row['published_at'];
            $company = $row['subject_company_name'];

            if (! $published instanceof DateTimeInterface) {
                continue;
            }

            $cluster = $this->clusterer->ensure(
                teamId: $teamId,
                eventKey: (string) $row['event_key'],
                definitionId: is_int($row['signal_definition_id'] ?? null) ? $row['signal_definition_id'] : null,
                fingerprint: $this->clusterer->subjectFingerprint(is_string($company) ? $company : null),
                publishedAt: $published,
            );

            if (! $cluster instanceof SignalCluster) {
                continue;
            }

            $rows[$index]['signal_cluster_id'] = $cluster->getKey();
            $clusters[(int) $cluster->getKey()] = $cluster;
        }

        return [$rows, array_values($clusters)];
    }

    /**
     * Build the DB-ready row for a kept signal. Written as a raw row (not via the model) because
     * `insertOrIgnore` is a bulk statement — so enums, JSON and the UTC published date are serialised
     * here. The news item's title is the factual headline; the AI summary is separate. `published_at`
     * falls back to the run time when Serper gave no parseable date.
     *
     * Both the definition's id **and** its key are written: the id is the live attribution and the
     * key is what survives the definition being deleted (ADR 0018 §4). The key comes off the
     * resolved definition rather than off the model's answer, so a row can never carry a key the
     * run did not offer.
     *
     * @return array<string, mixed>
     */
    private function toRow(SignalRun $run, SignalWatch $watch, ExtractedSignal $extracted, SourceItem $item, SignalDefinition $definition): array
    {
        $now = now();

        // The watch's regions are the **tiebreak**, not a default: they only decide when the
        // article names more than one place the map knows ("UK founder relocates to Dubai" names
        // both, and a watch over Dubai means the Dubai end).
        $regions = $watch->searchRegions();
        $jurisdiction = $this->jurisdictions->resolve($extracted->location, $regions);
        $resolvedMarket = $jurisdiction === null
            ? null
            : SignalJurisdictionResolver::marketFor($jurisdiction);

        // Is this event about a company the team already has? (S10) Answered **here**, while the
        // row is being built, so a signal is never stored unmatched and then corrected — the match
        // is one of its own columns from birth. The matcher errs toward no match by design; a null
        // here means the signal behaves exactly as it did before this phase.
        $match = $this->matcher->match(
            (int) $watch->team_id,
            $extracted->subjectCompanyName,
            $extracted->counterpartyName,
            $extracted->subjectPersonName,
        );

        return [
            'team_id' => $watch->team_id,
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
            'matched_lead_id' => $match?->lead->getKey(),
            'match_confidence' => $match?->confidence->value,
            'signal_definition_id' => $definition->getKey(),
            // Filled by `cluster()` once the key can be computed; null means this event names no
            // company and stays its own card.
            'signal_cluster_id' => null,
            'event_key' => $definition->key,
            'status' => SignalStatus::New->value,
            'headline' => Str::limit($item->title, 255, ''),
            'summary' => $extracted->summary,
            'subject_person_name' => $extracted->subjectPersonName,
            'subject_person_role' => $extracted->subjectPersonRole,
            'subject_company_name' => $extracted->subjectCompanyName,
            'counterparty_name' => $extracted->counterpartyName,
            'amount_minor' => $extracted->amountMinor,
            'currency' => $extracted->currency?->value,
            'location' => $extracted->location,
            // **Resolved here, at extraction, and never recomputed** (S8, ADR 0019). This is the
            // column that decides which outreach restriction a converted lead carries, so it is a
            // deterministic place-map lookup rather than anything inferred — and it is stored as
            // the answer that was given, because a lead's restriction must not change later
            // because somebody tidied the lookup table.
            'jurisdiction' => $jurisdiction,
            'resolved_market' => $resolvedMarket?->value,
            'confidence' => $extracted->confidence,
            'relevance' => $extracted->relevance,
            'suggested_approach' => $extracted->suggestedApproach,
            'source_url' => $item->link,
            'source_name' => $item->source,
            // Which source found it, and which of the type's templates did (S13). The kind comes off
            // the **item** rather than off the query, because an article returned by two sources is
            // de-duplicated to one row and the attribution has to follow the row that survived.
            //
            // `matched_template` is nullable and null is a real answer: a signal from a source that
            // does not search by keyword has no template behind it, and the explanation panel says
            // so rather than inventing one.
            'source_kind' => $item->kind->value,
            'matched_template' => $item->matchedTemplate,
            'published_at' => ($item->publishedAt ?? $now)->copy()->utc(),
            'url_hash' => $item->urlHash,
            // The two identities S11 added. `url_hash` is still the truth about the link;
            // `dedupe_hash` is what the unique index enforces and what a re-run compares, and
            // behind a redirector whose token varies it is the content hash rather than the URL's
            // (`docs/modules/signals.md`, "Known limitations", consequence 1).
            'content_hash' => $item->contentHash,
            'dedupe_hash' => $item->dedupeKey(),
            'raw' => json_encode($item->raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * The places this watch covers, for the prompt's "watched location" line.
     *
     * The model geo-qualifies against this, so it has to be every place rather than the first:
     * a watch over Dubai **and** London that only declared Dubai would have every London event
     * marked irrelevant by the thing we asked to filter them.
     *
     * @return list<string>
     */
    private function watchedPlaces(SignalWatch $watch): array
    {
        $places = [];

        foreach ($watch->locations ?? [] as $place) {
            if (trim($place) !== '') {
                $places[] = trim($place);
            }
        }

        if ($places !== []) {
            return $places;
        }

        foreach ($watch->searchRegions() as $region) {
            foreach ($region->defaultLocations() as $place) {
                $places[] = $place;
            }
        }

        return $places;
    }

    /**
     * The team's active ICP (with criteria eager-loaded), or null. Scoped explicitly by `team_id`
     * (beside the global scope) so another team's ICP can never leak into the prompt.
     */
    private function activeIcp(int $teamId): ?Icp
    {
        return Icp::query()->with('criteria')->where('team_id', $teamId)->where('is_active', true)->first();
    }

    /**
     * A short, plain summary of the team's business for the prompt (trusted input).
     */
    private function profileSummary(?BusinessProfile $profile): string
    {
        if (! $profile instanceof BusinessProfile) {
            return 'No business profile on record.';
        }

        $parts = array_filter([
            $profile->company_name,
            $profile->industry,
            $profile->value_proposition,
            $profile->products_services !== null ? implode(', ', $profile->products_services) : null,
        ], static fn (?string $v): bool => $v !== null && trim($v) !== '');

        return $parts === [] ? 'No business profile on record.' : implode(' — ', $parts);
    }

    /**
     * Comma-join an ICP's values of a given criterion type, or an em-dash when there are none.
     */
    private function joinIcp(?Icp $icp, CriterionType $type): string
    {
        if (! $icp instanceof Icp) {
            return '—';
        }

        $values = $icp->criteria
            ->filter(static fn (IcpCriterion $c): bool => $c->type === $type)
            ->map(static fn (IcpCriterion $c): string => trim($c->value))
            ->filter(static fn (string $v): bool => $v !== '')
            ->values()
            ->all();

        return $values === [] ? '—' : implode(', ', $values);
    }

    /**
     * Render a batch as a compact JSON array with an item_index per item, for the prompt. This is the
     * untrusted content (title/snippet/source/date), wrapped by the caller.
     *
     * @param  list<SourceItem>  $batch
     */
    private function itemsJson(array $batch): string
    {
        $out = [];
        foreach ($batch as $index => $item) {
            $out[] = [
                'item_index' => $index,
                'title' => $item->title,
                'snippet' => $item->snippet,
                'source' => $item->source,
                'date' => $item->publishedAt?->toDateString(),
            ];
        }

        return (string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Settle a run as Failed, recording the spend already incurred (so a failed run is still metered)
     * and advancing the watch's `last_run_at` — so a persistently failing watch (e.g. a team out of
     * AI credit) backs off to its cadence instead of being re-dispatched every hour.
     */
    private function fail(SignalRun $run, SignalWatch $watch, string $message, int $queriesCount = 0, int $resultsCount = 0, int $costMicros = 0, int $queriesRequested = 0, ?SignalSourceBreakdown $breakdown = null): SignalRun
    {
        DB::transaction(function () use ($run, $watch, $message, $queriesCount, $resultsCount, $costMicros, $queriesRequested, $breakdown): void {
            $run->forceFill([
                'status' => SignalRunStatus::Failed,
                'error' => $message,
                'queries_count' => $queriesCount,
                'queries_requested' => $queriesRequested,
                'results_count' => $resultsCount,
                'cost_micros' => $costMicros,
                // Recorded on a **failed** run too: a plan can span sources now, so the one that
                // failed is not necessarily the one that spent the money, and an operator asking
                // "what did this failure cost" needs the answer per source.
                'source_breakdown' => $breakdown === null || $breakdown->isEmpty()
                    ? null
                    : $breakdown->toArray(),
                'started_at' => $run->started_at ?? now(),
                'finished_at' => now(),
            ])->save();

            $watch->forceFill(['last_run_at' => now()])->save();
        });

        Log::channel('signals')->warning('Signal run failed.', [
            'team_id' => (int) $run->team_id,
            'signal_watch_id' => (int) $watch->getKey(),
            'run_id' => (int) $run->getKey(),
            'cost_micros' => $costMicros,
        ]);

        $this->broadcast($run, SignalRunStatus::Failed, 100, 'Signals run failed');

        return $run->refresh();
    }

    private function broadcast(SignalRun $run, SignalRunStatus $status, int $percent, string $step, int $signalsCreated = 0): void
    {
        event(new SignalRunProgressed(
            (int) $run->team_id,
            (int) $run->signal_watch_id,
            (int) $run->getKey(),
            $status,
            $percent,
            $step,
            $signalsCreated,
        ));
    }
}
