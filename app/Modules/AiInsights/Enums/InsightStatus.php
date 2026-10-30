<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Enums;

/**
 * Where an insight sits (Phase 14): waiting on the member, or cleared away.
 *
 * The module doc plans a third state, `applied`, for the one-click ICP tweak — **it is not here**,
 * because nothing can reach it yet and an enum case no code path can produce is a promise the
 * screens would have to honour anyway. It arrives with the apply action, post-MVP.
 *
 * Dismissal is permanent for the day: the generator's dedupe key means a dismissed insight is not
 * re-created by the next run (see the `insights` migration), so "no thanks" is respected rather
 * than re-offered an hour later.
 */
enum InsightStatus: string
{
    case New = 'new';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            // "Still open" rather than "Open": the shared "Open" key is already translated as the
            // imperative verb ("افتح"), which is not what a label describing a state should say.
            self::New => 'Still open',
            self::Dismissed => 'Dismissed',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
