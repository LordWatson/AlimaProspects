<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * The two things Alima meters. Each one has an authoritative ledger elsewhere in the app — this
 * enum is what lets Billing talk about both without caring which table they come from.
 *
 * **Units matter here more than anywhere else in the codebase.** A meter row, a plan allowance and
 * everything this enum returns are in the metric's own unit: whole leads for `Leads`, whole credits
 * for `AiCredits`. The AI ledger stores cost in *micro-units*, so credits cross that boundary
 * through {@see self::fromMicros()} / {@see self::toMicros()} and nowhere else.
 */
enum BillingMetric: string
{
    /** New companies persisted by a discovery run (`discovery_runs.leads_created`). */
    case Leads = 'leads';

    /** AI spend (`ai_requests.cost_micros`), expressed in credits. */
    case AiCredits = 'ai_credits';

    public function label(): string
    {
        return match ($this) {
            self::Leads => 'Companies found',
            self::AiCredits => 'AI credits',
        };
    }

    /**
     * What runs out when the allowance does — the sentence a member needs to understand the stop.
     */
    public function exhaustedDescription(): string
    {
        return match ($this) {
            self::Leads => 'New searches stop until the allowance resets or the plan changes.',
            self::AiCredits => 'Scoring, drafting and insights stop until the allowance resets or the plan changes.',
        };
    }

    /**
     * The config key under `billing.plans.<plan>` holding this metric's monthly allowance.
     */
    public function allowanceKey(): string
    {
        return match ($this) {
            self::Leads => 'leads',
            self::AiCredits => 'credits',
        };
    }

    /**
     * How many of the metric one *billed* overage unit covers. Leads are billed singly; credits in
     * thousands, because an invoice line per credit is unreadable.
     */
    public function overageUnit(): int
    {
        return max(1, (int) config('billing.overage.'.$this->value.'.unit', 1));
    }

    /**
     * Credits from the AI ledger's cost micro-units. Only meaningful for {@see self::AiCredits};
     * calling it on leads is a programming error, so it returns the value untouched rather than
     * inventing a conversion.
     */
    public function fromMicros(int $micros): int
    {
        if ($this !== self::AiCredits) {
            return $micros;
        }

        return intdiv($micros, self::microsPerCredit());
    }

    /**
     * The inverse of {@see self::fromMicros()}.
     */
    public function toMicros(int $amount): int
    {
        if ($this !== self::AiCredits) {
            return $amount;
        }

        return $amount * self::microsPerCredit();
    }

    /**
     * Guarded against a zero/negative config value, which would otherwise be a division by zero on
     * every AI call.
     */
    private static function microsPerCredit(): int
    {
        return max(1, (int) config('ai-platform.credits.micros_per_credit', 1_000));
    }
}
