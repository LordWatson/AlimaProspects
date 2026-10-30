<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Http\Requests;

use App\Modules\AiInsights\Actions\ListInsightsAction;
use App\Modules\AiInsights\Enums\InsightStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the feed's tab and page size. Authorisation lives in the controller via
 * `InsightPolicy`.
 *
 * Permissive on purpose, like the signals feed: an unknown `status` opens the default tab rather
 * than 422-ing a link somebody shared last month. What the rules are really for is bounding the
 * input before it reaches a query.
 */
final class ListInsightsRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'max:16'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.ListInsightsAction::MAX_PER_PAGE],
        ];
    }

    public function status(): InsightStatus
    {
        $status = $this->validated('status');

        return (is_string($status) ? InsightStatus::tryFrom($status) : null) ?? InsightStatus::New;
    }

    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? (int) $perPage : ListInsightsAction::PER_PAGE;
    }
}
