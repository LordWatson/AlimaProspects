<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Actions;

use App\Modules\AiInsights\Data\InsightDraft;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes a run's drafts (Phase 14) — the only code that creates an {@see Insight}.
 *
 * ### `firstOrCreate`, and why it is not `updateOrCreate`
 *
 * The dedupe key is `<type>:<the team's local date>`, so a second run on the same day finds the
 * row that is already there. It is left **exactly as it is**, which is the whole point:
 *
 * - A member who dismissed this morning's "going cold" alert must not have it reappear at noon
 *   because the hourly sweep ran again. `updateOrCreate` would quietly resurrect it by writing a
 *   fresh `generated_at` over a dismissed row, and the member would learn that dismissing does
 *   nothing.
 * - The counts in a stored payload are a snapshot of `generated_at` and are labelled as such, so
 *   refreshing them would buy nothing and cost the only thing that makes the row trustworthy.
 *
 * All the writes go in one transaction: a run either adds its insights or adds none, so the
 * notification that follows it can state a count that is true.
 */
final readonly class StoreInsightsAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @param  list<InsightDraft>  $drafts
     * @return list<Insight> The rows this run actually created, in draft order. Empty when every
     *                       draft was already stored today.
     */
    public function handle(Team $team, array $drafts, ?DateTimeInterface $generatedAt = null): array
    {
        if ($drafts === []) {
            return [];
        }

        $at = CarbonImmutable::parse($generatedAt ?? CarbonImmutable::now())->utc();

        return $this->teamContext->run($team, fn (): array => DB::transaction(function () use ($team, $drafts, $at): array {
            $created = [];

            foreach ($drafts as $draft) {
                $insight = Insight::query()->firstOrCreate(
                    ['team_id' => $team->getKey(), 'dedupe_key' => $draft->dedupeKey],
                    $draft->attributes($at),
                );

                if ($insight->wasRecentlyCreated) {
                    $created[] = $insight;
                }
            }

            return $created;
        }));
    }
}
