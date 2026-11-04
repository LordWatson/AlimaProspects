<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\SignalFilters;
use App\Modules\Signals\Enums\SignalScope;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\Signal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The signals feed (S5): one page of a team's signals for the chosen tab and filters.
 *
 * **Server-driven**, like the leads list: the tab, the filters and the page all live in the query,
 * so the screen never holds more than a page of signals and a shared URL reproduces exactly what
 * the sender saw.
 *
 * **Ordering is the member's, and defaults to heat** (S12, {@see SignalSortOrder}): what the event
 * is worth to this team, from the deterministic `SignalScorer`, with an unscored signal sorting
 * last rather than first. "Most recent" is kept as a real choice — v1's `published_at` order, which
 * is about *when the event happened* rather than when Alima found it — because a ranking nobody has
 * learned to trust yet needs the order they already understand to fall back to. Both tie-break on
 * the id so paging is stable when a run writes a dozen signals sharing a timestamp or a score.
 *
 * **Since S11 a row is a card only if it represents an event** ({@see self::onlyRepresentatives()}).
 * Eight outlets covering one acquisition are eight signals and one cluster, and the feed lists the
 * cluster's primary — the card then offers the other seven as "reported by 8 sources". The date it
 * is ordered by is the primary's, which is the first report of the event and so the event's own
 * date; later coverage joining the cluster does not push an old story back to the top of the feed.
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
                // watch's name to say which watch found this. `definition` is what the card's event
                // label reads (S7, ADR 0018) - eager-loaded, because a page of cards would
                // otherwise be a page of queries, and `preventLazyLoading` would say so.
                // `matchedLead` too since S10: the card offers "add this to Acme Ltd" rather
                // than "convert" when the event is about a company the team already has, and it
                // has to be able to name it.
                //
                // `cluster` and its members since S11: the card says "reported by 6 sources" and
                // expands to the list, so both the cluster's counters and the member rows come
                // down with the page. Columns only — a member is a link and a date on this screen,
                // never a second copy of the summary.
                ->with([
                    // **`market` and `regions` as well as the label.** The card names the watch,
                    // so `id,name` was all it needed — but `SignalResource` now also reports the
                    // outreach restriction a conversion would apply, and resolving that falls back
                    // to the watch's own market and then to its regions when the article named
                    // nowhere (ADR 0019 §2). Narrowing a relation's columns is a contract with
                    // every resource that reads it: with `shouldBeStrict()` on, the two missing
                    // columns are a `MissingAttributeException` rather than a null, which is the
                    // good outcome — the feed 500s in development instead of quietly telling
                    // somebody a UK prospect needs consent.
                    'watch:id,name,market,regions',
                    'lead:id,company_name',
                    'matchedLead:id,company_name',
                    'definition',
                    'cluster',
                    'cluster.signals:id,signal_cluster_id,source_name,source_url,published_at',
                ]);

            $query->representatives();

            if ($filters->scope === SignalScope::Accounts) {
                // The warm half of the feed: only events about companies the team already works.
                $query->whereNotNull('matched_lead_id');
            }

            if ($filters->eventKeys !== []) {
                // The stored key, not the FK: a signal whose definition has been deleted keeps its
                // key and must stay filterable by it.
                $query->whereIn('event_key', $filters->eventKeys);
            }

            if ($filters->sourceKinds !== []) {
                // By the stored column, which every row has: a pre-S13 signal is `news` because
                // that is what found it, so this filter has a true answer for the whole table and
                // never silently excludes the history.
                $query->whereIn('source_kind', array_map(
                    static fn (SignalSourceKind $kind): string => $kind->value,
                    $filters->sourceKinds,
                ));
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

            // The order the member asked for, defaulting to heat (S12). The enum owns the
            // `ORDER BY` — including the null handling, which differs by database and is the one
            // part of this somebody would get wrong twice.
            $filters->sort->apply($query);

            return $query->paginate($perPage)->withQueryString();
        });
    }

    /**
     * How many signals sit in each tab, so the tabs can carry counts without the screen fetching
     * three pages to find out. One grouped query, not three.
     *
     * @return array<string, int>
     */
    public function counts(Team $team, ?SignalScope $scope = null): array
    {
        // Scoped with the feed since S10, or the numbers on the tabs would answer a different
        // question from the tabs: "My accounts / New 4" beside four hundred cold signals is a
        // member being told the feature is broken.
        $accountsOnly = $scope === SignalScope::Accounts;

        return $this->teamContext->run($team, function () use ($team, $accountsOnly): array {
            $query = Signal::query()->where('team_id', $team->getKey());

            // Counted the same way the feed lists, which since S11 means **per event, not per
            // article** (one card per cluster). A tab reading 48 over a list of six cards would be
            // a member being told the feature is broken — the same mistake S10 made the other way
            // round with the accounts scope. The predicate is `Signal::representatives()`, shared
            // with the digest and the dashboard so the three cannot disagree.
            $query->representatives();

            return $query
                ->when($accountsOnly, static fn ($builder) => $builder->whereNotNull('matched_lead_id'))
                ->groupBy('status')
                ->selectRaw('status, count(*) as aggregate')
                ->pluck('aggregate', 'status')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();
        });
    }
}
