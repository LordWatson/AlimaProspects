<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Models\SavedView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The views offered in the leads list's view picker (Phase 10): the member's own, plus any a
 * teammate shared. Ordered own-first so a member's own views are where they left them, then by
 * name — a picker whose order changes with every save is a picker nobody trusts.
 */
final class ListSavedViewsAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return Collection<int, SavedView>
     */
    public function handle(Team $team, int $forUserId): Collection
    {
        return $this->teamContext->run($team, fn (): Collection => SavedView::query()
            ->where('team_id', $team->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->where('user_id', $forUserId)
                ->orWhere('is_shared', true))
            // The owner's name is shown on a shared view so it's clear whose it is; `team` is here
            // because SavedViewPolicy reads it, and a lazy read of it while authorising a list of
            // views would trip preventLazyLoading. One query covers every view (same team).
            ->with(['user:id,name', 'team'])
            ->orderByRaw('case when user_id = ? then 0 else 1 end', [$forUserId])
            ->orderBy('name')
            ->get());
    }
}
