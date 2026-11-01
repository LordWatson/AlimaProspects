<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Enums;

use App\Modules\AiPlatform\Observability\AiUsageCard;
use App\Modules\AiPlatform\Observability\AiUsageRecorder;

/**
 * The Pulse entry types the AI usage card is built from (Phase 20, ADR 0017).
 *
 * An enum because these are a **contract between two files** — {@see AiUsageRecorder} writes them
 * and {@see AiUsageCard} reads them back by the same name — and a contract held together by two
 * copies of five string literals is one that breaks silently. A renamed type does not error; the
 * column simply comes back null and a number on the dashboard quietly becomes zero, which is the
 * worst failure an observability surface can have.
 *
 * All five are recorded as **`sum`**, and that is a constraint rather than a preference:
 * `Pulse::aggregateTypes()` takes one aggregate for every type it reads, so a type recorded as
 * `count` could not appear in the same row as one recorded as `sum` — and a card that needs two
 * queries to fill one table loses the ability to show a prompt that has calls but no cost. A count
 * is a sum of ones.
 */
enum AiPulseMetric: string
{
    /** One per AI call, cache hits and failures included. */
    case Requests = 'ai_request';

    /** Of those, the ones served from the response cache: no provider call, no cost, no latency. */
    case Cached = 'ai_request_cached';

    /** Of those, the ones that failed — a transport error or a schema-invalid response. */
    case Failed = 'ai_request_failed';

    /** Spend in cost micro-units (CLAUDE.md §2: money is integer minor units). */
    case CostMicros = 'ai_cost';

    /** **Total** provider milliseconds, not a mean — the card divides by the calls in its own row. */
    case LatencyMs = 'ai_latency';

    /**
     * Every type, in the order the card's query asks for them.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $metric): string => $metric->value, self::cases());
    }
}
