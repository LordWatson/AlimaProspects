<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Core\Enums\Capability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Overriding what a team's plan includes (Phase 19b, ADR 0015).
 *
 * The **reason is required** and the expiry is offered, because those two fields are the difference
 * between an exception somebody decided and an exception somebody forgot. A pilot's Signals should
 * come with a date; a feature a customer asked us to switch off should come with a sentence saying
 * who asked.
 */
final class SetCapabilityOverrideRequest extends FormRequest
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
            'capability' => ['required', Rule::enum(Capability::class)],
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Say who asked for this and why, so the next operator can judge it.',
        ];
    }

    public function capability(): Capability
    {
        return Capability::from((string) $this->validated('capability'));
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = $this->validated('expires_at');

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
