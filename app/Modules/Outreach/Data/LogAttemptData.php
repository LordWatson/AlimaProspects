<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Data;

use App\Modules\Outreach\Actions\LogContactAttemptAction;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for logging an approach (Phase 11) — the input to
 * {@see LogContactAttemptAction}.
 *
 * `occurredAt` defaults to now but is accepted from the member, because the common case is logging
 * a call after it has happened. It is normalised to UTC here, as the column is UTC and the team
 * reads it in their own timezone (CLAUDE.md §2).
 */
final class LogAttemptData extends Data
{
    private const int NOTES_LIMIT = 2000;

    public function __construct(
        public readonly OutreachChannel $channel,
        public readonly ContactOutcome $outcome,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?int $leadContactId = null,
        public readonly ?int $messageId = null,
        public readonly ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $notes = trim((string) ($validated['notes'] ?? ''));

        return new self(
            channel: OutreachChannel::from((string) $validated['channel']),
            outcome: ContactOutcome::tryFrom((string) ($validated['outcome'] ?? '')) ?? ContactOutcome::Pending,
            occurredAt: self::timestamp($validated['occurred_at'] ?? null),
            leadContactId: isset($validated['lead_contact_id']) ? (int) $validated['lead_contact_id'] : null,
            messageId: isset($validated['message_id']) ? (int) $validated['message_id'] : null,
            notes: $notes === '' ? null : Str::limit($notes, self::NOTES_LIMIT, ''),
        );
    }

    private static function timestamp(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return Date::now()->utc()->toDateTimeImmutable();
        }

        return Date::parse($value)->utc()->toDateTimeImmutable();
    }
}
