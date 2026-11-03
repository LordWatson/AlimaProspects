<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\SetLeadAssigneesAction;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookAssignStrategy;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;

/**
 * "Assign the lead" — through Leads Workspace's own {@see SetLeadAssigneesAction} (S14).
 *
 * Two things that are easy to get wrong here, and both come from the Action's shape.
 *
 * **It sets the whole set**, not one more person. So the step *adds* to whoever is already on the
 * lead rather than replacing them: a rule that fired on an account somebody is working must not
 * quietly take it off them, which is what passing only the named ids would do. The union is built
 * here and the Action works out the difference, as it does for the screen.
 *
 * **Only members of the team can be assigned**, and the Action drops anybody else silently. That is
 * right for a stale picker and wrong for a rule, so the resolution happens here first: when nobody
 * the rule named is still on the team, the step reports `no_members` rather than issuing a
 * no-op write and calling it done.
 *
 * Round-robin is **keyed on the signal's id**, not on a counter. A counter would be a contended
 * write per team and would hand the same person two leads whenever a retry re-read it; the signal's
 * id means a retry lands on the same member, which is what the once-per-signal guarantee wants
 * anyway. The trade is that the distribution is only even on average, which for a list of three or
 * four people over a week it is.
 */
final class AssignToMemberHandler implements PlaybookStepHandler
{
    public function __construct(private readonly SetLeadAssigneesAction $assign) {}

    public function kind(): PlaybookActionKind
    {
        return PlaybookActionKind::AssignToMember;
    }

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome
    {
        $lead = $context->lead();

        if (! $lead instanceof Lead) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoLead);
        }

        $named = $this->membersOf($context, $step->memberIds());

        if ($named === []) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoMembers);
        }

        $chosen = $step->assignStrategy() === PlaybookAssignStrategy::RoundRobin
            ? [$named[((int) $context->signal->getKey()) % count($named)]]
            : $named;

        if (! $context->performs()) {
            return PlaybookStepOutcome::simulated($this->kind(), ['members' => count($chosen)]);
        }

        // The union with whoever already has it — see the class docblock.
        $existing = $lead->assignments()->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $all = array_values(array_unique([...$existing, ...$chosen]));

        $this->assign->handle($context->team, $lead, $all, $context->actor);

        return PlaybookStepOutcome::applied($this->kind(), [
            // A count, not names. The log is a list of hundreds of rows and the names are on the
            // lead; storing them here would also mean a leaver's name living on in JSON.
            'members' => count($chosen),
        ], $lead);
    }

    /**
     * The named ids that are still members of this team, in the rule's own order.
     *
     * The rule's order matters for round-robin: re-sorting by id would change which member a given
     * signal landed on whenever somebody joined, which is exactly the kind of silent reshuffle that
     * makes an automation feel unpredictable.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function membersOf(PlaybookContext $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<int> $members */
        $members = $context->team->users()
            ->whereIn('users.id', $ids)
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_filter(
            $ids,
            static fn (int $id): bool => in_array($id, $members, strict: true),
        ));
    }
}
