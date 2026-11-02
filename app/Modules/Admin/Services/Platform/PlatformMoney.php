<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Data\ConvertedCost;
use Illuminate\Support\Str;

/**
 * The one place the platform's two money scales meet (Phase 19c).
 *
 * Alima holds money in two incompatible forms, both correct in their own module:
 *
 * - **Plan revenue** — integer *minor units* of `billing.currency` (CLAUDE.md §2).
 * - **Provider cost** — integer *cost micro-units* of whatever currency the provider billed us in
 *   (`ai_requests.currency`, `discovery_runs`). Micro-units exist because a single AI call can cost
 *   a fraction of a penny, and rounding to minor units at write time would have thrown the figure
 *   away before anything summed it.
 *
 * Gross margin is the one figure that needs both, so this class converts cost onto the revenue
 * scale — and does it **loudly**. A currency with no configured rate is returned as
 * {@see ConvertedCost::$unconverted} rather than being dropped or treated as the reporting
 * currency, because the phase's own risk note is exactly this: a margin number that looks
 * authoritative while a provider's cost is unrecorded. Unrecorded cost is shown as unrecorded.
 *
 * The rate is a **configured constant**, not a market rate, and every screen that uses it prints
 * the rates it used. The alternative was a live FX call inside a nightly rollup for a figure whose
 * third significant digit nobody acts on.
 */
final class PlatformMoney
{
    private const int MICROS_PER_MAJOR_UNIT = 1_000_000;

    /** The ISO code every platform money figure is expressed in, upper-cased for display. */
    public function currency(): string
    {
        return Str::upper((string) config('admin.reporting.currency', 'gbp'));
    }

    /**
     * How many minor units of the reporting currency one major unit of `$currency` is worth, or
     * null when nobody has said.
     */
    public function rate(string $currency): ?int
    {
        $rate = config('admin.reporting.fx.'.Str::lower($currency));

        return is_numeric($rate) && (int) $rate > 0 ? (int) $rate : null;
    }

    /**
     * The configured rates, for the note every money screen carries. Keyed by upper-case ISO code.
     *
     * @return array<string, int>
     */
    public function rates(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('admin.reporting.fx', []);

        $rates = [];

        foreach ($configured as $code => $rate) {
            if (is_numeric($rate) && (int) $rate > 0) {
                $rates[Str::upper($code)] = (int) $rate;
            }
        }

        return $rates;
    }

    /**
     * Convert a map of cost micro-units keyed by currency onto the reporting currency.
     *
     * `intdiv` rather than `round`: the conversion is reported to the minor unit, and a margin
     * figure that rounded *up* on cost would understate margin — erring the safe way is the same
     * instinct `PlanResolver::fallbackPaidPlan()` follows.
     *
     * @param  array<string, int>  $microsByCurrency  upper- or lower-case ISO code => micro-units
     */
    public function convert(array $microsByCurrency): ConvertedCost
    {
        $minorUnits = 0;
        $unconverted = [];

        foreach ($microsByCurrency as $code => $micros) {
            $rate = $this->rate($code);

            if ($rate === null) {
                $key = Str::upper($code);
                $unconverted[$key] = ($unconverted[$key] ?? 0) + $micros;

                continue;
            }

            $minorUnits += intdiv($micros * $rate, self::MICROS_PER_MAJOR_UNIT);
        }

        return new ConvertedCost(
            minor_units: $minorUnits,
            unconverted: $unconverted,
            currency: $this->currency(),
        );
    }

    /**
     * Split a `t<id>|<CUR>` or `unattributed|<CUR>` rollup dimension into its two halves.
     *
     * A dimension with no currency half — which a historical row written before the split could
     * have — yields a null currency, and {@see self::convert()} then reports it as unconverted.
     * Guessing the reporting currency for it would be the quiet lie this class exists to refuse.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitCostDimension(string $dimension): array
    {
        if (! Str::contains($dimension, '|')) {
            return [$dimension, null];
        }

        return [(string) Str::before($dimension, '|'), Str::upper((string) Str::after($dimension, '|'))];
    }

    /**
     * The inverse, used by the rollup so the two halves are assembled in exactly one place.
     */
    public static function costDimension(string $owner, string $currency): string
    {
        return $owner.'|'.Str::upper($currency);
    }
}
