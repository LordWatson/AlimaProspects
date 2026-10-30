<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Data;

use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Outreach\Actions\DraftMessageAction;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use Spatie\LaravelData\Data;

/**
 * What to draft, for whom, in what register and language (Phase 11). The validated input to
 * {@see DraftMessageAction}.
 *
 * Note what is *not* here: any free-text instruction to the model. The tone is an enum whose
 * instruction text is ours, and a team's own brief lives in a template whose body is wrapped as
 * untrusted content. A free-text "extra instructions" field interpolated into a system prompt is an
 * open door for "ignore the above" (AI Platform injection rules).
 */
final class DraftMessageData extends Data
{
    public function __construct(
        public readonly OutreachChannel $channel,
        public readonly OutreachTone $tone,
        public readonly SupportedLocale $locale,
        /** Which person at the company to address, when the team has picked one. */
        public readonly ?int $leadContactId = null,
        public readonly ?int $templateId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated, SupportedLocale $fallbackLocale): self
    {
        return new self(
            channel: OutreachChannel::from((string) $validated['channel']),
            tone: OutreachTone::from((string) $validated['tone']),
            // The locale the draft is written in, which is not necessarily the one the member is
            // reading the app in: a bilingual team drafts Arabic from an English UI all day.
            locale: SupportedLocale::tryFromString(
                isset($validated['locale']) ? (string) $validated['locale'] : null,
            ) ?? $fallbackLocale,
            leadContactId: isset($validated['lead_contact_id']) ? (int) $validated['lead_contact_id'] : null,
            templateId: isset($validated['template_id']) ? (int) $validated['template_id'] : null,
        );
    }
}
