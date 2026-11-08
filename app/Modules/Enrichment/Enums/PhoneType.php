<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

/**
 * What sort of number a phone contact is (PL2) — which is the difference between reaching a person
 * and reaching a receptionist, and it is also a compliance distinction.
 *
 * The product branches on it in three places, which is why it is an enum: a mobile is what a
 * member actually wants and what costs the most to find; a switchboard is a company number and so
 * is **not personal data** in the way a direct dial is; and only a mobile is worth a WhatsApp deep
 * link on the mobile app.
 */
enum PhoneType: string
{
    /** A personal mobile. The expensive one, and the one that reaches a person.  */
    case Mobile = 'mobile';

    /** A desk line that rings one person rather than the building. */
    case Direct = 'direct';

    /** The company's published number. A business fact, not a person's. */
    case Switchboard = 'switchboard';

    /** A number with no stated kind — a vendor that did not say, or our own extraction. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Mobile => 'Mobile',
            self::Direct => 'Direct line',
            self::Switchboard => 'Switchboard',
            self::Unknown => 'Phone',
        };
    }

    /**
     * Whether this number identifies a **person** rather than a company.
     *
     * Read by compliance: a switchboard is a business contact detail a company publishes, while a
     * mobile or a direct dial belongs to an individual, and the two are not the same thing under
     * PECR or the UAE's PDPL (docs/compliance.md). A number of unknown kind is treated as a
     * person's, because the cautious answer is the one that cannot be wrong in the costly direction.
     */
    public function isPersonal(): bool
    {
        return $this !== self::Switchboard;
    }

    /**
     * Whether a "message on WhatsApp" link makes any sense for this number (the mobile app's
     * `wa.me` deep link). A landline would open a chat that can never be delivered.
     */
    public function acceptsMessaging(): bool
    {
        return $this === self::Mobile;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
