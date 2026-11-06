<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Models\Signal;

/**
 * One of the signals a playbook preview matched, as the screen shows it (S14).
 *
 * Deliberately **thin**: the headline, what kind of event it was, how hot, and whether it was about
 * a company the team already has. Enough for a member to recognise the story and say "yes, that is
 * what I meant" — which is the only question the preview asks.
 *
 * It carries no person's name and no summary. The preview is a confirmation dialog, not a feed, and
 * a name on it would be the module's data-minimisation rule relaxed for a screen that did not need
 * it.
 */
final readonly class PreviewedSignal
{
    public function __construct(
        public int $id,
        public string $headline,
        /** The event type's own label, or its stored key when the type has been deleted. */
        public string $eventLabel,
        public ?int $heat,
        public bool $aboutExistingLead,
        public ?string $publishedAt,
    ) {}

    public static function fromSignal(Signal $signal): self
    {
        return new self(
            id: (int) $signal->getKey(),
            headline: $signal->headline,
            eventLabel: $signal->eventLabel(),
            heat: $signal->heat === null ? null : (int) $signal->heat,
            aboutExistingLead: $signal->matched_lead_id !== null,
            publishedAt: $signal->published_at?->toIso8601String(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'headline' => $this->headline,
            'event_label' => $this->eventLabel,
            'heat' => $this->heat,
            'about_existing_lead' => $this->aboutExistingLead,
            'published_at' => $this->publishedAt,
        ];
    }
}
