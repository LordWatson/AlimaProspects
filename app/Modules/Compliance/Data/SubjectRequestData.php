<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Data;

use App\Modules\Compliance\Enums\SubjectRequestType;
use App\Modules\Core\Support\ContactIdentity;
use Spatie\LaravelData\Data;

/**
 * What a public data-subject request carries (PL5).
 *
 * The email is **normalised through {@see ContactIdentity}** on the way in rather than stored as
 * typed, because every downstream use of it is an identity comparison: the duplicate check, the
 * suppression entry, the per-team locate. A row holding `John@Acme.com ` would suppress one
 * identity and search for another.
 */
final class SubjectRequestData extends Data
{
    public function __construct(
        public SubjectRequestType $type,
        public string $email,
        public ?string $name = null,
        public ?string $company = null,
        public ?string $details = null,
        /** Optional, and only useful for an opt-out or erasure: it is a second identity to suppress. */
        public ?string $phone = null,
    ) {}
}
