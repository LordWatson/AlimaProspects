<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Data;

use App\Modules\Compliance\Actions\SaveLiaRecordAction;
use App\Modules\Core\Enums\Market;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input to {@see SaveLiaRecordAction} — a team's legitimate-interest assessment for
 * one market (docs/compliance.md §2).
 *
 * Values are clamped to their columns here, so a long assessment pasted from a Word document can't
 * 500 the write after the form has validated.
 *
 * `sign` is the difference between saving work in progress and affirming a lawful basis. It is a
 * separate flag rather than implied by completeness, because "I have filled in the boxes" and "I,
 * named, on this date, affirm this is our basis for processing" are different acts — and only the
 * second one opens the discovery gate.
 */
final class LiaRecordData extends Data
{
    public function __construct(
        public readonly Market $market,
        public readonly string $purpose,
        public readonly string $necessity,
        public readonly string $balancing,
        public readonly ?string $safeguards,
        public readonly ?string $signalsScope,
        public readonly bool $sign,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $limit = max(200, (int) config('compliance.lia.section_limit', 4000));

        return new self(
            market: Market::from((string) $validated['market']),
            purpose: self::section($validated['purpose'] ?? '', $limit) ?? '',
            necessity: self::section($validated['necessity'] ?? '', $limit) ?? '',
            balancing: self::section($validated['balancing'] ?? '', $limit) ?? '',
            safeguards: self::section($validated['safeguards'] ?? null, $limit),
            signalsScope: self::section($validated['signals_scope'] ?? null, $limit),
            sign: (bool) ($validated['sign'] ?? false),
        );
    }

    /**
     * Trim and clamp one written section. An empty string comes back as null for the optional
     * sections, so a cleared textarea stores "nothing to say" rather than a blank line the screen
     * then renders as an answer.
     */
    private static function section(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : Str::limit($trimmed, $limit, '');
    }
}
