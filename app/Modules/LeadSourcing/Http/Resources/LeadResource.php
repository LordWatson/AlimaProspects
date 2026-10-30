<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Resources;

use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Http\Resources\LeadContactResource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Contracts\LeadRestrictionResolver;
use App\Modules\Outreach\Services\OutreachGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lead
 */
final class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'enrichment_status' => $this->enrichment_status->value,
            'enrichment_status_label' => $this->enrichment_status->label(),
            'company_name' => $this->company_name,
            'company_number' => $this->company_number,
            'company_status' => $this->company_status,
            'company_type' => $this->company_type,
            'industry' => $this->industry,
            'employee_range' => $this->employee_range,
            'technologies' => $this->technologies ?? [],
            'incorporated_on' => $this->incorporated_on?->toDateString(),
            'website' => $this->website,
            'locality' => $this->locality,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'sic_codes' => $this->sic_codes ?? [],
            'source_url' => $this->source_url,
            'collected_at' => $this->collected_at?->toIso8601String(),
            'enriched_at' => $this->enriched_at?->toIso8601String(),
            'has_personal_data' => $this->has_personal_data,
            // A legal limit on how this company may be approached, when one applies (ADR 0007) —
            // e.g. a lead converted from a UAE signal, where PDPL needs prior consent that a news
            // article plainly isn't. Sent on every lead, not just the detail screen: Outreach
            // (Phase 11) and the mobile app both have to honour it, and a flag the client has to
            // ask for separately is a flag that gets missed.
            'outreach_restriction' => $this->outreachRestriction(),
            // Resolved to a plain list rather than handed over as a resource collection: a nested
            // collection keeps its own `data` wrapper, which would leave the client reading
            // `lead.contacts.data` on one screen and `lead.contacts` on the next.
            'contacts' => $this->whenLoaded(
                'contacts',
                fn (): array => LeadContactResource::collection($this->contacts)->resolve($request),
            ),
            // The count alone for a list row (Phase 10), so the leads table can say "3 contacts"
            // without loading three contacts per lead.
            'contacts_count' => $this->whenCounted('contacts'),
            // --- Leads Workspace (Phase 10b), each only when the caller loaded it ---
            // `stage` is null when the lead has never been placed, which the board reads as "in the
            // default column" (see the `pipeline_stage_id` migration) rather than as "nowhere".
            'stage' => $this->whenLoaded('pipelineStage', fn (): ?array => $this->pipelineStage === null ? null : [
                'id' => $this->pipelineStage->id,
                'name' => $this->pipelineStage->name,
                'outcome' => $this->pipelineStage->outcome?->value,
            ]),
            'tags' => $this->whenLoaded('tags', fn (): array => $this->tags
                ->map(static fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name])
                ->values()
                ->all()),
            'assignees' => $this->whenLoaded('assignments', fn (): array => $this->assignments
                ->map(static fn ($assignment): ?array => $assignment->user === null ? null : [
                    'id' => $assignment->user->id,
                    'name' => $assignment->user->name,
                ])
                ->filter()
                ->values()
                ->all()),
            'open_tasks_count' => $this->whenCounted('tasks'),
            // The grade against the team's active ICP (Phase 9), when `activeScore` was loaded —
            // enough for a badge in a list; the score screen serialises the full LeadScoreResource.
            'score' => $this->whenLoaded('activeScore', fn (): ?array => $this->activeScore === null ? null : [
                'score' => $this->activeScore->score,
                'grade' => $this->activeScore->grade->value,
                'grade_label' => $this->activeScore->grade->label(),
                'excluded' => $this->activeScore->excluded,
                'scored_at' => $this->activeScore->scored_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * The restriction in force for this lead, flattened to what a screen needs to show it. Null
     * when it carries none, which is the ordinary case.
     *
     * Asked of {@see LeadRestrictionResolver} rather than read off `raw`, so the flag a client is
     * sent is the same answer {@see OutreachGuard} will give it. Compliance (Phase 15) binds a
     * market-aware resolver, which means a UAE-sourced company carries the prior-consent
     * restriction here even though nothing stamped it on the row — and a screen that said
     * otherwise would promise an email the guard then refuses.
     *
     * The team comes from the active {@see TeamContext}, which costs one query per request however
     * many leads a page serialises. Without a team in context — a console render, a test building
     * a resource by hand — the resolver is skipped and the recorded flag is reported, which is the
     * answer that needs no tenant to compute.
     *
     * @return array{value: string, label: string, guidance: string, allows_direct_marketing: bool}|null
     */
    private function outreachRestriction(): ?array
    {
        $team = app(TeamContext::class)->team();

        $restriction = $team === null
            ? OutreachRestriction::fromRaw($this->raw)
            : app(LeadRestrictionResolver::class)->restrictionFor($team, $this->resource);

        if ($restriction === null) {
            return null;
        }

        return [
            'value' => $restriction->value,
            'label' => $restriction->label(),
            'guidance' => $restriction->guidance(),
            'allows_direct_marketing' => $restriction->allowsDirectMarketing(),
        ];
    }
}
