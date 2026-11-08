<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\Enrichment\Enums\PhoneType;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;
use Spatie\LaravelData\Data;

/**
 * What one provider returned for one question (PL2): an email address, or a phone number, or
 * nothing.
 *
 * **Two states, and a miss is one of them.** A provider that cannot find an address returns this
 * with `value` null rather than throwing, because a miss is the *common* case in a waterfall — it
 * is the reason a waterfall exists — and an exception for the common case produces orchestration
 * made of `try`/`catch`. A provider that genuinely failed (an exhausted account, a timeout past its
 * retries, a response we cannot read) throws {@see EnrichmentProviderException},
 * which PL3 records as an attempt with an `error` outcome and moves to the next provider.
 *
 * That is the distinction ADR 0023 §4a drew for signal sources, in the other direction: there, the
 * question was whether a 4xx meant the provider was unwell or the query was silly. Here it is
 * whether "no" is an answer or a failure. Both times, conflating them costs money.
 *
 * `raw` is the provider's own payload, kept for the attempt record. It is **never** rendered and
 * never logged: it holds an address or a mobile number.
 */
final class EnrichmentResult extends Data
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        /** The provider's config key, so an attempt can be attributed without guessing. */
        public string $provider,
        /** The address or number found, or **null for a miss**. */
        public ?string $value = null,
        /** The provider's own confidence, 0-100, where it states one. */
        public ?int $confidence = null,
        /** For a phone result: what kind of number the vendor says it is. */
        public ?PhoneType $phoneType = null,
        /** What this call cost us, in micros, already resolved for the outcome. */
        public int $costMicros = 0,
        public array $raw = [],
    ) {}

    public static function miss(string $provider, int $costMicros = 0): self
    {
        return new self(provider: $provider, costMicros: $costMicros);
    }

    /**
     * Whether the provider answered the question.
     */
    public function found(): bool
    {
        return $this->value !== null && trim($this->value) !== '';
    }
}
