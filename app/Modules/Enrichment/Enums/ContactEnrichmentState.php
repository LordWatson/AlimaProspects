<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

/**
 * Where one contact is in the paid-enrichment pipeline (PL2; driven by PL3's waterfall).
 *
 * **Per contact, not per lead.** `leads.enrichment_status` already tracks the free-tier pass over
 * the company, and it is a different question: a lead can be fully enriched as a company while one
 * of its four contacts is still having its mobile looked for. One column answering both would make
 * "enriched" mean nothing on either.
 *
 * {@see self::Idle} rather than a null default, so "nothing has been asked for yet" is a state the
 * UI can render rather than an absence it has to interpret.
 */
enum ContactEnrichmentState: string
{
    /** Nothing has been asked for. The state every contact starts in. */
    case Idle = 'idle';

    /** A member asked; the job is waiting for a worker. */
    case Queued = 'queued';

    /** A waterfall is running against this contact right now. */
    case Running = 'running';

    /** The waterfall finished. It does **not** mean anything was found. */
    case Done = 'done';

    /** Every provider errored, or the job died. A retry is a sensible offer. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Not looked for',
            self::Queued => 'Queued',
            self::Running => 'Looking',
            self::Done => 'Looked',
            self::Failed => 'Could not look',
        };
    }

    /**
     * Whether work is outstanding — what a spinner and a disabled button both read.
     */
    public function isInFlight(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
