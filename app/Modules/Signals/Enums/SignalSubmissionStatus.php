<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * Where a library submission has got to (S15 task 4).
 *
 * An enum rather than data, by ADR 0018 §1's test: the application branches hard on it — the review
 * queue reads `pending`, promotion writes `promoted` and creates a preset, the team's own screen
 * shows a different sentence for each — so it is a closed set the code reasons about.
 *
 * Four cases and no "under review". An operator opening a submission is not a state change the team
 * should see: it would start a clock nobody promised to meet, and a queue of things marked "being
 * looked at" that nobody has answered is worse than a queue of things marked "waiting".
 *
 * `Withdrawn` exists because a team must be able to take an offer back — they changed the type, or
 * they thought better of it — and deleting the row would lose the fact that they asked.
 *
 * Values are stable storage keys; labels are translation keys the screens pass through `t()`.
 */
enum SignalSubmissionStatus: string
{
    /** Waiting for an operator. */
    case Pending = 'pending';

    /** Accepted into the library; `preset_id` points at what it became. */
    case Promoted = 'promoted';

    /** Turned down, with a note. May be offered again once the team has changed something. */
    case Rejected = 'rejected';

    /** The team took it back. */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Promoted => 'In the library',
            self::Rejected => 'Not accepted',
            // Its own key rather than the bare `Withdrawn`, which already exists for a compliance
            // assessment: a one-word key is almost always already taken, and the Arabic for a
            // lapsed assessment is not the Arabic for a team taking an offer back (brand review).
            self::Withdrawn => 'You withdrew it',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pending => 'We have it and will look at it. Nothing about your own type changes while you wait.',
            self::Promoted => 'It is in the shared library for every team to start from. Thank you.',
            self::Rejected => 'We did not add it this time. Your own type is untouched and still running.',
            self::Withdrawn => 'You took this back. You can offer it again whenever you like.',
        };
    }

    /** Whether an operator still has to answer it. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the team may offer this type again.
     *
     * A rejected or withdrawn submission may be re-offered — the team has presumably changed
     * something — while a promoted one is already in the library and a pending one is already
     * asked. Read rather than compared, so a fifth case is caught everywhere at once.
     */
    public function allowsResubmission(): bool
    {
        return match ($this) {
            self::Rejected, self::Withdrawn => true,
            self::Pending, self::Promoted => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
