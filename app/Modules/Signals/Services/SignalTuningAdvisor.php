<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalTuningKind;
use App\Modules\Signals\Enums\SignalTuningStatus;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Models\SignalTuningSuggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reads what the team has been doing and **proposes** changes (S12).
 *
 * This is the v1 backlog's "learn from dismiss reasons", and the shape of it is the decision worth
 * recording: it **suggests and never applies**. A feature that silently retunes itself is a
 * feature nobody can debug — the member who asks "why did it stop finding these?" has no answer,
 * and neither does the person supporting them — so every proposal becomes a
 * {@see SignalTuningSuggestion} a member accepts or declines, with the evidence beside it.
 *
 * It reads three things and proposes four:
 *
 * - `signal_source_stats` → **ignore this outlet** (the team dismisses nearly everything it
 *   brings) and **raise a type's floor** (the dismissals say "not an event", which is the
 *   classifier being wrong rather than the team being uninterested).
 * - the dismiss reasons behind those counts → **raise confidence** versus **raise relevance**,
 *   which are different levers for different complaints and the thing a team would otherwise
 *   spend a month tightening the wrong one of.
 *
 * S15 added the fourth and strongest: the type's own funnel and its own bill over the ROI window
 * → **pause this type**, which is the only one of these arguing from what something cost rather
 * than from a rate.
 *
 * {@see SignalTuningKind::RetireTemplate} is **still declared and not proposed**, and this note is
 * here rather than a claim that it is: the apply path exists and is tested, but nothing generates
 * one. Doing it properly needs per-template attribution — which template found which signal — and
 * `signals.matched_template` records the template that *found* a kept signal but nothing records
 * the templates that found nothing, which is exactly the evidence a retirement needs. S15 recorded
 * per-**type** spend (`signal_definition_spend`) and stopped there deliberately: a per-template
 * ledger multiplies that table by ten and nobody has asked for the row yet. A docblock that said
 * otherwise would be the most expensive kind of wrong: documentation somebody trusts.
 *
 * Three rules keep it from being a nag:
 *
 * 1. **Evidence before opinion.** `signals.tuning.min_observations` and `min_rate` are what a
 *    proposal has to clear. Two dismissals are not a pattern.
 * 2. **Ask once.** A declined proposal is remembered by its fingerprint and never raised again,
 *    which is the difference between a suggestion and a nag.
 * 3. **A short list.** `signals.tuning.max_open` caps what a member is shown at once, because a
 *    panel of thirty suggestions is a backlog and a backlog is ignored.
 *
 * Deterministic and no AI, like {@see SignalScorer}: a proposal somebody can only trust is worse
 * than none, and "a model thought so" is not evidence a member can check.
 */
final class SignalTuningAdvisor
{
    public function __construct(private readonly SignalRoiReader $roi) {}

    /**
     * Look at this team and write any proposals that are due. Returns how many are now open.
     *
     * Idempotent: a second run over unchanged evidence refreshes the same rows rather than adding
     * to them, because the fingerprint is what identifies a proposal and not its id.
     */
    public function advise(Team $team): int
    {
        $teamId = (int) $team->getKey();

        $proposals = [
            ...$this->sourceProposals($teamId),
            ...$this->thresholdProposals($teamId),
            ...$this->retirementProposals($team),
        ];

        foreach ($proposals as $proposal) {
            $this->remember($teamId, $proposal);
        }

        $open = $this->openCount($teamId);

        Log::channel('signals')->info('Signal tuning advisor ran.', [
            'team_id' => $teamId,
            'proposed' => count($proposals),
            'open' => $open,
        ]);

        return $open;
    }

