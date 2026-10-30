<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * The kinds of record a data-subject request can turn up (docs/compliance.md §3.5).
 *
 * The categories are what a subject is entitled to be told — "you hold my address on a contact
 * record, you drafted two messages to me, and a news article about me is in your feed" — so they
 * are named after the thing from the subject's point of view rather than after the table.
 *
 * {@see self::isErasable()} is the one piece of policy carried here: a **signal on a converted
 * lead** and a **suppression entry** are both reported and neither is erased. The first is the
 * provenance of a lead someone is working (docs/compliance.md §5a); the second is the record of
 * the person's own instruction not to be contacted, and erasing it would be the one "erasure" that
 * harms them.
 */
enum SubjectDataCategory: string
{
    /** A contact record on a lead: name, title, email, phone. */
    case Contact = 'contact';

    /** A company record whose own fields name the person (a sole trader, a named owner). */
    case Lead = 'lead';

    /** A drafted or sent message addressed to them. */
    case Message = 'message';

    /** A logged approach — a call, a LinkedIn message, an email. */
    case Attempt = 'attempt';

    /** A published-news signal naming them (Signals module, §5a). */
    case Signal = 'signal';

    /** Their entry on the do-not-contact list. */
    case Suppression = 'suppression';

    public function label(): string
    {
        return match ($this) {
            self::Contact => 'Contact record',
            self::Lead => 'Company record',
            self::Message => 'Drafted message',
            self::Attempt => 'Logged approach',
            self::Signal => 'News signal',
            self::Suppression => 'Do-not-contact entry',
        };
    }

    /**
     * Whether a record in this category can, in principle, be erased by the DSAR tooling.
     *
     * A **suppression** never can, and that is not a limitation: the entry exists because the
     * person asked not to be contacted, it holds a hash and a mask rather than their address, and
     * removing it would let the next discovery run reach them again. A subject who wants it gone
     * is asking for something that works against them, and the screen says so rather than offering
     * a button.
     */
    public function isErasable(): bool
    {
        return $this !== self::Suppression;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
