<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Data\BulkLeadRequestData;
use App\Modules\LeadsWorkspace\Enums\BulkLeadAction;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection;

/**
 * Applies one action to a selection of leads (Phase 10b).
 *
 * Three properties it holds to:
 *
 * - **Only the team's own leads are touched.** The ids are resolved through a team-scoped read, so
 *   an id from another team is simply not in the set — the count returned is what actually changed,
 *   not what was asked for.
 * - **Bounded.** `leads-workspace.max_bulk_leads` caps how much work one click can start; the
 *   request validates the same limit, so a caller gets told rather than silently truncated.
 * - **It reuses the single-lead Actions**, so a bulk tag, assignment or move writes exactly the
 *   same timeline and audit entries as doing it one lead at a time. A bulk action that recorded
 *   history differently would be a second, quietly diverging implementation of the same thing.
 *
 * That reuse is the deliberate trade: a selection of 500 leads costs 500 small transactions rather
 * than one big `update`. It keeps the history honest, and it is why the cap exists.
 */
final class BulkUpdateLeadsAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly SyncLeadTagsAction $syncTags,
        private readonly SetLeadAssigneesAction $setAssignees,
        private readonly MoveLeadStageAction $moveStage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int How many of the team's own leads the action was applied to — which is not the
     *             same as how many *changed*, because each single-lead Action is idempotent and a
     *             lead that already carried the tag is a no-op. Zero means none of the ids were
     *             the team's, which is the one case worth telling the member about.
     */
    public function handle(Team $team, User $actor, BulkLeadRequestData $data): int
    {
        return $this->teamContext->run($team, function () use ($team, $actor, $data): int {
            $leads = $this->leads($team, $data);

            if ($leads->isEmpty()) {
                return 0;
            }

            $stage = $data->action === BulkLeadAction::MoveStage
                ? PipelineStage::query()
                    ->where('team_id', $team->getKey())
                    ->find($data->pipelineStageId)
                : null;

            if ($data->action === BulkLeadAction::MoveStage && ! $stage instanceof PipelineStage) {
                return 0; // the stage was removed between opening the screen and clicking
            }

            foreach ($leads as $lead) {
                match ($data->action) {
                    BulkLeadAction::AddTags => $this->syncTags->handle(
                        $team,
                        $lead,
                        array_values([...$lead->tags->pluck('name')->all(), ...$data->tags]),
                        $actor,
                    ),
                    BulkLeadAction::RemoveTags => $this->syncTags->handle(
                        $team,
                        $lead,
                        $this->without($lead, $data->tags),
                        $actor,
                    ),
                    BulkLeadAction::Assign => $this->setAssignees->handle(
                        $team,
                        $lead,
                        array_values([...$lead->assignments->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all(), (int) $data->userId]),
                        $actor,
                    ),
                    BulkLeadAction::Unassign => $this->setAssignees->handle(
                        $team,
                        $lead,
                        array_values($lead->assignments
                            ->pluck('user_id')
                            ->map(static fn (mixed $id): int => (int) $id)
                            ->reject(fn (int $id): bool => $id === (int) $data->userId)
                            ->all()),
                        $actor,
                    ),
                    BulkLeadAction::MoveStage => $this->moveStage->handle($lead, $stage, $actor),
                };
            }

            $this->audit->log('leads.bulk_updated', null, [
                'action' => $data->action->value,
                'leads' => $leads->count(),
            ], $actor);

            return $leads->count();
        });
    }

    /**
     * @return Collection<int, Lead>
     */
    private function leads(Team $team, BulkLeadRequestData $data): Collection
    {
        $max = max(1, (int) config('leads-workspace.max_bulk_leads', 500));

        return Lead::query()
            ->where('team_id', $team->getKey())
            ->whereIn('id', array_slice($data->leadIds, 0, $max))
            // Both are read per lead by the Actions below, so they are loaded once up front rather
            // than lazily per row.
            ->with(['tags', 'assignments', 'pipelineStage'])
            ->get();
    }

    /**
     * The lead's tag names minus the ones being removed, matched on the normalised form so case
     * and spacing don't decide whether a tag comes off.
     *
     * @param  list<string>  $removing
     * @return list<string>
     */
    private function without(Lead $lead, array $removing): array
    {
        $drop = array_map(
            static fn (string $name): string => LeadTag::normalise($name),
            $removing,
        );

        /** @var list<string> $kept */
        $kept = $lead->tags
            ->reject(fn ($tag): bool => in_array($tag->slug, $drop, strict: true))
            ->pluck('name')
            ->values()
            ->all();

        return $kept;
    }
}
