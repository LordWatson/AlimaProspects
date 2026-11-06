<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Contracts\LeadSignalSource;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;

/**
 * The real {@see LeadSignalSource}: what this team's Signals feed has seen the lead doing (Q3).
 *
 * Three rules, each of which is the same rule the feed itself follows:
 *
 * - **A dismissed signal says nothing.** A member who threw an event away has made a judgement, and
 *   a grade that still counts it is overruling a person with a rule (ADR 0024 §4c, one level out).
 * - **One event, one entry.** A signal is one article and a cluster is one event (S11), so a story
 *   covered by six outlets is read through `signal_clusters.primary_signal_id` and counted once —
 *   otherwise a well-reported funding round would outweigh a quiet one for no reason but coverage.
 * - **Recent only.** An ICP criterion like "recently raised funding" means recently; a round from
 *   three years ago is history, not a buying signal. The window is
 *   `signals.scoring.lead_signal_days`.
 *
 * The scorer only matches strings, so each signal contributes its **type name**, its `event_key`
 * and its **headline** — the type name is what a team's criterion is usually phrased like, and the
 * headline is what carries the specifics. The query is team-scoped by `BelongsToTeam`; the cap is
 * there because a long-watched account can accumulate a great many events and the scorer needs a
 * handful, not all of them.
 */
final class MatchedLeadSignals implements LeadSignalSource
{
    /** Newest-first cap. A criterion that does not match the recent handful would not match 400. */
    private const int MAX_SIGNALS = 20;

    /**
     * @return list<string>
     */
    public function labelsFor(Lead $lead): array
    {
        $since = now()->subDays(max(1, (int) config('signals.scoring.lead_signal_days', 365)));

        $signals = Signal::query()
            ->with('definition')
            ->where('matched_lead_id', $lead->getKey())
            ->whereNot('status', SignalStatus::Dismissed)
            ->where(function ($query): void {
                // One row per event: either the article is not clustered, or it is the one the feed
                // shows for its cluster.
                $query->whereNull('signal_cluster_id')
                    ->orWhereExists(fn ($exists) => $exists
                        ->selectRaw('1')
                        ->from('signal_clusters')
                        ->whereColumn('signal_clusters.id', 'signals.signal_cluster_id')
                        ->whereColumn('signal_clusters.primary_signal_id', 'signals.id'));
            })
            ->where(fn ($query) => $query
                ->where('published_at', '>=', $since)
                ->orWhere(fn ($fallback) => $fallback
                    ->whereNull('published_at')
                    ->where('created_at', '>=', $since)))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::MAX_SIGNALS)
            ->get();

        $labels = [];

        foreach ($signals as $signal) {
            foreach ([$signal->definition?->name, $signal->event_key, $signal->headline] as $label) {
                $label = trim((string) $label);

                if ($label !== '' && ! in_array($label, $labels, strict: true)) {
                    $labels[] = $label;
                }
            }
        }

        return $labels;
    }
}
