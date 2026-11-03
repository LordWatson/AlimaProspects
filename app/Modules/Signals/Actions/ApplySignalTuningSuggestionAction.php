<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Enums\SignalTuningKind;
use App\Modules\Signals\Enums\SignalTuningStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalTuningSuggestion;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\IgnoredDomains;
use Illuminate\Support\Facades\DB;

/**
 * Accepts or declines a tuning suggestion (S12).
 *
 * **Accepting is an ordinary edit, audited as one.** That is the whole point of the suggestion
 * machinery: the advisor proposes, and when a member says yes the product makes exactly the change
 * they could have made by hand — the same columns, the same audit entry — rather than a special
 * kind of write with its own rules. Somebody reading the audit log a month later sees a threshold
 * change with an actor's name on it, which is what they need; the suggestion row is the extra
 * context for why.
 *
 * **Declining writes nothing but the status**, and the row is kept. {@see SignalTuningAdvisor}
 * reads it before proposing, so a suggestion the team turned down is never raised again. Deleting
 * it would make the advisor ask every week, which is the difference between a suggestion and a nag.
 *
 * Idempotent in the way that matters: a suggestion the team has already answered is **refused**
 * rather than applied twice, because applying a ten-point threshold nudge twice is a twenty-point
 * change nobody asked for.
 */
final class ApplySignalTuningSuggestionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws SignalsException when the suggestion has already been answered, or when what it was
     *                          about has since been deleted.
     */
    public function accept(SignalTuningSuggestion $suggestion, User $actor): SignalTuningSuggestion
    {
        return DB::transaction(function () use ($suggestion, $actor): SignalTuningSuggestion {
            /** @var SignalTuningSuggestion $locked */
            $locked = SignalTuningSuggestion::query()
                ->whereKey($suggestion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isSettled()) {
                throw SignalsException::tuningSuggestionSettled();
            }

            match ($locked->kind) {
                SignalTuningKind::IgnoreSource => $this->ignoreSource($locked, $actor),
                SignalTuningKind::RaiseConfidence,
                SignalTuningKind::RaiseRelevance => $this->raiseThreshold($locked, $actor),
                SignalTuningKind::RetireTemplate => $this->retireTemplate($locked, $actor),
            };

            return $this->settle($locked, SignalTuningStatus::Accepted, $actor);
        });
    }

    public function decline(SignalTuningSuggestion $suggestion, User $actor): SignalTuningSuggestion
    {
        return DB::transaction(function () use ($suggestion, $actor): SignalTuningSuggestion {
            /** @var SignalTuningSuggestion $locked */
            $locked = SignalTuningSuggestion::query()
                ->whereKey($suggestion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isSettled()) {
                throw SignalsException::tuningSuggestionSettled();
            }

            // Nothing is changed. The row is the record that the team was asked and said no, which
            // is what stops the advisor asking again.
            $this->audit->log('signals.tuning_declined', $locked, [
                'kind' => $locked->kind->value,
                'signal_definition_id' => $locked->signal_definition_id,
            ], $actor);

            return $this->settle($locked, SignalTuningStatus::Declined, $actor);
        });
    }

    /**
     * Add the outlet to **every** active watch's skip list.
     *
     * Every watch rather than one, because the suggestion is about a publication and a team's
     * judgement of a publication does not vary by watch — and a member who accepted it for one
     * watch and kept seeing the source in another would reasonably call that broken. The list is
     * normalised through {@see IgnoredDomains}, the one place that rule lives, so an entry the
     * advisor wrote and an entry a member typed are compared identically.
     */
    private function ignoreSource(SignalTuningSuggestion $suggestion, User $actor): void
    {
        // The **name**, not the key: since S11 the skip list matches an outlet's name as well as a
        // host, and the name is what a member will recognise when they go to take it off again.
        $source = $suggestion->payloadString('source_name') ?? $suggestion->payloadString('source_key');

        if ($source === null) {
            throw SignalsException::tuningSuggestionStale();
        }

        /** @var list<SignalWatch> $watches */
        $watches = SignalWatch::query()
            ->where('team_id', $suggestion->team_id)
            ->get()
            ->all();

        foreach ($watches as $watch) {
            $existing = $watch->ignore_domains ?? [];
            $merged = IgnoredDomains::normaliseAll([...$existing, $source]);

            if ($merged === IgnoredDomains::normaliseAll($existing)) {
                continue;
            }

            $watch->forceFill(['ignore_domains' => $merged])->save();

            // Audited per watch, because that is the row that changed — the same entry
            // `SaveSignalWatchAction` would have written.
            $this->audit->log('signals.watch_updated', $watch, [
                'ignore_domains_count' => count($merged),
                'from_tuning_suggestion' => (int) $suggestion->getKey(),
            ], $actor);
        }
    }

    /**
     * Raise one of a definition's floors to the proposed value.
     *
     * The write goes through the model's own `thresholds` column with the other floor preserved —
     * a suggestion about confidence must not reset relevance to the global default, which is what
     * writing the column wholesale from the payload would do.
     */
    private function raiseThreshold(SignalTuningSuggestion $suggestion, User $actor): void
    {
        $definition = $suggestion->definition;
        $column = $suggestion->payloadString('threshold');
        $to = $suggestion->payloadInt('to');

        if (! $definition instanceof SignalDefinition || $definition->trashed() || $to === null
            || ! in_array($column, ['min_confidence', 'min_relevance'], strict: true)) {
            throw SignalsException::tuningSuggestionStale();
        }

        $thresholds = $definition->effectiveThresholds();
        $current = $thresholds[$column];

        // **Never downwards.** A member who raised this floor by hand after the suggestion was
        // written would otherwise have it *lowered* by accepting, with an audit entry reading like
        // an improvement (found by `laravel-module-reviewer`). A suggestion that can no longer
        // raise anything is stale, and saying so sends them back to the advisor rather than making
        // a change nobody asked for.
        if ($current >= $to) {
            throw SignalsException::tuningSuggestionStale();
        }

        $thresholds = $definition->thresholds ?? [];
        $thresholds[$column] = max(0, min(100, $to));

        $definition->forceFill([
            'thresholds' => $thresholds,
            // Bumped like any other save, so a later phase can still say which version of a
            // definition classified a signal.
            'version' => $definition->version + 1,
        ])->save();

        $this->audit->log('signals.definition_updated', $definition, [
            'threshold' => $column,
            'to' => $thresholds[$column],
            'from_tuning_suggestion' => (int) $suggestion->getKey(),
        ], $actor);
    }

    /**
     * Drop one query template from a definition.
     *
     * Refused when it would leave the type with **no** templates: a signal type that searches for
     * nothing is a row that costs nothing and finds nothing, and a member who accepted a tidy-up
     * suggestion would have no way of knowing that is what they had done. Pausing the type is the
     * honest action there, and it is theirs to take.
     */
    private function retireTemplate(SignalTuningSuggestion $suggestion, User $actor): void
    {
        $definition = $suggestion->definition;
        $template = $suggestion->payloadString('template');

        if (! $definition instanceof SignalDefinition || $definition->trashed() || $template === null) {
            throw SignalsException::tuningSuggestionStale();
        }

        $remaining = array_values(array_filter(
            $definition->query_templates,
            static fn (string $candidate): bool => trim($candidate) !== trim($template),
        ));

        if ($remaining === [] || count($remaining) === count($definition->query_templates)) {
            throw SignalsException::tuningSuggestionStale();
        }

        $definition->forceFill([
            'query_templates' => $remaining,
            'version' => $definition->version + 1,
        ])->save();

        $this->audit->log('signals.definition_updated', $definition, [
            'templates' => count($remaining),
            'from_tuning_suggestion' => (int) $suggestion->getKey(),
        ], $actor);
    }

    private function settle(SignalTuningSuggestion $suggestion, SignalTuningStatus $status, User $actor): SignalTuningSuggestion
    {
        $suggestion->forceFill([
            'status' => $status,
            'actioned_by' => $actor->getKey(),
            'actioned_at' => now(),
        ])->save();

        return $suggestion;
    }
}
