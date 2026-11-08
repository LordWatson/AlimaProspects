<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use Spatie\LaravelData\Data;

/**
 * One person a vendor says works at a company (PL2).
 *
 * **Nothing here is believed about whether they may be contacted.** A vendor returns a name, a
 * role and sometimes an address; whether a message may be sent to it is `OutreachGuard`'s answer
 * and nobody else's (ADR 0024's rule, and ADR 0027 §6 states it for vendors specifically).
 *
 * `email` is nullable because the people-find and email-find capabilities are separate: some
 * vendors include an address with the person, and when they do it is a free answer to the next
 * question — but it arrives with its own `emailStatus`, unverified unless the vendor says
 * otherwise, and it is never treated as confirmed because it came bundled.
 */
final class FoundPerson extends Data
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $fullName = null,
        public ?string $title = null,
        /** The vendor's own word for the level. Ranked by `SeniorityLadder`, never by an enum. */
        public ?string $seniority = null,
        public ?string $department = null,
        public ?string $linkedinUrl = null,
        public ?string $email = null,
        /** The vendor's own confidence, 0-100, where it states one. */
        public ?int $confidence = null,
    ) {}

    /**
     * The person's name as one string. Falls back to joining the parts, because a vendor that
     * returns only `first`/`last` is the common case and the display name is what a screen shows.
     */
    public function name(): ?string
    {
        if ($this->fullName !== null && trim($this->fullName) !== '') {
            return trim($this->fullName);
        }

        $parts = array_filter([$this->firstName, $this->lastName]);

        return $parts === [] ? null : trim(implode(' ', $parts));
    }

    /**
     * Whether this record names an actual person. A vendor that returns a department
     * ("Sales Team") with no name has returned a role inbox, not a decision-maker.
     */
    public function isNamed(): bool
    {
        return $this->name() !== null;
    }
}
