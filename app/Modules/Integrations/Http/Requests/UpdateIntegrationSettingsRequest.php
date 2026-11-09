<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the auto-sync rule (PL7).
 *
 * Four fields, and a FormRequest rather than an inline `$request->validate()` because that is the
 * rule (`CLAUDE.md` §2: validation only in FormRequests) and because the two nullable thresholds are
 * the part worth being explicit about: **null means "do not care"**, so `nullable` is load-bearing
 * rather than lenient. A team that wants everything pushed should not have to type a zero, and a
 * zero and an absent floor are different instructions.
 */
final class UpdateIntegrationSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'auto_sync' => ['required', 'boolean'],
            'min_confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'min_fit' => ['nullable', 'integer', 'min:0', 'max:100'],
            'create_deals' => ['required', 'boolean'],
        ];
    }

    public function autoSync(): bool
    {
        return (bool) $this->validated('auto_sync');
    }

    public function minConfidence(): ?int
    {
        $value = $this->validated('min_confidence');

        return $value === null ? null : (int) $value;
    }

    public function minFit(): ?int
    {
        $value = $this->validated('min_fit');

        return $value === null ? null : (int) $value;
    }

    public function createDeals(): bool
    {
        return (bool) $this->validated('create_deals');
    }
}
