<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Data;

use App\Modules\Compliance\Enums\SubjectDataCategory;
use Spatie\LaravelData\Data;

/**
 * One record a data-subject request turned up (docs/compliance.md §3.5) — a contact, a message, a
 * signal, a do-not-contact entry.
 *
 * It is a **candidate for a human to confirm**, never a verdict. Matching on an address is exact;
 * matching on a name is not, and "J. Smith" is not reliably "John Smith" (§5a). The screen shows
 * what was matched and on what, and a person decides.
 *
 * `detail` is the honest minimum for recognising the record — a masked address, a company name, a
 * date. It deliberately does **not** repeat the subject's full contact details back onto a screen
 * that a whole team can open: the point of the request is usually that they want less of their data
 * in our hands, not a fresh copy of it rendered in a table. The full values go into the export the
 * subject is sent, not onto the admin screen.
 */
final class SubjectRecord extends Data
{
    public function __construct(
        public readonly SubjectDataCategory $category,
        /** The row's own id, so an erasure acts on exactly what was shown. */
        public readonly int $id,
        /** The lead this record hangs off, when there is one — the thing a member recognises. */
        public readonly ?int $leadId,
        public readonly ?string $companyName,
        /**
         * The non-translatable half of the identifying line: a masked address, a headline, a
         * company's own words. Shown as it is, because it is data.
         */
        public readonly string $detail,
        /**
         * Translation **keys** for the rest of the line — an enum's label, a channel, an outcome.
         * Keys rather than sentences because the app is EN/AR (ADR 0007) and the wording is chosen
         * on the client; a composed English string would read as English in an Arabic workspace.
         *
         * @var list<string>
         */
        public readonly array $labels,
        /** Which field matched — `email`, `phone` or `name`. Shown, because it governs confidence. */
        public readonly string $matchedOn,
        /** When the record was created, ISO-8601, or null where the row has no timestamp. */
        public readonly ?string $recordedAt,
        /** Whether an erasure would remove this row. See {@see SubjectDataCategory::isErasable()}. */
        public readonly bool $erasable,
        /** Why not, when it isn't — shown beside the row rather than left to be guessed. */
        public readonly ?string $retainedReason = null,
    ) {}
}
