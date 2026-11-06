<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * What a preview did with one article, and **why** (S9).
 *
 * The reason this is six cases rather than a boolean is the whole argument for the preview. The S0
 * spike exists because nobody can tell whether a query template is any good by reading it — seven of
 * eight looked fine and returned nothing. A preview that only showed what it kept would move that
 * discovery from a developer's spike into a customer's first silent week: an empty list says
 * "something is wrong" and nothing about which thing.
 *
 * Each case below is a **different fix**. Templates returning nothing is a wording problem; events
 * scoring under the floor is a threshold problem; round-ups getting through is an `excludes` problem;
 * everything landing on a skip-listed site is a source problem; results from the wrong country is a
 * places problem. A member who can see which one they have does not need us to tell them.
 *
 * The order of {@see cases()} is the order they are evaluated in, which keeps a preview reproducible:
 * an article that is both on the skip list and not an event has exactly one verdict, every time.
 */
enum PreviewVerdict: string
{
    /** Its source is on the skip list — the product's own, or the team's. Judged before the AI. */
    case IgnoredDomain = 'ignored_domain';

    /** The AI did not read it as an event of this type at all. */
    case NotAnEvent = 'not_an_event';

    /** A real event, somewhere this watch is not looking. */
    case WrongLocation = 'wrong_location';

    /** An event, but the AI is not sure enough of it for this type's floor. */
    case BelowConfidence = 'below_confidence';

    /** An event this type is sure of, about somebody this team would not want. */
    case BelowRelevance = 'below_relevance';

    /** It would have become a signal. */
    case Kept = 'kept';

    /**
     * The AI never got to it — out of credit, or it answered unusably twice.
     *
     * Its own case because the alternative was worse: these were reported as
     * {@see self::NotAnEvent} for one phase, which told a member to rewrite templates that may be
     * perfectly good. A verdict has to name what happened, and "we could not classify this" is a
     * thing that happened to **us**, not a finding about their definition.
     */
    case Unclassified = 'unclassified';

    public function label(): string
    {
        return match ($this) {
            self::IgnoredDomain => 'Skipped source',
            self::NotAnEvent => 'Not an event',
            self::WrongLocation => 'Wrong place',
            self::BelowConfidence => 'Not sure enough',
            self::BelowRelevance => 'Not relevant enough',
            self::Kept => 'Would appear',
            self::Unclassified => 'Not checked',
        };
    }

    /**
     * What to change, in one sentence, for the member looking at this verdict.
     *
     * Advice rather than an explanation, because a verdict nobody can act on is just a label. These
     * are the sentences the preview panel shows beside each group.
     *
     * **Each one names a field by the label the form gives it** — "What doesn't", "How sure Alima
     * must be", "How well it must fit you". They said "does not count", "the relevance floor" and
     * "the AI is unsure" for one review cycle, which is advice about a form the member is not
     * looking at: there is no "relevance floor" on the screen, and "the AI" is called Alima
     * everywhere else in the product. The Arabic had drifted the same way and further, because it
     * was translated from the jargon rather than from the labels.
     */
    public function advice(): string
    {
        return match ($this) {
            self::IgnoredDomain => 'These sources are on your skip list, so a real check would never show them.',
            self::NotAnEvent => 'Your searches found these, but they read as commentary rather than events — try plainer words, or add to "What doesn’t".',
            self::WrongLocation => 'Real events, somewhere you are not watching. Add the place to a watch, or have the searches name one.',
            self::BelowConfidence => 'Likely events that Alima is not sure enough about. Lower "How sure Alima must be" to let these through.',
            self::BelowRelevance => 'Real events, about businesses outside your ideal customer. Lower "How well it must fit you", or accept that these are noise.',
            self::Kept => 'These would appear in your feed.',
            self::Unclassified => 'Alima found these but could not check them just now. Try again in a minute.',
        };
    }

    /**
     * Whether this verdict means the templates themselves are working.
     *
     * Everything except {@see self::IgnoredDomain} and {@see self::NotAnEvent} is an article that
     * *was* an event — so the templates did their job and the tuning is what is left. That
     * distinction is what the preview's summary line is about: "your templates find events, your
     * thresholds are rejecting them" is a different sentence from "your templates find nothing".
     */
    public function foundAnEvent(): bool
    {
        return match ($this) {
            // `Unclassified` is not a finding either way — nobody looked.
            self::IgnoredDomain, self::NotAnEvent, self::Unclassified => false,
            self::WrongLocation, self::BelowConfidence, self::BelowRelevance, self::Kept => true,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $verdict): string => $verdict->value, self::cases());
    }
}
