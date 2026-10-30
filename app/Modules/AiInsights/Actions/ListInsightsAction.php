<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Actions;

use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The insights feed (Phase 14): one page of a team's insights for the chosen tab.
 *
 * Server-driven, like the signals feed — the tab and the page live in the query string, so a
 * shared URL reproduces what the sender saw.
 *
 * Ordering is {@see Insight::scopeFeedOrder()}: severity first, then newest. An insight feed sorted
 * purely by time puts "your reply rate is up" above "four companies are going cold", which is the
 * wrong way round for somebody reading one screen before their first call.
 */
final class ListInsightsAction
{
    /** Insights per page. The cards carry lists of companies, so a page is short. */
    public const int PER_PAGE = 15;

    public const int MAX_PER_PAGE = 50;

    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return LengthAwarePaginator<int, Insight>
     */
    public function handle(
        Team $team,
        InsightStatus $status = InsightStatus::New,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $perPage = max(1, min($perPage ?? self::PER_PAGE, self::MAX_PER_PAGE));

        return $this->teamContext->run($team, fn (): LengthAwarePaginator => Insight::query()
            // Explicit beside the global scope (ADR 0006): a feed is the one screen where a missing
            // scope would be both invisible and catastrophic.
            ->where('team_id', $team->getKey())
            ->where('status', $status->value)
            ->feedOrder()
            ->paginate($perPage)
            ->withQueryString());
    }

    /**
     * How many insights sit in each tab. One grouped query, not two.
     *
     * @return array<string, int>
     */
    public function counts(Team $team): array
    {
        return $this->teamContext->run($team, static fn (): array => Insight::query()
            ->where('team_id', $team->getKey())
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all());
    }
}
