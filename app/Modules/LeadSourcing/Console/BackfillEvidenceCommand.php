<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Console;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Actions\RecalculateLeadConfidenceAction;
use App\Modules\LeadSourcing\Actions\RecordLeadEvidenceAction;
use App\Modules\LeadSourcing\Data\EvidenceItem;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Models\Signal;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Gives leads that existed before PL1 the evidence they would have been created with.
 *
 * Two sources, both already on the row: the lead's own provenance (where we read the company from)
 * and, for a lead converted from a signal, that signal's article. Nothing is fetched and no AI call
 * is made — this restates facts the database already holds, which is what makes it safe to run on a
 * live database in the middle of the afternoon.
 *
 * **Idempotent**, and by construction rather than by a flag: evidence is written through
 * {@see RecordLeadEvidenceAction}, whose identity is `(lead, source, hash of the source URL or
 * payload)`, so a second run updates the same rows rather than doubling them. Running it twice
 * therefore produces the same confidence as running it once — which is the property worth having,
 * because the first run on a big team will be interrupted.
 *
 * Confidence is recalculated for **every** lead in scope, including one that gained no new
 * evidence: a lead whose evidence was recorded by an earlier interrupted run still needs its number
 * written.
 */
final class BackfillEvidenceCommand extends Command
{
    protected $signature = 'evidence:backfill
        {--team= : Only this team id}
        {--chunk=200 : Leads per chunk}
        {--fresh : Re-record evidence for leads that already have some}';

    protected $description = 'Build lead evidence and confidence from data already on the rows (PL1).';

    public function handle(
        TeamContext $teamContext,
        RecordLeadEvidenceAction $record,
        RecalculateLeadConfidenceAction $recalculate,
    ): int {
        $teamId = $this->option('team');
        $chunk = max(1, (int) $this->option('chunk'));
        $fresh = (bool) $this->option('fresh');

        $teams = Team::query()
            ->when($teamId !== null, fn ($query) => $query->whereKey((int) $teamId))
            ->orderBy('id')
            ->get();

        if ($teams->isEmpty()) {
            $this->components->warn('No teams matched.');

            return self::SUCCESS;
        }

        $leads = 0;
        $written = 0;

        foreach ($teams as $team) {
            // Inside the team's context so the global scope applies and the threshold is this
            // team's own — a backfill that judged every team against the config default would
            // hide leads a team had explicitly asked to see.
            $teamContext->run($team, function () use ($team, $chunk, $fresh, $record, $recalculate, &$leads, &$written): void {
                Lead::query()
                    ->where('team_id', $team->getKey())
                    // A lead needs this command if it has no evidence **or** no confidence. The
                    // second half is what makes an interrupted run resumable: a lead whose
                    // evidence an earlier attempt wrote but whose figure it never reached would
                    // otherwise be skipped for ever, and sit on the list explaining nothing.
                    ->when(! $fresh, fn ($query) => $query->where(fn ($scope) => $scope
                        ->whereDoesntHave('evidence')
                        ->orWhereNull('confidence')))
                    ->with('evidence')
                    ->orderBy('id')
                    // `chunkById`, not `chunk`: this writes to the rows it is paging over
                    // (`confidence`), and an offset-paged query over a changing table skips rows.
                    ->chunkById($chunk, function ($batch) use ($team, $fresh, $record, $recalculate, &$leads, &$written): void {
                        foreach ($batch as $lead) {
                            $leads++;

                            // Nothing to record for a lead an earlier run already gave its
                            // reasons to — only the figure is missing, so only the figure is
                            // written. `handleMany` recalculates as part of its own work, so the
                            // two branches are an either/or rather than one then the other.
                            if ($fresh || $lead->evidence->isEmpty()) {
                                $written += count($record->handleMany($lead, $this->itemsFor($lead), $team));

                                continue;
                            }

                            $recalculate->handle($lead, $team);
                        }
                    });
            });
        }

        $this->components->info(sprintf('Backfilled %d lead(s); %d evidence row(s) written or refreshed.', $leads, $written));

        return self::SUCCESS;
    }

    /**
     * What this lead can be told about itself from rows that already exist.
     *
     * @return list<EvidenceItem>
     */
    private function itemsFor(Lead $lead): array
    {
        // A converted signal's provenance is the article, which the next block records properly. A
        // generic "converted from a signal" row beside it would be the same fact with a worse
        // label, which is the same reason the provenance observer skips this source.
        $items = $lead->source === LeadSource::Signal ? [] : [EvidenceItem::provenanceOf($lead)];

        foreach ($this->signalsFor($lead) as $signal) {
            $items[] = new EvidenceItem(
                kind: EvidenceKind::Signal,
                source: $signal->source_kind->value,
                title: Str::limit($signal->headline, 200),
                summary: Str::limit($signal->summary, 500),
                sourceLabel: $signal->source_name,
                sourceUrl: $signal->source_url,
                observedAt: $signal->published_at,
                payload: [
                    'signal_id' => (int) $signal->getKey(),
                    'event_key' => $signal->event_key,
                    'backfilled' => true,
                ],
                signalId: (int) $signal->getKey(),
            );
        }

        return $items;
    }

    /**
     * The signals behind this lead: the one it was **converted from** (`signals.lead_id`) and the
     * ones that are **about** it (`signals.matched_lead_id`, S10).
     *
     * Both, because both are real reasons to call — the second is the account-monitoring case,
     * where an event was matched to a company the team already had and nobody converted anything.
     * Those events were invisible on the lead until now, and surfacing them is most of what this
     * backfill is worth.
     *
     * @return Collection<int, Signal>
     */
    private function signalsFor(Lead $lead): Collection
    {
        return Signal::query()
            ->where(fn ($query) => $query
                ->where('lead_id', $lead->getKey())
                ->orWhere('matched_lead_id', $lead->getKey()))
            ->orderByDesc('published_at')
            ->get();
    }
}
