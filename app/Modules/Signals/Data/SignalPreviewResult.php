<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\PreviewVerdict;
use Spatie\LaravelData\Data;

/**
 * What one preview found, what it cost, and the one sentence a member needs (S9).
 *
 * The counts are not decoration. "Nothing came back" and "forty things came back and your floors
 * rejected all of them" are different problems with different fixes, and a list of cards does not
 * say which one somebody has. {@see self::diagnosis()} is the sentence that does.
 */
final class SignalPreviewResult extends Data
{
    /**
     * @param  list<PreviewedItem>  $items
     * @param  list<string>  $queries  What was actually searched, after the preview's own ceiling.
     */
    public function __construct(
        public readonly array $items,
        public readonly array $queries,
        public readonly int $queriesRequested,
        public readonly int $costMicros,
        /**
         * Whether the AI actually classified the sample.
         *
         * False when it was out of credit or answered unusably, in which case the verdicts say
         * `Not checked` and the diagnosis must not blame the member's templates for it.
         */
        public readonly bool $classified = true,
    ) {}

    /** How many would have become signals. */
    public function keptCount(): int
    {
        return count(array_filter(
            $this->items,
            static fn (PreviewedItem $item): bool => $item->verdict === PreviewVerdict::Kept,
        ));
    }

    /** How many were real events of this type, whatever happened to them afterwards. */
    public function eventCount(): int
    {
        return count(array_filter(
            $this->items,
            static fn (PreviewedItem $item): bool => $item->verdict->foundAnEvent(),
        ));
    }

    /** How many articles the queries returned at all. */
    public function resultCount(): int
    {
        return count($this->items);
    }

    /**
     * Whether the preview was trimmed to its ceiling — a preview is a sample, and says so.
     */
    public function wasTrimmed(): bool
    {
        return $this->queriesRequested > count($this->queries);
    }

    /**
     * The one sentence that tells a member which problem they have.
     *
     * In order of what is worth saying first: nothing found at all is a templates problem; events
     * found but none kept is a thresholds problem; anything kept is a working definition. The S0
     * spike is why this is phrased about the **templates** rather than about the feature — seven of
     * eight hand-written templates returned zero results, and nobody could tell by reading them.
     */
    public function diagnosis(): string
    {
        if (! $this->classified && $this->resultCount() > 0) {
            return 'Your searches found news, but Alima could not check it just now. Try again in a minute — nothing is wrong with your signal type.';
        }

        if ($this->resultCount() === 0) {
            return 'These searches found no news at all this week. The templates are probably too specific — try plainer keywords.';
        }

        if ($this->eventCount() === 0) {
            return 'The searches found news, but none of it reads as this kind of event. Try plainer templates, or sharpen "what counts".';
        }

        if ($this->keptCount() === 0) {
            return 'Real events came back, and your thresholds rejected all of them. Lower how sure Alima must be, or the relevance floor.';
        }

        return 'This signal type works. Save it and add it to a watch.';
    }
}
