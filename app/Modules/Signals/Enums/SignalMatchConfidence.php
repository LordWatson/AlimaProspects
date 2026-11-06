<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * How sure the matcher is that a signal is about a lead the team already has (S10).
 *
 * **Shown, not hidden**, which is the risk note this phase was handed: the way account monitoring
 * goes wrong is an event attached to the wrong company, so the member is told how strong the claim
 * is and "convert it as a new lead anyway" stays a first-class choice rather than a buried escape
 * hatch.
 *
 * There is no `Low`. A weak guess is **not a match** — the matcher returns null and the signal
 * behaves exactly as it did before this phase. A case for "probably not" would be a case somebody
 * eventually trusts.
 */
enum SignalMatchConfidence: string
{
    /**
     * The team said so themselves, or the names are identical.
     *
     * A watchlist entry carrying a `lead_id` is the team's own statement that this name is that
     * lead; an exact normalised-name match is the same rule `SourcedLead::dedupeKey()` uses to
     * decide two discovered companies are one.
     */
    case Exact = 'exact';

    /**
     * The names agree once the legal form is set aside — "Acme Engineering" and "Acme Engineering
     * Ltd".
     *
     * Weaker on purpose: a journalist writes a company's name the way a reader will recognise it
     * and a registry writes it the way it was filed, so this is the common case rather than the
     * suspicious one — but it is still an inference, and the card says so.
     */
    case Likely = 'likely';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Same name',
            self::Likely => 'Probably the same',
        };
    }

    /**
     * What the member is being told, in one line.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Exact => 'The name in the article is the name on this lead.',
            self::Likely => 'The names match apart from the company type, so this is very likely the same business — check before you rely on it.',
        };
    }

    /**
     * Whether the screen should ask before treating it as the same company.
     *
     * Only the weaker case does. An exact match on a name the team themselves put on a watchlist is
     * not a question worth asking every morning.
     */
    public function warrantsConfirmation(): bool
    {
        return $this === self::Likely;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $confidence): string => $confidence->value, self::cases());
    }
}
