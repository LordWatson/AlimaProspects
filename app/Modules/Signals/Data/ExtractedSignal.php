<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Core\Enums\Currency;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Models\Signal;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * One item of the `signals.extract_event` AI output (schema-validated by the AI Platform before it
 * reaches here), mapped back to the news item it describes by {@see self::$itemIndex}. All subject
 * fields are nullable — the model leaves out what the snippet doesn't state. A result is only kept as
 * a {@see Signal} when it {@see self::meetsThreshold()}: a genuine event,
 * of a known type, clearing the confidence and relevance floors.
 */
final class ExtractedSignal extends Data
{
    private const int MAX_SUMMARY = 500;

    private const int MAX_TEXT = 255;

    public function __construct(
        public int $itemIndex,
        public bool $isEvent,
        public ?SignalEventType $eventType,
        public ?string $subjectPersonName,
        public ?string $subjectPersonRole,
        public ?string $subjectCompanyName,
        public ?string $counterpartyName,
        public ?int $amountMinor,
        public ?Currency $currency,
        public ?string $location,
        public int $confidence,
        public int $relevance,
        public string $summary,
        public string $suggestedApproach,
    ) {}

    /** Largest deal size we'll store, in major units — guards against absurd model output / overflow. */
    private const int MAX_AMOUNT_MAJOR = 1_000_000_000_000;

    /** A compliant default when the model gives no safe next step (UAE PDPL, ADR 0007). */
    private const string DEFAULT_APPROACH = 'Introduce yourself via LinkedIn or a mutual referral.';

    /**
     * Map one validated AI event object to a DTO. Amounts arrive in major units and are stored as
     * integer minor units (CLAUDE.md money rule); a currency is kept only when it resolves to a
     * supported ISO code, and an amount is dropped if its currency doesn't (an amount with no
     * currency is ambiguous). Free text is clamped.
     *
     * @param  array<string, mixed>  $item
     */
    public static function fromAiItem(array $item): self
    {
        $currency = is_string($item['currency'] ?? null)
            ? Currency::tryFrom(Str::lower(trim($item['currency'])))
            : null;

        $amount = $item['amount'] ?? null;
        $amountMinor = ($currency !== null && (is_int($amount) || is_float($amount)) && $amount >= 0 && $amount <= self::MAX_AMOUNT_MAJOR)
            ? (int) round(((float) $amount) * $currency->minorUnitFactor())
            : null;

        $eventType = is_string($item['event_type'] ?? null)
            ? SignalEventType::tryFrom($item['event_type'])
            : null;

        return new self(
            itemIndex: (int) ($item['item_index'] ?? -1),
            isEvent: (bool) ($item['is_event'] ?? false),
            eventType: $eventType,
            subjectPersonName: self::text($item['subject_person_name'] ?? null),
            subjectPersonRole: self::text($item['subject_person_role'] ?? null),
            subjectCompanyName: self::text($item['subject_company_name'] ?? null),
            counterpartyName: self::text($item['counterparty_name'] ?? null),
            amountMinor: $amountMinor,
            currency: $currency,
            location: self::text($item['location'] ?? null),
            confidence: self::clampScore($item['confidence'] ?? 0),
            relevance: self::clampScore($item['relevance'] ?? 0),
            summary: Str::limit(trim((string) ($item['summary'] ?? '')), self::MAX_SUMMARY, ''),
            suggestedApproach: self::approach($item['suggested_approach'] ?? null),
        );
    }

    /**
     * Whether this result should become a signal: a genuine event of a known type (the `event_type`
     * column is not nullable) that clears both AI floors.
     */
    public function meetsThreshold(int $minConfidence, int $minRelevance): bool
    {
        return $this->isEvent
            && $this->eventType !== null
            && $this->confidence >= $minConfidence
            && $this->relevance >= $minRelevance;
    }

    private static function text(mixed $value): ?string
    {
        $trimmed = trim((string) (is_string($value) ? $value : ''));

        // Data minimisation (ADR 0011): never store a contact detail even if the model leaks one.
        $trimmed = trim((string) preg_replace('/\S+@\S+\.\S+/', '', $trimmed));

        return $trimmed === '' ? null : Str::limit($trimmed, self::MAX_TEXT, '');
    }

    /**
     * Enforce the outreach rule in code, not just the prompt: a suggested next step must be a
     * LinkedIn or referral introduction, never cold email/phone. Anything that drifts toward a
     * contact channel is replaced with a safe default so the UI always shows a compliant line.
     */
    private static function approach(mixed $value): string
    {
        $text = trim((string) (is_string($value) ? $value : ''));

        // Also bans a bare link or a messaging handle: the approach line is read by a
        // salesperson, and an article is attacker-controlled text, so a crafted one must not be
        // able to put a clickable-looking destination in front of them (security review, S6).
        $banned = '/@|https?:\/\/|\bwww\.|\bt\.me\b|\bbit\.ly\b|\be-?mails?\b|\bcold[-\s]?(call|email)|\bphone\b|\bwhatsapp\b|\btelegram\b|\b(call|text|dm|message)\s+(them|him|her)\b/i';

        if ($text === '' || preg_match($banned, $text) === 1) {
            return self::DEFAULT_APPROACH;
        }

        return Str::limit($text, self::MAX_TEXT, '');
    }

    private static function clampScore(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }
}
