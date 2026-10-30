<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Enums\SignalDismissReason;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a dismissal. The reason is **optional** — a member who just wants the card gone
 * shouldn't have to justify it — but must be one of the known reasons when given, so the stored
 * values stay usable for tuning. Authorisation lives in the controller via `SignalPolicy`.
 */
final class DismissSignalRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'in:'.implode(',', SignalDismissReason::values())],
        ];
    }

    public function reason(): ?SignalDismissReason
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? SignalDismissReason::tryFrom($reason) : null;
    }
}
