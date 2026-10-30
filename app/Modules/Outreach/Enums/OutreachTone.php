<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

/**
 * The register a draft is written in (Phase 11) — the one control a member reliably wants over an
 * AI draft, and the one they can judge the result of at a glance.
 *
 * Deliberately four coarse options rather than a free-text instruction: the tone is interpolated
 * into the drafting prompt, and a free-text field there is an open door for "ignore the above"
 * (AI Platform injection rules). A team that wants to say more says it in a template, whose body
 * is wrapped as untrusted content.
 *
 * Values are stable storage keys; {@see self::label()} is a translation key.
 */
enum OutreachTone: string
{
    case Direct = 'direct';
    case Warm = 'warm';
    case Formal = 'formal';
    case Consultative = 'consultative';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Warm => 'Warm',
            self::Formal => 'Formal',
            self::Consultative => 'Consultative',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Direct => 'Short, plain, straight to the ask.',
            self::Warm => 'Friendly and human, still brief.',
            self::Formal => 'Measured and professional.',
            self::Consultative => 'Leads with their problem, not our product.',
        };
    }

    /**
     * The instruction handed to the model. Ours, not the team's — it is template text, never
     * interpolated user input.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::Direct => 'Be direct and brief. Plain words, no preamble, one clear ask.',
            self::Warm => 'Be warm and conversational without being familiar. Keep it short.',
            self::Formal => 'Be measured and professional. Full sentences, no contractions, no slang.',
            self::Consultative => 'Open on the problem they are likely to have, not on us. Earn the ask.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $tone): string => $tone->value, self::cases());
    }
}
