<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Data;

use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Outreach\Actions\SaveOutreachTemplateAction;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for a team's message brief — the input to
 * {@see SaveOutreachTemplateAction}. Values are clamped to their columns here, so a pasted brief
 * can't 500 the write after the form has validated.
 */
final class OutreachTemplateData extends Data
{
    private const int NAME_LIMIT = 80;

    private const int SUBJECT_LIMIT = 180;

    private const int BODY_LIMIT = 4000;

    public function __construct(
        public readonly string $name,
        public readonly OutreachChannel $channel,
        public readonly OutreachTone $tone,
        public readonly SupportedLocale $locale,
        public readonly string $body,
        public readonly ?string $subject = null,
        public readonly bool $isDefault = false,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $channel = OutreachChannel::from((string) $validated['channel']);
        $subject = trim((string) ($validated['subject'] ?? ''));

        return new self(
            name: Str::limit(Str::squish((string) $validated['name']), self::NAME_LIMIT, ''),
            channel: $channel,
            tone: OutreachTone::from((string) $validated['tone']),
            locale: SupportedLocale::tryFromString(
                isset($validated['locale']) ? (string) $validated['locale'] : null,
            ) ?? SupportedLocale::default(),
            body: Str::limit(trim((string) $validated['body']), self::BODY_LIMIT, ''),
            // Dropped rather than stored for a channel with no subject line: keeping it would put a
            // field on the row that nothing will ever read, and that a later channel change would
            // silently resurrect.
            subject: $channel->hasSubject() && $subject !== ''
                ? Str::limit($subject, self::SUBJECT_LIMIT, '')
                : null,
            isDefault: (bool) ($validated['is_default'] ?? false),
        );
    }
}
