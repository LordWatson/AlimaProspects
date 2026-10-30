<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Data\SignalFilters;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the feed's tab/filter query string. Authorisation lives in the controller via
 * `SignalPolicy`.
 *
 * The rules are **permissive on purpose** and {@see SignalFilters} does the narrowing: a filter
 * value that no longer exists should open the feed without it, not 422 a link someone shared last
 * month. What the rules are really for is bounding the input — a 10 kB `status` or a thousand
 * event types shouldn't reach the DTO at all.
 */
final class ListSignalsRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'in:'.implode(',', SignalStatus::values())],
            'event_types' => ['nullable', 'array', 'max:'.count(SignalEventType::cases())],
            'event_types.*' => ['string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'min_relevance' => ['nullable', 'integer', 'min:0', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.ListSignalsAction::MAX_PER_PAGE],
        ];
    }

    public function filters(): SignalFilters
    {
        return SignalFilters::fromArray($this->validated());
    }

    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? (int) $perPage : ListSignalsAction::PER_PAGE;
    }
}
