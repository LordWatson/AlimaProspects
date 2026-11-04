<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Modules\Signals\Models\Signal;

/**
 * One signal worth naming in the round-up (S15 task 1).
 *
 * The digest's other lines are counts — ":count new signals waiting" — and a count is enough for
 * most of them. It is not enough for signals, and that is the whole reason this type exists: the
 * product's most time-sensitive output is a specific event at a specific company, and "you have
 * eleven" sends somebody to a feed to find out which one mattered. The window in which a call is
 * welcome is days.
 *
 * **The approach note travels with the headline**, and that is the part that is easy to leave out.
 * A member reading a Monday email about a UAE acquisition is one press away from writing a cold
 * email they may not send (`docs/compliance.md` §3.11), and the digest is read **outside** the app
 * where no screen can warn them. So the restriction comes with the line or the line does not go.
 *
 * `headline` is the article's own words, so it is **prose** and not a translation key — the one
 * thing in a notification payload that cannot be a key, because it was written by a journalist.
 * Everything Alima says about it (`approachNote`) is a key.
 */
final readonly class DigestHighlight
{
    public function __construct(
        public int $signalId,
        /** The article's own headline. Prose: a journalist wrote it. */
        public string $headline,
        /** The type's label, or its stored key where the type has been deleted. */
        public string $eventLabel,
        /** 0–100, or null where Alima has not scored it — null is not zero (ADR 0022). */
        public ?int $heat,
        /** A translation key: how this prospect may be approached, or null where nothing limits it. */
        public ?string $approachNote,
    ) {}

    /**
     * Read a highlight off a signal.
     *
     * The restriction comes from the signal's **own resolved jurisdiction** rather than from the
     * watch or the team, which is ADR 0019's rule: a UK team's watch that caught a Dubai event
     * produces a UAE-restricted lead, and keying the note on anything else is the permissive bug
     * `docs/compliance.md` §3.11 exists to prevent.
     */
    public static function fromSignal(Signal $signal): self
    {
        $market = $signal->resolvedMarket();
        // `Market::outreachRestriction()` is the one place that mapping lives — the same call
        // `ConvertSignalToLeadAction` makes, so the note in the email and the flag on the lead it
        // becomes cannot disagree.
        $restriction = $market?->outreachRestriction();

        return new self(
            signalId: (int) $signal->getKey(),
            headline: $signal->headline,
            eventLabel: $signal->eventLabel(),
            heat: $signal->heat === null ? null : (int) $signal->heat,
            approachNote: $restriction?->guidance(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'signal_id' => $this->signalId,
            'headline' => $this->headline,
            'event_label' => $this->eventLabel,
            'heat' => $this->heat,
            'approach_note' => $this->approachNote,
        ];
    }
}
