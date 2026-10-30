<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Data;

use App\Modules\Compliance\Actions\SaveRetentionPolicyAction;
use Spatie\LaravelData\Data;

/**
 * The validated input to {@see SaveRetentionPolicyAction} — one team's retention windows
 * (docs/compliance.md §3.4).
 *
 * The bounds are applied in the FormRequest and again by the Action against
 * `Compliance\Services\DataRetention`, which is also what the sweep reads. Clamped here too, so a
 * value that got past both can't be written to a column that will not hold it.
 */
final class RetentionPolicyData extends Data
{
    public function __construct(
        public readonly bool $isEnabled,
        public readonly int $uncontactedLeadDays,
        public readonly int $contactedLeadDays,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            isEnabled: (bool) ($validated['is_enabled'] ?? true),
            uncontactedLeadDays: (int) $validated['uncontacted_lead_days'],
            contactedLeadDays: (int) $validated['contacted_lead_days'],
        );
    }
}
