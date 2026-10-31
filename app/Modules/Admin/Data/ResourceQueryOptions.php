<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Admin\Services\ResourceQuery;

/**
 * What an operator asked an index for (Phase 19a): a search term, equality filters, one tenant,
 * a sort and a page size.
 *
 * Read off the query string rather than held in client state, like the leads list and the
 * dashboard: an index an operator can paste into a ticket reproduces exactly what they were looking
 * at, which is most of the value of having the panel at all.
 */
final readonly class ResourceQueryOptions
{
    /**
     * @param  array<string, string>  $filters  Filter name => value, already string-typed. Values
     *                                          the descriptor does not declare are discarded by
     *                                          {@see ResourceQuery}.
     */
    public function __construct(
        public ?string $search = null,
        public array $filters = [],
        public ?int $teamId = null,
        public ?string $sort = null,
        public string $direction = 'desc',
        public int $perPage = 25,
    ) {}

    /**
     * The shape that goes into the audit entry for a read: what was asked for, never what came back
     * — and **never the search term itself**.
     *
     * The term is the one field here that can be personal data, and operators search by email
     * address and by name. Writing it in would put `jane@acme.com` into `audit_logs` on every
     * index view, which is the exact thing this entry exists to police: a record of who looked at
     * personal data must not itself be a copy of it. `audit_logs` is append-only and outside
     * Compliance's retention sweep, so it would stay there too.
     *
     * `searched` records that a term was used, which is what an audit reader needs to know; the
     * filters are enum values and tenant ids, which are not personal data. If somebody has to
     * reproduce exactly what an operator saw, the URL is in the ticket.
     *
     * @return array<string, mixed>
     */
    public function toAuditProperties(): array
    {
        return array_filter([
            'searched' => $this->search !== null && $this->search !== '' ? true : null,
            'filters' => $this->filters === [] ? null : $this->filters,
            'team_id' => $this->teamId,
            'sort' => $this->sort,
            'per_page' => $this->perPage,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The form state the index page renders its controls from.
     *
     * @return array<string, mixed>
     */
    public function toFormState(): array
    {
        return [
            'search' => $this->search,
            'filters' => $this->filters,
            'team_id' => $this->teamId,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
        ];
    }
}
