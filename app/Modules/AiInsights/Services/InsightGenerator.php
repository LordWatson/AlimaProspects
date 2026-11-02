<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Services;

use App\Modules\AiInsights\Actions\BuildContactTodayAction;
use App\Modules\AiInsights\Actions\FindStaleLeadsAction;
use App\Modules\AiInsights\Actions\StoreInsightsAction;
use App\Modules\AiInsights\Console\GenerateInsightsCommand;
use App\Modules\AiInsights\Data\ActivitySnapshot;
use App\Modules\AiInsights\Data\InsightDraft;
use App\Modules\AiInsights\Enums\InsightSeverity;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\AiInsights\Models\InsightRun;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiPlatformException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds one team's insights for one day (Phase 14) and tells the team they are there.
 *
 * ### The order is the design
 *
 * The two deterministic insights are built **first and unconditionally**, then the model is asked
 * for its reading of the week, and only then is anything written. So:
 *
 * - **An AI failure costs the feed its commentary, not its contents.** A provider outage, an
 *   exhausted allowance or a response that will not validate is caught here: the stale-lead alert
 *   and today's call list are already in hand and still get stored. The alternative — one
 *   try/catch around the whole run — means a bad morning at the provider is the reason a team
 *   never hears that four companies are going cold.
 * - **A quiet week costs nothing.** {@see ActivitySnapshot::isQuiet()} skips the call outright, so
 *   a trial account that did nothing is not billed daily for the model saying so.
 * - **The AI call happens outside the transaction**, which {@see StoreInsightsAction} opens after
 *   this method has everything it is going to write (CLAUDE.md: never an external call inside a
 *   transaction).
 *
 * ### Once a day, whatever the day produced
 *
 * Every run writes an {@see InsightRun} for the team's local date, and a run that finds that row
 * already there does nothing at all. The marker is what makes "once a day" true rather than
 * merely likely: a day with no stale leads, nobody to call and a model with nothing to say creates
 * no insights, and an earlier version — which asked the insights table whether the team had been
 * done — would therefore run the aggregates and the AI call again every hour until midnight. The
 * dedupe key on each insight protects the *rows*; this protects the *spend*.
 *
 * `--team` on {@see GenerateInsightsCommand} forces a run past the marker, for a demo or a support
 * question. Even then the AI call is skipped when the day already has a digest, so a forced run
 * costs nothing at the provider.
 *
 * The notification goes out only when something was actually created, and inside
 * `DB::afterCommit()`, so the count it states is a count of rows that exist.
 */
