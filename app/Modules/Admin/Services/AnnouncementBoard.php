<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Models\Announcement;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a particular person should see right now (Phase 19b).
 *
 * Asked on **every tenant page load**, which shapes the whole implementation: the query is one
 * indexed read over a table that holds a handful of rows, the targeting and the dismissals are
 * filtered in PHP rather than in SQL (a `whereJsonContains` against two nullable arrays is neither
 * portable nor indexable), and the operator's `announcements.enabled` kill switch short-circuits
 * the lot before any of it happens.
 *
 * The kill switch is the first thing checked for a reason: it exists for the banner that turns out
 * to be wrong, and an operator reaching for it wants every tenant to stop seeing it on the next
 * request — not after somebody works out which rows to delete.
 */
final class AnnouncementBoard
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly PlanResolver $plans,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, ?Team $team, string $locale): array
    {
        if (! $this->settings->bool('announcements.enabled')) {
            return [];
        }

        $live = Announcement::query()->live()->get();

        if ($live->isEmpty()) {
            return [];
        }

        $planKey = $team instanceof Team ? $this->plans->planFor($team)->key->value : '';
        $dismissed = $this->dismissedIds($user, array_values($live->modelKeys()));

        $visible = [];

        foreach ($live as $announcement) {
            if ($team instanceof Team && ! $announcement->targets($team, $planKey)) {
                continue;
            }

            if ($announcement->is_dismissible && in_array((int) $announcement->getKey(), $dismissed, true)) {
                continue;
            }

            $copy = $announcement->copyFor($locale);

            // An announcement with nothing to say in any language is a draft somebody saved, not a
            // notice. Skipped rather than rendered as an empty banner.
            if ($copy['title'] === '') {
                continue;
            }

            $visible[] = [
                'id' => (int) $announcement->getKey(),
                'level' => $announcement->level->value,
                'variant' => $announcement->level->variant(),
                'title' => $copy['title'],
                'body' => $copy['body'],
                // The locale each line **actually** resolved to, so the banner can mark its own
                // direction. A reader asking for Arabic who gets the English fallback is reading
                // LTR text inside an RTL page, and nothing else on the page knows that.
                'title_locale' => $copy['title_locale'],
                'body_locale' => $copy['body_locale'],
                'dismissible' => $announcement->is_dismissible,
            ];
        }

        return $visible;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function dismissedIds(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // Through `dismissedBy` rather than a raw table builder: the pivot is already described on
        // the model, and two descriptions of one table is one too many. Scoped to the ids already
        // in hand, so it stays one indexed read over the live announcements rather than the table.
        return array_values(array_map('intval', Announcement::query()
            ->whereIn('id', $ids)
            ->whereHas('dismissedBy', static fn (Builder $query): Builder => $query
                ->whereKey($user->getKey()))
            ->pluck('id')
            ->all()));
    }
}
