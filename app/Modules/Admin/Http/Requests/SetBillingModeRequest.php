<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Billing\Enums\BillingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing how a tenant is billed (Phase 19b).
 */
final class SetBillingModeRequest extends FormRequest
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
            'mode' => ['required', Rule::enum(BillingMode::class)],
        ];
    }

    public function mode(): BillingMode
    {
        return BillingMode::from((string) $this->validated('mode'));
    }
}
