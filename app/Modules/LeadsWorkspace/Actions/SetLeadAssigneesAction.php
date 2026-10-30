<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Events\LeadAssigned;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sets who is working a lead (Phase 10b) — the whole set at once, so the screen sends what it wants
 * and this works out the difference.
 *
 * Only members of the owning team can be assigned; anyone else in the list is dropped rather than
 * rejected, because a stale picker (a teammate who left between page load and save) shouldn't lose
 * the rest of the change. Timeline entries name who was added and who was taken off, so a handover
 * is legible months later.
 *
 * Fires {@see LeadAssigned} for the **newly added** members only, which Notifications (Phase 12)
 * turns into "Dana gave you Acme Ltd".
 *
 * Must be called inside the lead's team context.
 */
final class SetLeadAssigneesAction
{
    public function __construct(
        private readonly RecordLeadActivityAction $record,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, LeadAssignment>
     */
    public function handle(Team $team, Lead $lead, array $userIds, User $actor): Collection
    {
        return DB::transaction(function () use ($team, $lead, $userIds, $actor): Collection {
            /** @var list<int> $members */
            $members = $team->users()
                ->whereIn('users.id', array_unique($userIds))
                ->pluck('users.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $current = LeadAssignment::query()
                ->where('team_id', $team->getKey())
                ->where('lead_id', $lead->getKey())
                ->with('user:id,name')
                ->get();

            $currentIds = $current->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all();

            $added = array_values(array_diff($members, $currentIds));
            $removed = array_values(array_diff($currentIds, $members));

            foreach ($added as $userId) {
                // `firstOrCreate` rather than `create`: two members assigning the same person at
                // once would otherwise lose the (lead, user) unique race and 500, and the right
                // answer to "they're already assigned" is nothing at all.
                LeadAssignment::query()->firstOrCreate(
                    [
                        'team_id' => $team->getKey(),
                        'lead_id' => $lead->getKey(),
                        'user_id' => $userId,
                    ],
                    [
                        'assigned_by' => $actor->getKey(),
                        'assigned_at' => now(),
                    ],
                );
            }

            if ($removed !== []) {
                LeadAssignment::query()
                    ->where('team_id', $team->getKey())
                    ->where('lead_id', $lead->getKey())
                    ->whereIn('user_id', $removed)
                    ->delete();
            }

            if ($added !== []) {
                $this->record->handle(
                    lead: $lead,
                    type: LeadActivityType::Assigned,
                    properties: ['members' => $this->names($team, $added)],
                    actor: $actor,
                );
            }

            if ($removed !== []) {
                $this->record->handle(
                    lead: $lead,
                    type: LeadActivityType::Unassigned,
                    properties: [
                        // Read off the rows we already loaded, because the removal has happened.
                        'members' => $current
                            ->whereIn('user_id', $removed)
                            ->map(static fn (LeadAssignment $a): string => (string) $a->user?->name)
                            ->filter()
                            ->values()
                            ->all(),
                    ],
                    actor: $actor,
                );
            }

            if ($added !== [] || $removed !== []) {
                $this->audit->log('leads.assignees_changed', $lead, [
                    'added' => $added,
                    'removed' => $removed,
                ], $actor);
            }

            // Only the members newly added: somebody already on the lead has not been given
            // anything, and telling them again whenever a colleague joins would be noise.
            // afterCommit so the listener never reads an assignment that isn't there yet.
            if ($added !== []) {
                DB::afterCommit(static fn () => event(new LeadAssigned(
                    teamId: (int) $team->getKey(),
                    leadId: (int) $lead->getKey(),
                    userIds: $added,
                    actorId: (int) $actor->getKey(),
                )));
            }

            return LeadAssignment::query()
                ->where('team_id', $team->getKey())
                ->where('lead_id', $lead->getKey())
                ->with('user:id,name')
                ->get();
        });
    }

    /**
     * @param  list<int>  $userIds
     * @return list<string>
     */
    private function names(Team $team, array $userIds): array
    {
        /** @var list<string> $names */
        $names = $team->users()
            ->whereIn('users.id', $userIds)
            ->pluck('users.name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        return $names;
    }
}
