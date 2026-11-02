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
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Data\NewsItem;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Events\SignalRunProgressed;
use App\Modules\Signals\Events\SignalsDetected;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs one signal watch end to end (ADR 0011, S3). Must run inside the watch's {@see TeamContext}
 * (the job does this). In order: open/claim the run (Running), build queries from the watch + active
 * ICP and search Serper, drop already-seen articles, batch the rest through the `signals.extract_event`
 * prompt (each item wrapped as {@see UntrustedContent}), keep genuine events clearing the confidence
 * and relevance floors, then persist the kept signals + the run totals in one transaction (idempotent
 * via `insertOrIgnore` on `(team_id, url_hash)`). Serper and the AI are **never** called inside a
 * transaction (CLAUDE.md). A non-retryable provider failure or AI-credit exhaustion fails the run
 * cleanly; a transient Serper failure bubbles so the job can retry.
 */
final class RunSignalWatchAction
{
    private const int BATCH_SIZE = 10;

    public function __construct(
        private readonly SignalQueryBuilder $queries,
        private readonly NewsSearchClient $news,
        private readonly AiClient $ai,
        private readonly CapabilityGate $capabilities,
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

        $icp = $this->activeIcp($watch->team_id);
        $profile = BusinessProfile::query()->where('team_id', $watch->team_id)->first();

        $queries = $this->queries->build($watch, $icp);
        $queriesCount = count($queries);
        $serperCostMicros = $queriesCount * (int) config('signals.cost_micros_per_request', 1_000);

        // --- External work: Serper search (outside any transaction) ---
        try {
            [$items, $resultsCount] = $this->search($queries);
        } catch (SignalsException $e) {
            if ($e->isRetryable()) {
                throw $e; // bubble → the job retries with backoff
            }

            // Misconfig/driver can never succeed; no paid request ran (the guard throws first).
            return $this->fail($run, $watch, $e->getMessage(), $queriesCount, 0, 0);
        }

        // Drop articles this team has already seen, so a re-run does no AI work on them.
        $items = $this->dropAlreadySeen($watch->team_id, $items);

        $this->broadcast($run, SignalRunStatus::Running, 55, 'Analysing what we found');

        // --- External work: AI extraction (outside any transaction) ---
        [$kept, $aiCostMicros, $creditExhausted] = $this->extract($watch, $icp, $profile, $items);
        $costMicros = $serperCostMicros + $aiCostMicros;

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
     * Run every query, flatten, and de-duplicate by `url_hash` within this run.
     *
     * @param  list<SignalQuery>  $queries
     * @return array{0: list<NewsItem>, 1: int} unique items and the raw result count across queries
     */
    private function search(array $queries): array
    {
        $unique = [];
        $rawCount = 0;

        foreach ($queries as $query) {
            foreach ($this->news->search($query) as $item) {
                $rawCount++;
                $unique[$item->urlHash] = $item;
            }
        }

        return [array_values($unique), $rawCount];
    }

    /**
     * Remove items whose `url_hash` already exists for this team, so a re-run never re-processes an
     * article. Scoped explicitly by `team_id` (belt-and-braces beside the global scope), so one team
     * can never dedupe against another's signals even if ever called outside TeamContext.
     *
     * @param  list<NewsItem>  $items
     * @return list<NewsItem>
     */
    private function dropAlreadySeen(int $teamId, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $hashes = array_map(static fn (NewsItem $i): string => $i->urlHash, $items);

        $seen = Signal::query()
            ->where('team_id', $teamId)
            ->whereIn('url_hash', $hashes)
            ->pluck('url_hash')
            ->all();

        if ($seen === []) {
            return $items;
        }

        $seen = array_flip($seen);

        return array_values(array_filter($items, static fn (NewsItem $i): bool => ! isset($seen[$i->urlHash])));
    }

    /**
     * Batch the items (≤10) through the extraction prompt and keep the genuine, in-threshold events.
     * A batch whose AI response can't be made schema-valid is logged and skipped — the run continues.
     * On AI-credit exhaustion, stop batching and return what was gathered plus a `creditExhausted`
     * flag and the cost already spent, so the caller can fail the run *and record the spend*.
     *
     * @param  list<NewsItem>  $items
     * @return array{0: list<array{0: ExtractedSignal, 1: NewsItem}>, 1: int, 2: bool} kept pairs, AI cost, credit-exhausted
     */
    private function extract(SignalWatch $watch, ?Icp $icp, ?BusinessProfile $profile, array $items): array
    {
        $minConfidence = (int) config('signals.min_confidence', 60);
        $minRelevance = (int) config('signals.min_relevance', 40);

        $kept = [];
        $costMicros = 0;

        foreach (array_chunk($items, self::BATCH_SIZE) as $batch) {
            try {
                $response = $this->ai->structured('signals.extract_event', [
                    'business_profile' => $this->profileSummary($profile),
                    'location' => $watch->location,
                    'event_types' => implode(', ', $watch->event_types),
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

            foreach ($events as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $extracted = ExtractedSignal::fromAiItem($raw);
                $item = $batch[$extracted->itemIndex] ?? null;

                if ($item instanceof NewsItem && $extracted->meetsThreshold($minConfidence, $minRelevance)) {
                    $kept[] = [$extracted, $item];
                }
            }
        }

        return [$kept, $costMicros, false];
    }

    /**
     * Persist the kept signals + the run totals in one transaction. `insertOrIgnore` on
     * `(team_id, url_hash)` makes a re-run idempotent and never overwrites an existing (possibly
     * converted) signal. Fires {@see SignalsDetected} after commit.
     *
     * @param  list<array{0: ExtractedSignal, 1: NewsItem}>  $kept
     */
    private function complete(SignalRun $run, SignalWatch $watch, array $kept, int $queriesCount, int $resultsCount, int $costMicros): SignalRun
    {
        // De-duplicate by url_hash within this run, keeping the highest-confidence result when the
        // model returns more than one for the same article.
        $best = [];
        foreach ($kept as [$extracted, $item]) {
            $existing = $best[$item->urlHash][0] ?? null;
            if (! $existing instanceof ExtractedSignal || $extracted->confidence > $existing->confidence) {
                $best[$item->urlHash] = [$extracted, $item];
            }
        }

        $rows = [];
        foreach ($best as [$extracted, $item]) {
            $rows[] = $this->toRow($run, $watch, $extracted, $item);
        }

        $created = DB::transaction(function () use ($run, $watch, $rows, $queriesCount, $resultsCount, $costMicros): int {
            $created = $rows === [] ? 0 : Signal::query()->insertOrIgnore($rows);

            $run->forceFill([
                'status' => SignalRunStatus::Completed,
                'queries_count' => $queriesCount,
                'results_count' => $resultsCount,
                'signals_created' => $created,
                'cost_micros' => $costMicros,
                'error' => null,
                'started_at' => $run->started_at ?? now(),
                'finished_at' => now(),
            ])->save();

            $watch->forceFill(['last_run_at' => now()])->save();

            $teamId = (int) $run->team_id;
            $runId = (int) $run->getKey();
            DB::afterCommit(static function () use ($teamId, $runId, $created): void {
                if ($created > 0) {
                    event(new SignalsDetected($teamId, $runId, $created));
                }
            });

            return $created;
        });

        $this->broadcast($run, SignalRunStatus::Completed, 100, 'Done', $created);

        return $run->refresh();
    }

    /**
     * Build the DB-ready row for a kept signal. Written as a raw row (not via the model) because
     * `insertOrIgnore` is a bulk statement — so enums, JSON and the UTC published date are serialised
     * here. The news item's title is the factual headline; the AI summary is separate. `published_at`
     * falls back to the run time when Serper gave no parseable date.
     *
     * @return array<string, mixed>
     */
    private function toRow(SignalRun $run, SignalWatch $watch, ExtractedSignal $extracted, NewsItem $item): array
    {
        $now = now();

        $eventType = $extracted->eventType;
        if ($eventType === null) {
            // Unreachable: only threshold-passing items (which require a non-null event type) are
            // kept. Guarded so the not-null column can never be written empty.
            throw new \LogicException('Cannot persist a signal without an event type.');
        }

        return [
            'team_id' => $watch->team_id,
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
            'event_type' => $eventType->value,
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
            'confidence' => $extracted->confidence,
            'relevance' => $extracted->relevance,
            'suggested_approach' => $extracted->suggestedApproach,
            'source_url' => $item->link,
            'source_name' => $item->source,
            'published_at' => ($item->publishedAt ?? $now)->copy()->utc(),
            'url_hash' => $item->urlHash,
            'raw' => json_encode($item->raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
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
     * @param  list<NewsItem>  $batch
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
    private function fail(SignalRun $run, SignalWatch $watch, string $message, int $queriesCount = 0, int $resultsCount = 0, int $costMicros = 0): SignalRun
    {
        DB::transaction(function () use ($run, $watch, $message, $queriesCount, $resultsCount, $costMicros): void {
            $run->forceFill([
                'status' => SignalRunStatus::Failed,
                'error' => $message,
                'queries_count' => $queriesCount,
                'results_count' => $resultsCount,
                'cost_micros' => $costMicros,
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
