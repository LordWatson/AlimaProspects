<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Enums;

use App\Modules\AiInsights\Services\InsightGenerator;

/**
 * What an insight is about (Phase 14). Three in the MVP, and the division that matters is not what
 * they say but **who wrote them**:
 *
 * - {@see self::StaleLeads} and {@see self::ContactToday} are **deterministic**. They are SQL over
 *   the team's own leads, they cost nothing, and they store no prose — the screen words them from
 *   `type` + `payload`, so they read in English or Arabic depending on how the member has the app
 *   set (ADR 0007).
 * - {@see self::PeriodicDigest} is **the model's reading** of the week's figures, and it is the
 *   only one that spends AI credit. Its wording is stored as written, in the team's locale.
 *
 * The split is deliberate rather than incidental: the two insights the phase promises daily are the
 * two that cannot fail, go over budget or hallucinate a number, and a provider outage costs the
 * feed its commentary rather than its contents (see {@see InsightGenerator}).
 *
 * Values are stable storage keys; labels and descriptions are translation keys the screens pass
 * through `t()`.
 */
enum InsightType: string
{
    /** Leads the team started working and then went quiet on. */
    case StaleLeads = 'stale_leads';

    /** The short list worth approaching today. */
    case ContactToday = 'contact_today';

    /** The model's read of the last week — one row per point it makes. */
    case PeriodicDigest = 'periodic_digest';

    public function label(): string
    {
        return match ($this) {
            self::StaleLeads => 'Going cold',
            self::ContactToday => 'Worth a call today',
            self::PeriodicDigest => 'What Alima noticed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::StaleLeads => 'Companies you picked up and haven’t touched since.',
            self::ContactToday => 'Your best-fit companies that nobody has approached yet.',
            self::PeriodicDigest => 'Alima’s read of the last week’s numbers.',
        };
    }

    /**
     * Whether a row of this type carries model-written prose. The screen branches on it: a
     * deterministic insight is worded on the client, an AI one is shown as written.
     */
    public function isWrittenByAi(): bool
    {
        return $this === self::PeriodicDigest;
    }

    /**
     * The severity a row of this type gets when nothing more specific applies. The AI picks its
     * own and may say "this is just interesting"; the two deterministic types know what they are.
     */
    public function defaultSeverity(): InsightSeverity
    {
        return match ($this) {
            self::StaleLeads => InsightSeverity::Warning,
            self::ContactToday => InsightSeverity::Action,
            self::PeriodicDigest => InsightSeverity::Info,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
