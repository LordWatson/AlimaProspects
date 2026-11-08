<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Data;

use App\Modules\LeadSourcing\Actions\RecordLeadEvidenceAction;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use DateTimeInterface;
use Spatie\LaravelData\Data;

/**
 * One reason, on its way in. What a caller hands {@see RecordLeadEvidenceAction} before it becomes
 * a {@see LeadEvidence} row.
 *
 * The DTO exists so every producer — a discovery provider, a converted signal, an import, a member
 * typing a company in — describes a reason the same way, and so the two things a caller must not
 * decide for itself stay out of its hands: the **reliability** (config's, at the moment of capture)
 * and the **dedupe identity** (derived, so the same fact arriving twice from the same place cannot
 * count twice).
 */
final class EvidenceItem extends Data
{
    /**
     * @param  array<string, mixed>  $payload  the producer's own output, for audit; never rendered
     * @param  DateTimeInterface|null  $observedAt  when the event happened — null where nobody stated a date,
     *                                              which the decay then reads as the capture date rather than as "undated and therefore fresh"
     */
    public function __construct(
        public EvidenceKind $kind,
        public string $source,
        public string $title,
        public ?string $summary = null,
        public ?string $sourceLabel = null,
        public ?string $sourceUrl = null,
        public ?DateTimeInterface $observedAt = null,
        public array $payload = [],
        public ?int $signalId = null,
    ) {}

    /**
     * The lead's own provenance, as a reason: *this company exists, and here is the record we read
     * it from*.
     *
     * Every lead gets one of these as it is created, whatever path created it, which is what makes
     * "every lead has at least one item of evidence" structural rather than a thing each new
     * creation path has to remember. A registry record and a directory listing are different kinds
     * of fact, so the kind follows the source rather than being fixed here.
     */
    public static function provenanceOf(Lead $lead): self
    {
        $source = $lead->source;

        return new self(
            kind: $source->evidenceKind(),
            source: $source->value,
            title: $source->provenanceTitle(),
            summary: $lead->company_number === null
                ? null
                : (string) __('Company number :number.', ['number' => $lead->company_number]),
            sourceLabel: $source->label(),
            sourceUrl: $lead->source_url,
            // A company's own incorporation date is the one dated fact a registry record carries,
            // and it is what makes "established 2009" age gracefully rather than looking like
            // today's news. Falls back to when we collected it.
            observedAt: $lead->incorporated_on ?? $lead->collected_at,
        );
    }
}
