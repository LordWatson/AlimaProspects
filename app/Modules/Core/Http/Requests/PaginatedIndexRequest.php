<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The pagination parameter, defined once (Phase 17).
 *
 * `/api/v1` grew endpoint by endpoint, and `per_page` grew with it: some endpoints read it and
 * capped it, some paginated at a fixed size and ignored it, and a couple handed back the whole
 * table. A client cannot write one pager against three behaviours, so this is the shared base for
 * any index endpoint whose only input is the page it wants — the ones with filters of their own
 * (leads, signals, insights) declare `per_page` in their own request with the same ceiling.
 *
 * The ceiling is the point: without it, `per_page=100000` is a way to ask for the whole table in
 * one query, which is both a slow page and a cheap way to make the database do the work of an
 * export nobody authorised.
 */
class PaginatedIndexRequest extends FormRequest
{
    /**
     * The largest page any `/api/v1` endpoint will serve.
     */
    public const int MAX_PER_PAGE = 100;

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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(int $default = 25): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? (int) $perPage : $default;
    }
}
