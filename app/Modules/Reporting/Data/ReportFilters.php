<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Support\Facades\Date;
use Spatie\LaravelData\Data;

/**
 * The dashboard's filter state (Phase 13) — the one shape the request, the aggregations, the
 * Inertia props and an export URL all speak.
 *
 * Everything is narrowed on the way in: an unknown range becomes the default and a malformed date
 * becomes null, so nothing a caller sends reaches a query as anything but an enum case or a
 * `Y-m-d` string. Unknown values are **dropped, not rejected** — a bookmark from an older release
 * should still open the dashboard.
 *
 * The default is {@see ReportRange::AllTime}: the dashboard is where a member lands, and "leads in
 * your list" is an inventory question before it is a flow one. Narrowing is something they ask for.
 */
final class ReportFilters extends Data
{
    public function __construct(
        public readonly ReportRange $range = ReportRange::AllTime,
        /** `Y-m-d` in the team's timezone; read only by a custom range. */
        public readonly ?string $from = null,
        /** `Y-m-d` in the team's timezone, inclusive; read only by a custom range. */
        public readonly ?string $to = null,
    ) {}

    /**
     * Build from a loose array — a query string or validated request data.
     *
     * A date pair with no explicit range is read as a custom range: that is what a member who
     * typed two dates meant, and making them also pick "Custom" from a list would be a second step
     * for no information.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $from = self::date($input['from'] ?? null);
        $to = self::date($input['to'] ?? null);

        $range = ReportRange::tryFrom((string) ($input['range'] ?? ''))
            ?? ($from !== null && $to !== null ? ReportRange::Custom : ReportRange::AllTime);

        // Dates are meaningless outside a custom range; carrying them would put them back in the
        // URL and in the form, where they would look active.
        return $range === ReportRange::Custom
            ? new self($range, $from, $to)
            : new self($range);
    }

    public function resolve(string $timezone): DateRange
    {
        return $this->range->resolve($timezone, $this->from, $this->to);
    }

    /**
     * The canonical form: what goes in the URL and on an export link. The default range is omitted
     * so an unfiltered dashboard has a bare URL.
     *
     * @return array<string, string>
     */
    public function toParams(): array
    {
        return array_filter([
            'range' => $this->range === ReportRange::AllTime ? null : $this->range->value,
            'from' => $this->range === ReportRange::Custom ? $this->from : null,
            'to' => $this->range === ReportRange::Custom ? $this->to : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * The form the filter control binds to — every key present, so a Vue `v-model` never has to
     * cope with a missing one, and the date inputs default to the window actually being shown.
     *
     * @return array<string, string>
     */
    public function toFormState(string $timezone): array
    {
        $resolved = $this->resolve($timezone);
        $today = Date::now($timezone)->toDateString();

        return [
            'range' => $this->range->value,
            'from' => $this->from ?? $resolved->fromDate() ?? $today,
            'to' => $this->to ?? $resolved->toDate() ?? $today,
        ];
    }

    /**
     * A `Y-m-d` string, or null when the value is not one. The shape is checked here and the date's
     * validity in {@see DateRange} — "2026-02-31" is well-formed and still not a date.
     */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }
}
