<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Models\SignalDefinitionSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One library submission, as the team's own screen and the API read it (S15 task 4).
 *
 * **This is the tenant's view, not the operator's.** It carries what the team said, what we said
 * back, and what became of it — and nothing about any other tenant. The review queue is Admin's own
 * payload, built in the panel, and it is the one that deliberately shows a type's performance
 * without showing whose it is.
 *
 * `status_label` and `status_description` are translation keys the client passes through `t()`;
 * `note` and `review_note` are **prose**, which is the one exception this codebase makes and for
 * the obvious reason — there is nothing to translate about one person's own words to another.
 *
 * @mixin SignalDefinitionSubmission
 */
final class SignalDefinitionSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalDefinitionSubmission $submission */
        $submission = $this->resource;

        return [
            'id' => $submission->id,
            'definition_id' => $submission->signal_definition_id,
            // From the relation where it is loaded, because the name is what a member recognises —
            // and `withTrashed` on the relation is what makes it render for a type they have since
            // removed while the offer waited.
            'definition_name' => $this->whenLoaded(
                'definition',
                fn (): ?string => $submission->definition?->name,
            ),
            'status' => $submission->status->value,
            'status_label' => $submission->status->label(),
            'status_description' => $submission->status->description(),
            'is_open' => $submission->status->isOpen(),
            'can_resubmit' => $submission->status->allowsResubmission(),
            'note' => $submission->note,
            'review_note' => $submission->review_note,
            'created_at' => $submission->created_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
        ];
    }
}
