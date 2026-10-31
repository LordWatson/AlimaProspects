<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Data\ResourceQueryOptions;
use App\Modules\Admin\Resources\AdminResource;
use App\Modules\Core\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Turns a descriptor plus an operator's query string into one paginated page of rows (Phase 19a).
 *
 * **This is the only place in the product that queries a tenant table with no `team_id` in the
 * `where` clause.** {@see BelongsToTeam}'s global scope is a no-op when no team is active, and the
 * admin middleware has cleared the context precisely so that these reads cross tenants. Everything
 * here is therefore written as if it were the attack surface it is:
 *
 * - **Sorts are whitelisted** against `sortable()`, never taken from the query string. An
 *   unvalidated sort column is both an injection and a full table scan on whatever has no index.
 * - **Filters are whitelisted twice** — the descriptor has to declare the filter, and the value has
 *   to be one of the options it declared. An open filter grammar would be a query nobody wrote.
 * - **Searches bind the term and never build SQL.** The pattern is a bound parameter and the column
 *   comes from `searchable()`, so there is no raw SQL and nothing from the request is concatenated
 *   into a query. Wildcards the operator types are passed through deliberately rather than escaped:
 *   on an internal tool a `%` is a useful feature, and both `%` and `_` can only ever match *more*
 *   rows, so the row somebody was looking for is still in the results. That also sidesteps the
 *   `LIKE ... ESCAPE` character being a cross-database trap — a backslash escape is one this
 *   codebase has already been bitten by on MySQL (Phase 15 corrected it in the Signals exporter).
 * - **Relations are eager loaded** from `with()`. The index renders a page of rows, so a lazy
 *   relation is an N+1 — and `preventLazyLoading` turns that into a failure rather than a slow page,
 *   which is why a dotted column without its relation declared breaks loudly in tests.
 *
 * Pagination is never optional and has no "all" escape hatch, for the reason `PaginatedIndexRequest`
 * gives about `/api/v1`: an unbounded page is a way to ask the database to perform an export nobody
 * authorised.
 */
final class ResourceQuery
{
    public const int MAX_PER_PAGE = 100;

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(AdminResource $resource, ResourceQueryOptions $options): LengthAwarePaginator
    {
        $query = $this->apply($resource, $options);

        /** @var LengthAwarePaginator<int, Model> $paginator */
        $paginator = $query->paginate(min($options->perPage, self::MAX_PER_PAGE));

        return $paginator->withQueryString();
    }

    /**
     * @return Builder<Model>
     */
    public function apply(AdminResource $resource, ResourceQueryOptions $options): Builder
    {
        $model = $resource->model();

        /** @var Builder<Model> $query */
        $query = $model::query();

        if ($resource->with() !== []) {
            $query->with($resource->with());
        }

        $this->applyTeam($resource, $query, $options);
        $this->applyFilters($resource, $query, $options);
        $this->applySearch($resource, $query, $options);
        $this->applySort($resource, $query, $options);

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyTeam(AdminResource $resource, Builder $query, ResourceQueryOptions $options): void
    {
        if ($options->teamId === null || ! $resource->isTenantOwned()) {
            return;
        }

        $query->where($query->getModel()->qualifyColumn('team_id'), $options->teamId);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyFilters(AdminResource $resource, Builder $query, ResourceQueryOptions $options): void
    {
        foreach ($resource->filters() as $filter) {
            $value = $options->filters[$filter->name] ?? null;

            if (! is_string($value) || $value === '' || ! $filter->accepts($value)) {
                continue;
            }

            $query->where($query->getModel()->qualifyColumn($filter->name), $this->filterValue($filter, $value));
        }
    }

    /**
     * A boolean filter arrives as `yes`/`no` and has to reach the column as 1/0; everything else is
     * compared as it came. Cast here rather than in the descriptor, so the descriptor stays
     * declarative — and driven by the filter's own `isBoolean` flag rather than by recognising an
     * option list, which would make one array shape magic.
     */
    private function filterValue(ResourceFilter $filter, string $value): string|bool
    {
        return $filter->isBoolean ? $value === 'yes' : $value;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySearch(AdminResource $resource, Builder $query, ResourceQueryOptions $options): void
    {
        $term = trim((string) $options->search);

        if ($term === '' || $resource->searchable() === []) {
            return;
        }

        $pattern = '%'.$term.'%';
        $columns = $resource->searchable();
        $table = $query->getModel();

        $query->where(static function (Builder $nested) use ($columns, $pattern, $table): void {
            foreach ($columns as $column) {
                $nested->orWhere($table->qualifyColumn($column), 'like', $pattern);
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySort(AdminResource $resource, Builder $query, ResourceQueryOptions $options): void
    {
        $sort = in_array($options->sort, $resource->sortable(), true)
            ? (string) $options->sort
            : $resource->defaultSort();

        $direction = $options->direction === 'asc' ? 'asc' : 'desc';

        $query->orderBy($query->getModel()->qualifyColumn($sort), $direction);

        // A stable tiebreak, so page 2 is not a reshuffle of page 1 when the sort column repeats —
        // which it does on every status and enum column in the registry.
        $key = $query->getModel()->getKeyName();

        if ($sort !== $key) {
            $query->orderBy($query->getModel()->qualifyColumn($key), 'desc');
        }
    }
}
