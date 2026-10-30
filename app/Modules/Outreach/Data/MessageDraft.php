<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Data;

use App\Modules\Outreach\Enums\OutreachChannel;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * What the drafting model came back with (Phase 11): a subject, where the channel has one, and one
 * or more variants to choose between.
 *
 * Hydrated from the schema-valid `outreach.draft_message` output. The hydration is defensive about
 * two things the schema can't express:
 *
 * - **Variants for other channels are dropped.** The prompt's schema allows a `channel` on each
 *   variant, and a model asked for a LinkedIn message has been known to helpfully throw in an
 *   email as well. Keeping it would put a draft on the screen for a channel the member didn't ask
 *   for — and, for a restricted lead, for a channel the guard has already refused.
 * - **Everything is clamped to its column.** A body longer than the `text` column or a subject past
 *   180 characters must not 500 the write after the model has already been paid for.
 */
final class MessageDraft extends Data
{
    /**
     * @param  list<string>  $variants  Bodies, in the order the model offered them. Never empty.
     */
    public function __construct(
        public readonly OutreachChannel $channel,
        public readonly ?string $subject,
        public readonly array $variants,
    ) {}

    /** The subject column's width. */
    private const int SUBJECT_LIMIT = 180;

    /**
     * A generous ceiling on one variant. Far longer than any outreach message, and short enough
     * that a model that forgets to stop can't fill the column.
     */
    private const int BODY_LIMIT = 8000;

    /** More than a member will ever read through. */
    private const int MAX_VARIANTS = 5;

    /**
     * Hydrate from `outreach.draft_message` output, or return null when nothing usable came back.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromAi(array $data, OutreachChannel $channel): ?self
    {
        $variants = [];

        foreach (self::rows($data) as $row) {
            if (! is_array($row)) {
                continue;
            }

            // A variant for a channel we didn't ask for is not a variant, it is a different
            // message. An absent channel is taken as the one requested — the common, benign case.
            $rowChannel = isset($row['channel']) ? OutreachChannel::tryFrom((string) $row['channel']) : $channel;

            if ($rowChannel !== $channel) {
                continue;
            }

            $body = trim((string) ($row['body'] ?? ''));

            if ($body === '') {
                continue;
            }

            $variants[] = Str::limit($body, self::BODY_LIMIT, '');

            if (count($variants) === self::MAX_VARIANTS) {
                break;
            }
        }

        if ($variants === []) {
            return null;
        }

        $subject = trim((string) ($data['subject'] ?? ''));

        return new self(
            channel: $channel,
            // A LinkedIn message or a call script has no subject line; a model that volunteers one
            // anyway has written something with nowhere to go.
            subject: $channel->hasSubject() && $subject !== ''
                ? Str::limit($subject, self::SUBJECT_LIMIT, '')
                : null,
            variants: $variants,
        );
    }

    /**
     * The chosen variant — the first, which is the model's own preferred ordering.
     */
    public function body(): string
    {
        return $this->variants[0];
    }

    /**
     * The variants as they are stored on the message row.
     *
     * @return list<array{channel: string, body: string}>
     */
    public function toStoredVariants(): array
    {
        return array_map(
            fn (string $body): array => ['channel' => $this->channel->value, 'body' => $body],
            $this->variants,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private static function rows(array $data): array
    {
        $variants = $data['variants'] ?? null;

        return is_array($variants) ? array_values($variants) : [];
    }
}
