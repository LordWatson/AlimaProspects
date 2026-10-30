<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Services\DigestBuilder;
use Spatie\LaravelData\Data;

/**
 * What one member's round-up says (Phase 12). Built by {@see DigestBuilder}.
 *
 * {@see self::$lines} carries **translation keys plus params** rather than sentences, for the same
 * reason every other notification does, and the mail view runs them through `__()` under the team's
 * locale. A digest with no lines is never sent — an email that says "nothing happened" is an email
 * that teaches a member to filter the next one.
 */
final class DigestSummary extends Data
{
    /**
     * @param  list<array{key: string, params: array<string, string|int>, url: string|null}>  $lines
     */
    public function __construct(
        public readonly DigestFrequency $frequency,
        public readonly array $lines = [],
        /** The total the headline reports, so "7 things happened" matches what is listed. */
        public readonly int $total = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
