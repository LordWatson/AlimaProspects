<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\PreviewedSignal;
use App\Modules\Signals\Data\SignalPlaybookConditions;
use App\Modules\Signals\Data\SignalPlaybookFacts;
use App\Modules\Signals\Data\SignalPlaybookPreview;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Services\SignalPlaybookEvaluator;
use Illuminate\Support\Facades\Date;

/**
 * "What would this rule have done to last week's signals?" (S14)
 *
 * S9's preview idea applied to automation, and it is what makes somebody brave enough to switch a
 * rule on. It differs from S9's in the one way that matters: **it spends nothing.** The evaluator is
 * deterministic and this reads rows the team has already paid for, so there is no AI call, no
 * provider call, no ledger row and no budget — only a window
 * (`signals.playbooks.preview_days`) and a sample size.
 *
 * It **executes nothing**, not even in a sandbox. There is no "what would the draft have said": a
 * rehearsal that wrote drafts would be a rehearsal that spent credit, and the dry-run mode exists
 * for the question "what happens when it runs". This one answers the cheaper question first — does
 * it match the right stories at all — which is the one most rules get wrong.
 *
 * Cluster members are excluded, as they are at run time: one event is one card (S11), and a preview
 * that counted eight articles about one acquisition would over-report by eight.
 *
 * Takes conditions rather than a saved playbook, so the builder can preview a rule **before** it
 * exists — which is the moment somebody actually wants the answer.
 */
final class PreviewSignalPlaybookAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly SignalPlaybookEvaluator $evaluator,
    ) {}

    /**
     * @param  list<PlaybookActionKind>  $kinds  The steps the rule carries, so the preview can warn
     *                                           that a matching rule would hit a per-job ceiling.
     */
    public function handle(Team $team, SignalPlaybookConditions $conditions, array $kinds = []): SignalPlaybookPreview
    {
        $days = max(1, (int) config('signals.playbooks.preview_days', 7));
        $sampleSize = max(1, (int) config('signals.playbooks.preview_samples', 10));
        $since = Date::now()->utc()->subDays($days);

        return $this->teamContext->run($team, function () use (
            $conditions, $kinds, $days, $sampleSize, $since
        ): SignalPlaybookPreview {
            $considered = 0;
            $matched = 0;
            $samples = [];

            Signal::query()
                ->representatives()
                ->where('created_at', '>=', $since)
                ->with(['cluster', 'watch'])
                // Newest first, so the samples are the stories a member still remembers. The whole
                // window is still walked for the counts — the ratio is the useful number and a
                // sample of ten would not give it.
                ->orderByDesc('id')
                ->chunk(200, function ($chunk) use (
                    $conditions, $sampleSize, &$considered, &$matched, &$samples
                ): void {
                    foreach ($chunk as $signal) {
                        $considered++;

                        if (! $this->evaluator->matches($conditions, SignalPlaybookFacts::fromSignal($signal))) {
                            continue;
                        }

                        $matched++;

                        if (count($samples) < $sampleSize) {
                            $samples[] = PreviewedSignal::fromSignal($signal);
                        }
                    }
                });

            $converts = array_filter($kinds, static fn (PlaybookActionKind $kind): bool => in_array(
                $kind,
                [PlaybookActionKind::ConvertToLead, PlaybookActionKind::AttachToMatchedLead],
                strict: true,
            ));

            $drafts = in_array(PlaybookActionKind::DraftOutreach, $kinds, strict: true);

            return new SignalPlaybookPreview(
                considered: $considered,
                matched: $matched,
                days: $days,
                // Said out loud, because a rule with nothing filled in that converts is the single
                // most expensive mistake available on this screen.
                matchesEverything: $conditions->matchesEverything(),
                // The per-job ceilings, measured against a window rather than a job — which
                // over-warns for a team whose week arrives in seven daily runs. That is the right
                // direction to be wrong in: the warning says "this may be refused partway", and a
                // member who reads it and narrows the rule has lost nothing.
                wouldExceedConversions: $converts !== []
                    && $matched > max(0, (int) config('signals.playbooks.max_conversions_per_run', 25)),
                wouldExceedDrafts: $drafts
                    && $matched > max(0, (int) config('signals.playbooks.max_drafts_per_run', 10)),
                samples: $samples,
            );
        });
    }
}
