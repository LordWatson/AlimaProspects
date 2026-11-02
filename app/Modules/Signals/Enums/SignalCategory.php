<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\SignalDefinition;

/**
 * How the signal-definition library is grouped (ADR 0018, S7): the section a
 * {@see SignalDefinition} appears under when a team browses the presets or their own types.
 *
 * This **is** an enum, where the event type is not, and the distinction is the one ADR 0018 §1
 * draws: the application branches on a category (it renders a section per case, in this order,
 * with this heading), while it only ever carries and displays an event key. A closed set the code
 * reasons about is an enum.
 *
 * Values are stable storage keys; {@see self::label()} and {@see self::description()} are
 * translation keys resolved on the client (the UI is EN/AR, ADR 0007).
 */
enum SignalCategory: string
{
    /** Someone came into money: a sale, an exit, a secondary, a listing. */
    case Liquidity = 'liquidity';

    /** The business is growing: funding, expansion, a new market. */
    case Growth = 'growth';

    /** Someone or something moved: a relocation, a new office, a family office opening. */
    case Movement = 'movement';

    /** Something is going wrong: insolvency, administration, overdue accounts. */
    case Distress = 'distress';

    /** They are hiring: a surge, a new function, a senior appointment. */
    case Hiring = 'hiring';

    /** A registry or regulator said so: a filing, a permission, a licence. */
    case Regulatory = 'regulatory';

    /** The team's own idea, which fits none of the above. The default for a hand-authored type. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Liquidity => 'Liquidity',
            self::Growth => 'Growth',
            self::Movement => 'Movement',
            self::Distress => 'Distress',
            self::Hiring => 'Hiring',
            self::Regulatory => 'Regulatory',
            self::Custom => 'Your own',
        };
    }

    /**
     * The one-line section explainer shown above the group in the library.
     */
    public function description(): string
    {
        return match ($this) {
            self::Liquidity => 'Someone just came into money.',
            self::Growth => 'The business is growing and has capital to spend.',
            self::Movement => 'A person, a business or a head office has moved.',
            self::Distress => 'Something is going wrong, and that is its own kind of opening.',
            self::Hiring => 'They are hiring, which says where they are investing.',
            self::Regulatory => 'A registry, regulator or authority has published something.',
            self::Custom => 'Signal types your team wrote.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
