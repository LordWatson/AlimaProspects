<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One past invoice, flattened out of Stripe's object into the handful of fields a billing screen
 * shows: when, how much, whether it was paid, and where to get the PDF.
 *
 * The PDF link is **Stripe's own hosted URL**, not a route of ours. Rendering invoices ourselves
 * would mean reproducing the company details, tax lines and currency formatting that Stripe already
 * puts on the document of record — and getting any of it subtly wrong on a document a customer
 * files with their accounts. The URL is signed and expires, which is why it is fetched per request
 * rather than stored.
 */
final class InvoiceData extends Data
{
    public function __construct(
        public readonly string $id,
        /** Stripe's human-facing invoice number, e.g. `D1F2A3-0001`. Null on a draft. */
        public readonly ?string $number,
        public readonly CarbonImmutable $date,
        /** Total in minor units (pence). */
        public readonly int $total,
        public readonly string $currency,
        /** Stripe's status: `paid`, `open`, `void`, `uncollectible`, `draft`. */
        public readonly string $status,
        public readonly ?string $pdfUrl,
    ) {}
}
