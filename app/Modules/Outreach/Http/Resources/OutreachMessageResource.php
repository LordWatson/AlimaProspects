<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Resources;

use App\Modules\Outreach\Models\OutreachMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A drafted or sent message.
 *
 * `sent_to` is **not** exposed. It is on the row as the audit of where the message went, and the
 * client already knows which contact was chosen; sending a prospect's address back out to every
 * list view would put personal data in a payload that has no use for it (docs/compliance.md §3.9).
 *
 * Labels are translation keys, not finished strings (EN/AR, ADR 0007).
 *
 * @mixin OutreachMessage
 */
final class OutreachMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'lead_contact_id' => $this->lead_contact_id,
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'tone' => $this->tone->value,
            'tone_label' => $this->tone->label(),
            'locale' => $this->locale->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'subject' => $this->subject,
            'body' => $this->body,
            // Every variant the model offered, so switching between them costs nothing.
            'variants' => array_map(
                static fn (array $variant): string => (string) ($variant['body'] ?? ''),
                $this->variants ?? [],
            ),
            'template_id' => $this->outreach_template_id,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'author' => $this->whenLoaded('creator', fn (): ?array => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            // Whether this draft is still usable. Computed here rather than inferred from the
            // status on the client, so a new status can't accidentally become re-sendable.
            'is_spent' => $this->isSpent(),
            'can_send' => ! $this->isSpent()
                && $this->channel->isSendable()
                && ($request->user()?->can('send', $this->resource) ?? false),
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
        ];
    }
}
