<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Data\PlanGrantData;
use App\Modules\Billing\Enums\PlanKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Granting a team a plan outside Stripe (Phase 19b).
 *
 * The **note is required**, for the same reason a suspension's reason is: a grant with no
 * explanation is one the next operator cannot safely revoke, so the customer keeps an entitlement
 * nobody can account for — or loses one they were promised.
 *
 * The three allowance fields are nullable and mean "use the plan's own number". The form says so,
 * because the alternative reading — unlimited — is what the plan's own null means, and an operator
 * who confused the two would uncap a contract.
 */
final class GrantPlanRequest extends FormRequest
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
            'plan' => ['required', Rule::enum(PlanKey::class)],
            'note' => ['required', 'string', 'min:10', 'max:500'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'leads' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'credits' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            // Not `after:now`: a contract that started on Monday is a normal thing to record on
            // Wednesday, and refusing it would make an operator lie about the date.
            'starts_at' => ['nullable', 'date'],
            'ends_at' => [
                'nullable',
                'date',
                // `after:starts_at` only when there is a `starts_at` to be after. Absent, the
                // Action defaults the start to now, so `after:now` is the same rule stated against
                // the value that will actually be used.
                'after:'.($this->filled('starts_at') ? 'starts_at' : 'now'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.min' => 'Say why this team has a plan nobody is paying Stripe for.',
        ];
    }

    public function grant(): PlanGrantData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new PlanGrantData(
            plan: PlanKey::from((string) $validated['plan']),
            note: trim((string) $validated['note']),
            startsAt: $this->instant($validated['starts_at'] ?? null) ?? CarbonImmutable::now('UTC'),
            endsAt: $this->instant($validated['ends_at'] ?? null),
            seats: $this->nullableInt($validated['seats'] ?? null),
            leads: $this->nullableInt($validated['leads'] ?? null),
            credits: $this->nullableInt($validated['credits'] ?? null),
        );
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value)->utc()
            : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
