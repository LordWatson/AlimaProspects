<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Resources;

use App\Modules\Outreach\Models\ContactAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One recorded approach. Labels are translation keys (EN/AR, ADR 0007).
 *
 * @mixin ContactAttempt
 */
final class ContactAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'message_id' => $this->outreach_message_id,
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'outcome' => $this->outcome->value,
            'outcome_label' => $this->outcome->label(),
            'is_positive' => $this->outcome->isPositive(),
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'notes' => $this->notes,
            // The person approached, when one was named. Title and name only — the address is not
            // part of the history of an approach, and the contact panel has it already.
            'contact' => $this->whenLoaded('contact', fn (): ?array => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'title' => $this->contact->title,
            ]),
            'author' => $this->whenLoaded('creator', fn (): ?array => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
        ];
    }
}
