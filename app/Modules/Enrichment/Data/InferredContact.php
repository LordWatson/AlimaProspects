<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\Enrichment\Services\EmailPatternInferrer;
use Spatie\LaravelData\Data;

/**
 * One contact whose email was **inferred** from a company domain and a named person — a candidate the
 * {@see EmailPatternInferrer} produced, never read off a page. It
 * carries the person it was built for so the stored contact keeps a name and title; the email is a
 * guess until the caller verifies the domain accepts mail, and even then is never proven deliverable
 * (no SMTP probe, ADR 0008), which is why the persisted contact is flagged `is_inferred` and counts
 * toward nothing in a lead's readiness.
 */
final class InferredContact extends Data
{
    public function __construct(
        public string $email,
        public string $name,
        public ?string $title = null,
    ) {}
}
