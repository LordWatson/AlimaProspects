<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\SignalTuningAdvisor;

/**
 * What a tuning suggestion proposes (S12).
 *
 * **An enum, because the application branches hard on it**: accepting an {@see self::IgnoreSource}
 * writes a watch's skip list, accepting a {@see self::RaiseConfidence} writes a definition's
 * thresholds, and accepting a {@see self::RetireTemplate} writes its query templates. That is
 * ADR 0018 §1's test — a closed set the code reasons about — where the *content* of each proposal
 * is data in `signal_tuning_suggestions.payload`.
 *
 * All three are **proposals**. {@see SignalTuningAdvisor} writes them and a member accepts or
 * declines; nothing here applies itself. A feature that silently retunes itself is a feature
 * nobody can debug: the member who asks "why did it stop finding these?" has no answer, and
 * neither does the person supporting them.
 */
enum SignalTuningKind: string
{
    /**
     * Stop showing this outlet. Accepting adds it to the watches' own skip list.
     *
     * Built from dismissals rather than from misclassifications: "I am not interested in this
     * publication" is the team's taste, which is exactly what a per-team ignore list is for.
     */
    case IgnoreSource = 'ignore_source';

    /**
     * Raise a type's confidence floor. Accepting edits the {@see SignalDefinition}'s thresholds.
     *
     * Built from **"not an event"** dismissals only
     * ({@see SignalDismissReason::indicatesMisclassification()}), because that is the one reason
     * that says the classifier was wrong rather than that the team was uninterested — and a floor
     * is the lever for a classifier that is too loose.
     */
    case RaiseConfidence = 'raise_confidence';

    /**
     * Raise a type's relevance floor. Accepting edits the same thresholds.
     *
     * Built from "not relevant" dismissals, which say the opposite thing: the events are real and
     * they are not this team's. Relevance is the lever for that, and confusing the two is how a
     * team ends up tightening the wrong screw for a month.
     */
    case RaiseRelevance = 'raise_relevance';

    /**
     * Retire a query template that has found nothing worth keeping. Accepting edits the
     * definition's templates.
     *
     * The S0 spike is the reason this is worth proposing at all: nobody can tell whether a query
     * template works by reading it, and a template that has run for weeks and produced nothing a
     * team kept is the clearest evidence this product can gather about one.
     */
    case RetireTemplate = 'retire_template';

    /**
     * **Pause this whole type**, because over a full window it has not earned what it costs (S15
     * task 3).
     *
     * The strongest thing the advisor can propose, and the one with the most evidence behind it:
     * not a dismissal rate over one outlet or one template, but the type's own funnel and its own
     * bill over `signals.roi.window_days`. A type with four hundred signals, three kept and
     * nothing converted is the product telling the truth about itself.
     *
     * **Pause, never delete.** A paused type keeps its history, its templates and its clone
     * lineage, and a team that disagrees turns it back on in one press. Deleting would throw away
     * the evidence the proposal was made from — and `signal_definitions` is soft-deleted precisely
     * because a type's record outlives the team's interest in it (ADR 0018 §4).
     */
    case RetireDefinition = 'retire_definition';

    /** A translation key naming the proposal. */
    public function label(): string
    {
        return match ($this) {
            self::IgnoreSource => 'Stop showing this source',
            self::RaiseConfidence => 'Ask for more certainty',
            self::RaiseRelevance => 'Ask for a closer match',
            self::RetireTemplate => 'Retire a search',
            self::RetireDefinition => 'Pause this signal type',
        };
    }

    /** A translation key explaining what accepting it would do. */
    public function explanation(): string
    {
        return match ($this) {
            self::IgnoreSource => 'Adds this outlet to the sources your watches skip. You can take it off again at any time.',
            self::RaiseConfidence => 'Raises how sure Alima has to be before it shows you this kind of event. Fewer, better cards.',
            self::RaiseRelevance => 'Raises how closely an event has to match you before it reaches your feed.',
            self::RetireTemplate => 'Removes one of this type’s searches. The others keep running.',
            self::RetireDefinition => 'Stops this type being searched for. Nothing is deleted — its history stays, and you can switch it back on whenever you like.',
        };
    }

    /**
     * Whether accepting this edits a {@see SignalDefinition} (as opposed to the watches).
     *
     * The accept Action branches on it, and the authorisation follows it: a proposal that edits a
     * type is authorised as a definition update, and one that edits the skip list as a watch
     * update. Two different permissions, which is why this question has to be answerable without
     * reading the payload.
     */
    public function editsDefinition(): bool
    {
        return $this !== self::IgnoreSource;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