final readonly class InsightGenerator
{
    public function __construct(
        private FindStaleLeadsAction $staleLeads,
        private BuildContactTodayAction $contactToday,
        private ActivitySnapshotBuilder $snapshots,
        private StoreInsightsAction $store,
        private AiClient $ai,
        private NotificationDispatcher $notifications,
        private TeamContext $teamContext,
        private CapabilityGate $capabilities,
    ) {}

    /**
     * @param  bool  $force  Run even though the team has already been done today. The insights'
     *                       own dedupe keys still apply, so a forced run cannot duplicate a row —
     *                       and the digest check below means it cannot duplicate a cost either.
     * @return list<Insight> The insights this run created. Empty when the team had nothing new to
     *                       be told, or had already been told it today.
     */
    public function forTeam(Team $team, ?DateTimeInterface $now = null, bool $force = false): array
    {
        // **The capability check, in the Action rather than in middleware** (Phase 19b, ADR 0015).
        // This path is reachable from a queued job and from the scheduler, so there is no request
        // to hang middleware on — ADR 0012's argument, unchanged. The route middleware beside it is
        // the courtesy that gives a member a sentence; this is what makes the refusal true.
        //
        // Returns an empty list rather than throwing: this is reached from an hourly command that
        // walks every team, and a plan that does not include insights is a normal state for most of
        // them — not an exception worth failing a scheduled run over.
        if (! $this->capabilities->allows($team, Capability::AiInsights)) {
            return [];
        }

        $asOf = CarbonImmutable::parse($now ?? CarbonImmutable::now())->utc();
        // The **team's** local date, not the server's: "one of these per day" is measured in the
        // team's own calendar, and in Dubai the day turns four hours before it does in UTC. A date
        // cut in UTC would give a Dubai team two "today"s between 20:00 and midnight.
        $dateKey = $asOf->setTimezone((string) $team->timezone)->toDateString();

        if (! $force && $this->alreadyRan($team, $dateKey)) {
            return [];
        }

        $drafts = array_values(array_filter([
            $this->staleLeads->handle($team, $dateKey, $asOf),
            $this->contactToday->handle($team, $dateKey, $asOf),
            ...$this->digest($team, $dateKey, $asOf),
        ]));

        $created = $this->store->handle($team, $drafts, $asOf);

        $this->recordRun($team, $dateKey, $asOf, $drafts, $created);

        if ($created !== []) {
            // After the write has committed. It is already outside `StoreInsightsAction`'s
            // transaction here, but a future caller wrapping this method in one of its own would
            // otherwise announce rows that could still be rolled back (CLAUDE.md).
            DB::afterCommit(fn () => $this->announce($team, $created));
        }

        Log::channel('insights')->info('Insights generated.', [
            'team_id' => $team->getKey(),
            'date' => $dateKey,
            'drafted' => count($drafts),
            'created' => count($created),
            'forced' => $force,
        ]);

        return $created;
    }

    /**
     * Has this team already had its insights worked out today?
     */
    private function alreadyRan(Team $team, string $dateKey): bool
    {
        return $this->teamContext->run($team, fn (): bool => InsightRun::query()
            ->where('team_id', $team->getKey())
            ->where('local_date', $dateKey)
            ->exists());
    }

    /**
     * Stamp the day as done, whatever it found.
     *
     * `updateOrCreate` rather than `firstOrCreate`, because a forced re-run should update what the
     * marker says it did rather than silently keep the first run's figures.
     *
     * @param  list<InsightDraft>  $drafts
     * @param  list<Insight>  $created
     */
    private function recordRun(
        Team $team,
        string $dateKey,
        CarbonImmutable $asOf,
        array $drafts,
        array $created,
    ): void {
        $aiDrafts = array_values(array_filter(
            $drafts,
            static fn (InsightDraft $draft): bool => $draft->type->isWrittenByAi(),
        ));

        $this->teamContext->run($team, fn (): InsightRun => InsightRun::query()->updateOrCreate(
            ['team_id' => $team->getKey(), 'local_date' => $dateKey],
            [
                'generated_at' => $asOf,
                'insights_created' => count($created),
                'ai_called' => $aiDrafts !== [],
                // The cost is on the first draft of a batch: one call produced all of them, and
                // every draft carries the same figure, so summing them would multiply it.
                'cost_micros' => $aiDrafts === [] ? 0 : $aiDrafts[0]->costMicros,
            ],
        ));
    }

    /**
     * The model's read of the week, as zero or more drafts.
     *
     * Returns an empty list — never throws — for every reason the call might not happen: switched
     * off, already done today, a quiet week, or the AI Platform refusing. See the class docblock.
     *
     * @return list<InsightDraft>
     */
    private function digest(Team $team, string $dateKey, CarbonImmutable $asOf): array
    {
        if (! (bool) config('ai-insights.ai.enabled', true)) {
            return [];
        }

        // A forced run reaches this with the day's digest already stored. Asking again would spend
        // real money to produce rows the dedupe keys then throw away — and, because the model is
        // not deterministic, a longer answer than the first would slip extra cards past them.
        if ($this->digestExistsFor($team, $dateKey)) {
            return [];
        }

        $snapshot = $this->snapshots->forTeam($team);

        if ($snapshot->isQuiet()) {
            return [];
        }

        try {
            // No {@see UntrustedContent} wrapper, and that is not an oversight: the snapshot is
            // numbers the platform computed, with no team-entered text in it at all (see
            // {@see ActivitySnapshot}). Nothing here came from outside, so there is nothing to
            // delimit.
            $response = $this->ai->structured('insights.periodic_digest', [
                'activity' => $snapshot->toPromptValue(),
            ]);
        } catch (AiPlatformException $e) {
            // A warning rather than an error: the run succeeded, it just has less to say. The
            // exception class only — a provider message can carry prompt content, and this channel
            // holds ids and counts.
            Log::channel('insights')->warning('Insight digest skipped: the AI call failed.', [
                'team_id' => $team->getKey(),
                'exception' => $e::class,
            ]);

            return [];
        }

        $items = $response->get('insights');

        if (! is_array($items)) {
            return [];
        }

        $maxItems = max(1, (int) config('ai-insights.ai.max_items', 3));
        $drafts = [];

        foreach (array_values($items) as $index => $item) {
            if (count($drafts) === $maxItems) {
                break;
            }

            if (! is_array($item)) {
                continue;
            }

            $headline = $this->text($item, 'title', 240);
            $body = $this->text($item, 'body', 1_000);

            // An insight with nothing to say is not an insight. The schema requires both fields,
            // so this guards against an empty string getting through rather than a missing key.
            if ($headline === null || $body === null) {
                continue;
            }

            $drafts[] = new InsightDraft(
                type: InsightType::PeriodicDigest,
                severity: InsightSeverity::tryFrom($this->text($item, 'severity', 16) ?? '')
                    ?? InsightType::PeriodicDigest->defaultSeverity(),
                payload: [
                    // What the model says it is about, and the figures it was looking at — so a
                    // sentence on the card can always be checked against the numbers behind it.
                    'topic' => $this->text($item, 'topic', 32),
                    'metrics' => $snapshot->metrics,
                ],
                dedupeKey: InsightType::PeriodicDigest->value.':'.$dateKey.':'.$index,
                headline: $headline,
                body: $body,
                periodStart: $snapshot->periodStart === null
                    ? null
                    : CarbonImmutable::parse($snapshot->periodStart, (string) $team->timezone)->utc(),
                periodEnd: $asOf,
                promptKey: $response->promptKey,
                promptVersion: $response->promptVersion,
                model: $response->model,
                costMicros: $response->costMicros,
            );
        }

        return $drafts;
    }

    private function digestExistsFor(Team $team, string $dateKey): bool
    {
        return $this->teamContext->run($team, fn (): bool => Insight::query()
            ->where('team_id', $team->getKey())
            ->where('type', InsightType::PeriodicDigest->value)
            ->where('dedupe_key', 'like', InsightType::PeriodicDigest->value.':'.$dateKey.':%')
            ->exists());
    }

    /**
     * Tell the team, through the Notifications module — never directly (CLAUDE.md).
     *
     * The payload is counts, not a sentence: the wording lives in `NotificationPresenter` and is
     * composed when the member reads it, in whatever language they have the app set to.
     *
     * @param  list<Insight>  $created
     */
    private function announce(Team $team, array $created): void
    {
        $counts = [];

        foreach ($created as $insight) {
            $key = $insight->type->value;
            $payloadCount = $insight->payload['count'] ?? 1;
            $counts[$key] = ($counts[$key] ?? 0) + (is_numeric($payloadCount) ? (int) $payloadCount : 1);
        }

        $this->notifications->toTeam($team, NotificationType::InsightsReady, [
            'count' => count($created),
            'contact_count' => $counts[InsightType::ContactToday->value] ?? 0,
            'stale_count' => $counts[InsightType::StaleLeads->value] ?? 0,
        ]);
    }

    /**
     * A trimmed, clamped string from the model's output, or null when it is empty.
     *
     * The prompt's schema already bounds these; clamping again is cheap, and means a schema
     * loosened in a later prompt version cannot overflow a column.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function text(array $item, string $key, int $limit): ?string
    {
        $value = $item[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $clean = Str::limit(Str::squish($value), $limit, '');

        return $clean === '' ? null : $clean;
    }
}
