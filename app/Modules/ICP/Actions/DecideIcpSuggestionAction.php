<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Models\User;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Data\CriterionData;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\IcpSuggestionAction;
use App\Modules\ICP\Enums\IcpSuggestionStatus;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\ICP\Models\IcpSuggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A member's answer to one suggestion.
 *
 * **Accepting is an ordinary profile edit.** The change is applied through {@see UpdateIcpAction} —
 * the same Action the editor's save uses — so it is audited, it deactivates and re-scores exactly
 * as a hand edit would, and there is no second way to write a profile. **Declining is kept**, not
 * deleted: the row is what stops the same idea being raised again.
 *
 * Idempotent: a suggestion already decided is returned as it stands.
 */
final class DecideIcpSuggestionAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly UpdateIcpAction $update,
    ) {}

    public function handle(IcpSuggestion $suggestion, bool $accept, User $actor): IcpSuggestion
    {
        return $this->teamContext->run((int) $suggestion->team_id, fn (): IcpSuggestion => DB::transaction(function () use ($suggestion, $accept, $actor): IcpSuggestion {
            $fresh = IcpSuggestion::query()
                ->where('team_id', $suggestion->team_id)
                ->whereKey($suggestion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status !== IcpSuggestionStatus::Pending) {
                return $fresh;
            }

            if ($accept) {
                $this->apply($fresh, $actor);
            }

            $fresh->forceFill([
                'status' => $accept ? IcpSuggestionStatus::Accepted : IcpSuggestionStatus::Declined,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
            ])->save();

            return $fresh;
        }));
    }

    /**
     * The profile as it stands, with the one change the suggestion describes.
     */
    private function apply(IcpSuggestion $suggestion, User $actor): void
    {
        $icp = $suggestion->icp->load('criteria');

        $matches = static fn (IcpCriterion $criterion): bool => $criterion->type === $suggestion->type
            && Str::lower(trim($criterion->value)) === Str::lower(trim($suggestion->value));

        $criteria = $icp->criteria
            // A removal drops the criterion; a re-weighting keeps it at the new weight.
            ->reject(fn (IcpCriterion $criterion): bool => $suggestion->action === IcpSuggestionAction::Remove && $matches($criterion))
            ->map(fn (IcpCriterion $criterion): CriterionData => new CriterionData(
                type: $criterion->type,
                value: $criterion->value,
                weight: $suggestion->action === IcpSuggestionAction::Reweight && $matches($criterion)
                    ? $suggestion->weight
                    : $criterion->weight,
            ))
            ->values()
            ->all();
        $criteria = array_values($criteria);

        // An addition the profile has gained by hand since the suggestion was raised is not added twice.
        if ($suggestion->action === IcpSuggestionAction::Add && ! $icp->criteria->contains($matches)) {
            $criteria[] = new CriterionData(type: $suggestion->type, value: $suggestion->value, weight: $suggestion->weight);
        }

        $this->update->handle($icp, new IcpData(
            name: $icp->name,
            description: $icp->description,
            isActive: $icp->is_active,
            criteria: $criteria,
        ), $actor);
    }
}