    /**
     * The proposals a member should see: open, newest first, capped.
     *
     * @return list<SignalTuningSuggestion>
     */
    public function open(Team $team): array
    {
        /** @var list<SignalTuningSuggestion> $open */
        $open = SignalTuningSuggestion::query()
            ->where('team_id', $team->getKey())
            ->where('status', SignalTuningStatus::Proposed->value)
            ->with('definition')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('signals.tuning.max_open', 5)))
            ->get()
            ->all();

        return $open;
    }

    /**
     * Outlets this team dismisses nearly everything from.
     *
     * Proposed as an **ignore-list** entry rather than as a scoring change, because that is what
     * the team is actually saying: "I am not interested in this publication" is taste, and a
     * per-team skip list is exactly the lever for taste. The scorer already demotes the source on
     * its own through `source_authority`; this is the stronger, explicit act, and a member has to
     * ask for it.
     *
     * @return list<array<string, mixed>>
     */
    private function sourceProposals(int $teamId): array
    {
        $minimum = max(1, (int) config('signals.tuning.min_observations', 8));
        $rate = min(1.0, max(0.1, (float) config('signals.tuning.min_rate', 0.7)));

        // **Aggregated across the types, because the proposal is.** The statistics are per
        // `(source, type)` — an outlet is good at one kind of event and useless at another — but
        // accepting this adds the outlet to the watches' skip list, which silences it for
        // *everything*. Deciding that from one type's row would silence a source the team values
        // elsewhere, and two rows for one outlet would overwrite each other's evidence under the
        // same fingerprint (found by `laravel-module-reviewer`).
        /** @var array<string, array{name: string|null, shown: int, dismissed: int, converted: int}> $bySource */
        $bySource = [];

        foreach ($this->statsFor($teamId) as $stat) {
            $key = $stat->source_key;

            $bySource[$key] ??= ['name' => $stat->source_name, 'shown' => 0, 'dismissed' => 0, 'converted' => 0];
            $bySource[$key]['shown'] += $stat->observations();
            $bySource[$key]['dismissed'] += $stat->dismissed_count;
            $bySource[$key]['converted'] += $stat->converted_count;
        }

        $proposals = [];

        foreach ($bySource as $source => $totals) {
            $shown = $totals['shown'];
            $dismissed = $totals['dismissed'];
            $name = $totals['name'];

            if ($shown < $minimum || $totals['converted'] > 0) {
                // A source that has ever produced a lead is not one to propose silencing, however
                // noisy the rest of it is. One good lead pays for a lot of dismissing.
                continue;
            }

            if (($dismissed / $shown) < $rate) {
                continue;
            }

            $proposals[] = [
                'kind' => SignalTuningKind::IgnoreSource,
                'definition_id' => null,
                'payload' => [
                    'source_key' => $source,
                    'source_name' => $name,
                ],
                'evidence' => [
                    'source_name' => $name,
                    'kept' => $shown,
                    'dismissed' => $dismissed,
                    'observations' => $shown,
                    'rate' => round($dismissed / $shown, 2),
                ],
                'fingerprint' => $this->fingerprint(SignalTuningKind::IgnoreSource, null, $source),
            ];
        }

        return $proposals;
    }

    /**
     * Types whose dismissals say the floor is in the wrong place.
     *
     * **The two reasons point at different levers**, and keeping them apart is the whole value
     * here. "Not an event" says the classifier is too loose, and the lever is **confidence**.
     * "Not relevant" says the events are real and not this team's, and the lever is **relevance**.
     * A product that offered one knob for both would have teams tightening the wrong screw for a
     * month, which is the failure this method is shaped to avoid.
     *
     * The proposed value is a **nudge, not a target**: ten points, bounded at 90, because a floor
     * raised to 95 on one bad week stops the type finding anything and the team cannot tell
     * whether the tuning or the market went quiet.
     *
     * @return list<array<string, mixed>>
     */
    private function thresholdProposals(int $teamId): array
    {
        $minimum = max(1, (int) config('signals.tuning.min_observations', 8));
        $rate = min(1.0, max(0.1, (float) config('signals.tuning.min_rate', 0.7)));

        /** @var array<string, array{definition: SignalDefinition|null, dismissed: int, not_event: int, observations: int}> $byType */
        $byType = [];

        foreach ($this->statsFor($teamId) as $stat) {
            $key = $stat->event_key;

            $byType[$key] ??= [
                'definition' => $stat->definition,
                'dismissed' => 0,
                'not_event' => 0,
                'observations' => 0,
            ];

            $byType[$key]['dismissed'] += $stat->dismissed_count;
            $byType[$key]['not_event'] += $stat->not_an_event_count;
            $byType[$key]['observations'] += $stat->observations();
        }

        $proposals = [];

        foreach ($byType as $eventKey => $totals) {
            $definition = $totals['definition'];

            if (! $definition instanceof SignalDefinition || $totals['observations'] < $minimum) {
                continue;
            }

            $thresholds = $definition->effectiveThresholds();

            // No zero guard: the `< $minimum` check above already refused anything under one
            // observation, and `signals.tuning.min_observations` is clamped to at least one. A
            // defensive ternary here would be dead code pretending to be care.
            $notEventRate = $totals['not_event'] / $totals['observations'];
            // What is left of the dismissals once the misclassifications are taken out: the team
            // saying "real, and not for me".
            $irrelevantRate = ($totals['dismissed'] - $totals['not_event']) / $totals['observations'];

            if ($notEventRate >= $rate && $thresholds['min_confidence'] < 90) {
                $proposals[] = $this->thresholdProposal(
                    SignalTuningKind::RaiseConfidence,
                    $definition,
                    $eventKey,
                    'min_confidence',
                    $thresholds['min_confidence'],
                    $totals['not_event'],
                    $totals['observations'],
                    $notEventRate,
                );

                continue;
            }

            if ($irrelevantRate >= $rate && $thresholds['min_relevance'] < 90) {
                $proposals[] = $this->thresholdProposal(
                    SignalTuningKind::RaiseRelevance,
                    $definition,
                    $eventKey,
                    'min_relevance',
                    $thresholds['min_relevance'],
                    $totals['dismissed'] - $totals['not_event'],
                    $totals['observations'],
                    $irrelevantRate,
                );
            }
        }

        return $proposals;
    }

    /**
     * One threshold nudge, with the counts behind it.
     *
     * @return array<string, mixed>
     */
    private function thresholdProposal(
        SignalTuningKind $kind,
        SignalDefinition $definition,
        string $eventKey,
        string $threshold,
        int $current,
        int $count,
        int $observations,
        float $rate,
    ): array {
        $proposed = min(90, $current + 10);

        return [
            'kind' => $kind,
            'definition_id' => (int) $definition->getKey(),
            'payload' => [
                'threshold' => $threshold,
                'from' => $current,
                'to' => $proposed,
            ],
            'evidence' => [
                'definition_name' => $definition->name,
                'event_key' => $eventKey,
                'dismissals' => $count,
                'observations' => $observations,
                'rate' => round($rate, 2),
            ],
            // The fingerprint deliberately does **not** include the proposed value: a team that
            // declines "raise this to 70" has declined raising this floor, and coming back next
            // week with 71 would be the same nag wearing a different number.
            'fingerprint' => $this->fingerprint($kind, (int) $definition->getKey(), $threshold),
        ];
    }

    /**
     * Write a proposal, unless the team has already answered it.
     *
     * The three cases this has to get right: a **declined** proposal is left alone for ever, an
     * **accepted** one is left alone because the change has been made, and a live one is refreshed
     * so its evidence is current when the member finally reads it.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function remember(int $teamId, array $proposal): void
    {
        /** @var SignalTuningKind $kind */
        $kind = $proposal['kind'];
        /** @var string $fingerprint */
        $fingerprint = $proposal['fingerprint'];

        $existing = SignalTuningSuggestion::query()
            ->where('team_id', $teamId)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($existing instanceof SignalTuningSuggestion) {
            if ($existing->status->isSettled()) {
                return;
            }

            $existing->forceFill([
                'payload' => $proposal['payload'],
                'evidence' => $proposal['evidence'],
            ])->save();

            return;
        }

        DB::transaction(static function () use ($teamId, $kind, $proposal, $fingerprint): void {
            SignalTuningSuggestion::query()->insertOrIgnore([
                'team_id' => $teamId,
                'kind' => $kind->value,
                'signal_definition_id' => $proposal['definition_id'],
                'payload' => json_encode($proposal['payload'], JSON_UNESCAPED_UNICODE),
                'evidence' => json_encode($proposal['evidence'], JSON_UNESCAPED_UNICODE),
                'status' => SignalTuningStatus::Proposed->value,
                'fingerprint' => $fingerprint,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * This team's source statistics, read once per call.
     *
     * Scoped twice, because the advisor is reachable from a console command with no request behind
     * it — and a proposal built from another tenant's dismissals would be the worst kind of wrong:
     * plausible, specific and about somebody else.
     *
     * @return list<SignalSourceStat>
     */
    private function statsFor(int $teamId): array
    {
        /** @var list<SignalSourceStat> $stats */
        $stats = SignalSourceStat::query()
            ->where('team_id', $teamId)
            ->with('definition')
            ->get()
            ->all();

        return $stats;
    }

    private function openCount(int $teamId): int
    {
        return SignalTuningSuggestion::query()
            ->where('team_id', $teamId)
            ->where('status', SignalTuningStatus::Proposed->value)
            ->count();
    }

    /**
     * Types that have not earned what they cost (S15 task 3).
     *
     * **The strongest proposal here, and the one with the most evidence behind it.** The others
     * argue from a dismissal rate over an outlet or a threshold; this argues from the type's own
     * funnel and its own bill over the whole ROI window, which is the only honest basis for saying
     * "stop paying for this".
     *
     * Three guards, and all three are about not being wrong in front of somebody who likes the
     * type:
     *
     * - **A floor on the evidence** (`signals.roi.retire.minimum_found`). A type with four signals
     *   and none kept says nothing at all.
     * - **Nothing converted.** A type with a poor kept rate that has nonetheless produced a deal is
     *   earning its keep by the only measure that matters, and proposing its retirement would make
     *   the whole panel untrustworthy.
     * - **Only an active type.** Proposing a pause for something already paused is the product not
     *   reading its own data.
     *
     * The fingerprint is the type and nothing else, so a team that declines this is never asked
     * about that type again — which is right: they have told us they know and they want it anyway.
     *
     * @return list<array<string, mixed>>
     */
    private function retirementProposals(Team $team): array
    {
        if (! (bool) config('signals.roi.retire.enabled', true)) {
            return [];
        }

        $minimum = max(1, (int) config('signals.roi.retire.minimum_found', 30));
        $floor = min(1.0, max(0.0, (float) config('signals.roi.retire.kept_rate_floor', 0.1)));

        $roi = $this->roi->forTeam($team);

        /** @var array<string, SignalDefinition> $definitions */
        $definitions = SignalDefinition::query()
            ->where('team_id', $team->getKey())
            ->where('is_active', true)
            ->get()
            ->keyBy('key')
            ->all();

        $proposals = [];

        foreach ($definitions as $key => $definition) {
            $figures = $roi[$key] ?? null;

            if ($figures === null || ! $figures->hasEnoughHistory($minimum) || $figures->converted > 0) {
                continue;
            }

            $keptRate = $figures->keptRate();

            if ($keptRate === null || $keptRate > $floor) {
                continue;
            }

            $proposals[] = [
                'kind' => SignalTuningKind::RetireDefinition,
                'definition_id' => (int) $definition->getKey(),
                'payload' => ['event_key' => $key],
                // The numbers the card leads with. A proposal a member cannot check is one they can
                // only obey or ignore (ADR 0022's rule for this panel), and these are the ones that
                // make the argument: it found this much, you kept this little, it cost this.
                'evidence' => [
                    'definition_name' => $definition->name,
                    'event_key' => $key,
                    'found' => $figures->found,
                    'kept' => $figures->kept,
                    'rate' => round($keptRate, 2),
                    'cost_micros' => $figures->totalCostMicros(),
                    'days' => $figures->days,
                ],
                'fingerprint' => $this->fingerprint(
                    SignalTuningKind::RetireDefinition,
                    (int) $definition->getKey(),
                    'retire',
                ),
            ];
        }

        return $proposals;
    }

    /**
     * A stable identity for a proposal: its kind, what it is about, and the thing being changed.
     *
     * Not the proposed *value*, deliberately — see {@see self::thresholdProposal()}.
     */
    private function fingerprint(SignalTuningKind $kind, ?int $definitionId, string $subject): string
    {
        return sha1($kind->value.'|'.($definitionId ?? 0).'|'.$subject);
    }
}
