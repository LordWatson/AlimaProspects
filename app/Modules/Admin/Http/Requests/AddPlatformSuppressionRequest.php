<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Services\Platform\OutreachOversight;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding an identity to the **global** do-not-contact list (Phase 19c).
 *
 * Three things are deliberate about these rules.
 *
 * **The reason list is narrowed.** Only three of the five {@see SuppressionReason} cases make sense
 * at platform scope — they asked us to stop, they reported us, or we are adding it by hand. A
 * **bounce** is a per-tenant technical fact and an **erasure** belongs to the tenant that received
 * the request; recording either platform-wide would be recording something that is not true. The
 * list comes from {@see OutreachOversight::allowedReasons()}, which also drives the form, so the
 * two cannot disagree.
 *
 * **The value is not validated as an email.** The type decides what the value means, and
 * `SuppressionType::normalise()` is the only thing that knows how — it takes a pasted URL as a
 * domain and `+44 20 7946 0000` as a number. An `email` rule here would reject both. A value that
 * is not usable comes back as `AdminException::suppressionValueUnusable()` from the Action, which
 * is the same answer in the right place.
 *
 * **The note is required.** `SuppressionList` allows a null note and a *team's* own entry rightly
 * needs none. A platform entry stops every tenant contacting somebody for ever, and one with no
 * note is one the next operator cannot explain — the same reasoning that makes a plan grant's note
 * required.
 */
final class AddPlatformSuppressionRequest extends FormRequest
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
            'type' => ['required', Rule::enum(SuppressionType::class)],
            'value' => ['required', 'string', 'max:254'],
            'reason' => ['required', Rule::in(OutreachOversight::allowedReasons())],
            // 180, matching the column. A longer note would be silently truncated by the database,
            // which is how a reason turns into half a reason.
            'note' => ['required', 'string', 'max:180'],
        ];
    }

    public function type(): SuppressionType
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return SuppressionType::from((string) $validated['type']);
    }

    public function reason(): SuppressionReason
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return SuppressionReason::from((string) $validated['reason']);
    }
}
