<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\LeadSourcing\Models\Lead;

/**
 * A legal limit on how a {@see Lead} may be approached, carried on the lead as
 * `raw.outreach_restriction` and set by whichever module created it (ADR 0007, ADR 0011).
 *
 * It lives in Core rather than in Signals because it is a **property of the lead**, not of where
 * the lead came from: Outreach (Phase 11) must honour it without knowing that Signals exists, and a
 * later source in a consent-first market sets the same flag. Absent means "no restriction beyond
 * the team's normal basis" — never "cleared to cold-email", which is the LIA's job to decide.
 *
 * Values are stable storage keys; {@see self::label()} and {@see self::guidance()} are display text.
 */
enum OutreachRestriction: string
{
    /**
     * UAE PDPL: direct marketing needs the person's prior consent, which a news article plainly
     * isn't. Approach through a channel where they choose to answer — LinkedIn or a referral — and
     * never by cold email or SMS.
     */
    case UaePriorConsent = 'uae_prior_consent';

    /** Where the flag is kept on {@see Lead::$raw}, so no caller spells the key by hand. */
    public const string RAW_KEY = 'outreach_restriction';

    public function label(): string
    {
        return match ($this) {
            self::UaePriorConsent => 'Get consent first',
        };
    }

    /**
     * The one line a member needs to see next to the lead.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::UaePriorConsent => 'Approach via LinkedIn or a referral — not cold email.',
        };
    }

    /**
     * Whether a direct marketing channel (email, SMS) may be used at all under this restriction.
     * Outreach reads this rather than comparing cases, so a new restriction can't be missed.
     */
    public function allowsDirectMarketing(): bool
    {
        return match ($this) {
            self::UaePriorConsent => false,
        };
    }

    /**
     * The restriction recorded on a lead, or null when it carries none (or an unrecognised value —
     * an old row written before a case was renamed must not crash the detail screen).
     *
     * @param  array<array-key, mixed>|null  $raw
     */
    public static function fromRaw(?array $raw): ?self
    {
        $value = $raw[self::RAW_KEY] ?? null;

        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
