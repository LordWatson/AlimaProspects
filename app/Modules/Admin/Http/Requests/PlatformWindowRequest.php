<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Support\PlatformWindow;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The window every platform screen is read over (Phase 19c).
 *
 * ## The window is always cut in **UTC**, and that is not a default
 *
 * {@see DateRange} cuts a window in the *team's* timezone, which is right for a tenant dashboard
 * and meaningless here: an operator belongs to no team, and one who has just been impersonating
 * somebody would otherwise read the platform's figures on that customer's clock. So the timezone is
 * hard-coded, the panel labels every timestamp UTC (see `AdminLayout`), and the rollup's own dates
 * are UTC days — the three agree by construction rather than by luck.
 *
 * `DateRange::constrain()`'s rule still matters even so, and the store's own `constrain()` applies
 * it: the bounds are converted to UTC before they reach a query, so a window cut in any other
 * timezone maps to the **UTC days it actually spans** rather than to a date read off a local
 * wall clock. That is the honest answer rather than the convenient one — a Dubai-cut October spans
 * 32 UTC days, because it opens at 20:00 on 30 September UTC — and it is precisely why the panel
 * cuts every window here, in UTC, and nowhere else.
 *
 * ## Default
 *
 * Thirty days. Long enough for the growth and cost figures to have shape, short enough that the
 * margin report's proration is close to one month — which is the figure an operator is reading it
 * as.
 *
 * ## Not `final`
 *
 * Deliberately, unlike every other request in this module. Four of the Phase 19c screens need this
 * window **plus** their own filters, and the UTC rule is the one thing that must not exist in four
 * copies — a `final` class here would have meant four requests each repeating
 * `DateRange::for(..., 'UTC', ...)`, and the day one of them was written with a timezone instead
 * would be the day one screen's figures quietly moved.
 */
class PlatformWindowRequest extends FormRequest
{
    /**
     * Authorisation is the middleware stack — the admin role, the 2FA requirement and the route's
     * `admin.can:` ability — all of which have run before a FormRequest is resolved.
     */
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
            'range' => ['nullable', Rule::enum(ReportRange::class)],
            // `date_format`, not `date`: a plain day, so a caller cannot smuggle an offset into a
            // window that is deliberately cut in UTC.
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function window(): DateRange
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $range = ReportRange::tryFrom((string) ($validated['range'] ?? '')) ?? ReportRange::Last30Days;

        // See the class note. Never the team's, never the operator's — there is no operator
        // timezone to read, and reading the impersonated tenant's would be worse than none.
        // `PlatformWindow` is the only constructor of these windows, so the UTC decision and the
        // day-space comparison window live in one place.
        return PlatformWindow::for(
            $range,
            isset($validated['from']) ? (string) $validated['from'] : null,
            isset($validated['to']) ? (string) $validated['to'] : null,
        );
    }

    /**
     * The window as the screen's filter control reads it back — so a shared URL reproduces exactly
     * what the sender saw.
     *
     * @return array<string, mixed>
     */
    public function formState(): array
    {
        $window = $this->window();

        return [
            'range' => $window->preset->value,
            'from' => $window->fromDate(),
            'to' => $window->toDate(),
        ];
    }
}
