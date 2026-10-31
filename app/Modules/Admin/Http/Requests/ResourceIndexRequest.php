<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Data\ResourceQueryOptions;
use App\Modules\Admin\Services\ResourceQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What an operator may ask a registry index for (Phase 19a).
 *
 * Shape only. **Meaning is checked later**, in {@see ResourceQuery}, against the descriptor: a
 * filter the resource does not declare, a value outside its option list and a sort column outside
 * `sortable()` are all discarded there rather than refused here. That division is deliberate — this
 * request has no idea which resource it is for (the registry resolves that from the route), so a
 * rule written here could only ever be a guess at one.
 *
 * The `per_page` ceiling is the same idea as `PaginatedIndexRequest`'s and exists for the same
 * reason: an unbounded page is a way to ask the database to perform an export nobody authorised,
 * and on a cross-tenant table that is every tenant's data in one response.
 *
 * `authorize()` returns true because authorisation for the panel is the middleware stack — the
 * admin role, the 2FA requirement and the route's `admin.can:` ability — all of which have already
 * run by the time a FormRequest is resolved.
 */
final class ResourceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.ResourceQuery::MAX_PER_PAGE],
            'filters' => ['nullable', 'array'],
            'filters.*' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function options(): ResourceQueryOptions
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $search = $validated['search'] ?? null;
        $teamId = $validated['team_id'] ?? null;
        $sort = $validated['sort'] ?? null;
        $direction = $validated['direction'] ?? null;
        $perPage = $validated['per_page'] ?? null;

        return new ResourceQueryOptions(
            search: is_string($search) && $search !== '' ? $search : null,
            filters: $this->filters($validated),
            teamId: is_numeric($teamId) ? (int) $teamId : null,
            sort: is_string($sort) && $sort !== '' ? $sort : null,
            direction: $direction === 'asc' ? 'asc' : 'desc',
            perPage: is_numeric($perPage) ? (int) $perPage : 25,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function filters(array $validated): array
    {
        $filters = $validated['filters'] ?? [];

        if (! is_array($filters)) {
            return [];
        }

        $clean = [];

        foreach ($filters as $name => $value) {
            if (is_string($name) && is_string($value) && $value !== '') {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }
}
