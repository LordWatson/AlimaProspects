<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\SignalFilters;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Models\Signal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The signals feed (S5): one page of a team's signals for the chosen tab and filters.
 *
 * **Server-driven**, like the leads list: the tab, the filters and the page all live in the query,
 * so the screen never holds more than a page of signals and a shared URL reproduces exactly what
 * the sender saw.
 *
 * Ordering is `published_at` descending — the feed is about *when the event happened*, not when
 * Alima happened to find it — with the id as a tie-break so paging is stable when a run writes a
 * dozen signals sharing a fallback timestamp.
 */
final class ListSignalsAction
{
    /** Signals per page. Cards are tall, so a page is short. */
    public const int PER_PAGE = 15;

    public const int MAX_PER_PAGE = 50;

    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return LengthAwarePaginator<int, Signal>
     */
    public function handle(Team $team, SignalFilters $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage ?? self::PER_PAGE, self::MAX_PER_PAGE));

        return $this->teamContext->run($team, function () use ($team, $filters, $perPage): LengthAwarePaginator {
            $query = Signal::query()
                // Explicit beside the global scope (ADR 0006): a feed is the one screen where a
                // missing scope would be both invisible and catastrophic.
                ->where('team_id', $team->getKey())
                ->where('status', $filters->status)
                // The converted tab links straight through to the lead, and the card needs the
                // watch's name to say which watch found this.
                ->with(['watch:id,name', 'lead:id,company_name']);

            if ($filters->eventTypes !== []) {
                $query->whereIn(
                    'event_type',
                    array_map(static fn (SignalEventType $type): string => $type->value, $filters->eventTypes),
                );
            }

            if ($filters->from !== null) {
                $query->where('published_at', '>=', $filters->from->setTime(0, 0));
            }

            if ($filters->to !== null) {
                // Inclusive: a member filtering "to the 9th" means the whole of the 9th.
                $query->where('published_at', '<=', $filters->to->setTime(23, 59, 59));
            }

            if ($filters->minRelevance !== null) {
                $query->where('relevance', '>=', $filters->minRelevance);
            }

            return $query
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->paginate($perPage)
                ->withQueryString();
        });
    }

    /**
     * How many signals sit in each tab, so the tabs can carry counts without the screen fetching
     * three pages to find out. One grouped query, not three.
     *
     * @return array<string, int>
     */
    public function counts(Team $team): array
    {
        return $this->teamContext->run($team, static fn (): array => Signal::query()
            ->where('team_id', $team->getKey())
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all());
    }
}
