<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPlaybookRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the run log — and the **refusals are the point of it** (S14).
 *
 * The questions people ask of an automation are "why didn't it draft anything for Acme?" and "what
 * would this have done last week?". So every step goes out with its status, its reason key and the
 * values the sentence is composed from, and `needs_attention` is sent so a client can surface the
 * rows somebody has to read without re-deriving which statuses those are.
 *
 * `playbook_name` is the copied one off the row rather than the relation's, so a run whose rule has
 * been deleted still says which rule it was. `playbook_id` goes out as null in that case, which is
 * how the screen knows not to offer a link.
 *
 * The signal's headline is included — it is the only thing that makes a log row recognisable — and
 * nothing else from it. No summary, no person's name: this is a list of hundreds of rows, and the
 * card in the feed is where a member reads the story.
 *
 * @mixin SignalPlaybookRun
 */
final class SignalPlaybookRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalPlaybookRun $run */
        $run = $this->resource;

        $signal = $run->relationLoaded('signal') ? $run->signal : null;
        $lead = $run->relationLoaded('lead') ? $run->lead : null;
        $actor = $run->relationLoaded('actor') ? $run->actor : null;

        return [
            'id' => $run->id,
            'playbook_id' => $run->signal_playbook_id,
            'playbook_name' => $run->playbook_name,
            'mode' => $run->mode->value,
            'is_rehearsal' => ! $run->mode->performs(),
            'signal_id' => $run->signal_id,
            'signal_headline' => $signal instanceof Signal ? $signal->headline : null,
            'signal_event_label' => $signal instanceof Signal ? $signal->eventLabel() : null,
            'lead_id' => $run->lead_id,
            'lead_company' => $lead instanceof Lead ? $lead->company_name : null,
            // Whose authority the chain acted under, which is what explains the actor on every
            // timeline entry the steps wrote.
            'actor_name' => $actor?->name,
            'applied_count' => $run->applied_count,
            'refused_count' => $run->refused_count,
            'failed_count' => $run->failed_count,
            'needs_attention' => $run->needsAttention(),
            // False means the chain was interrupted between claiming the signal and writing what
            // it did. The screen says so rather than rendering an empty run as "matched nothing".
            'is_complete' => $run->isComplete(),
            'steps' => array_map(
                static fn (PlaybookStepOutcome $outcome): array => $outcome->toPayload(),
                $run->outcomes(),
            ),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
