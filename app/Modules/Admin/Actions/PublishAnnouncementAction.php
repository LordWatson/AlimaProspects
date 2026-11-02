<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Jobs\AnnounceToTenantsJob;
use App\Modules\Admin\Models\Announcement;
use App\Modules\Admin\Services\AdminAudit;
use Illuminate\Support\Facades\DB;

/**
 * Publish an announcement, and optionally tell everybody (Phase 19b).
 *
 * The fan-out is dispatched **after commit**, which is the whole reason this is an Action rather
 * than a line in the controller: a notification that went out and then had its announcement rolled
 * back would be a message pointing at a row that does not exist, in a thousand tenants' bells at
 * once. `DB::afterCommit` is CLAUDE.md §2's rule and this is the case it is for.
 *
 * It is a **queued** fan-out because it walks every team, and an operator saving a form should not
 * hold the request open while a thousand notifications are written.
 */
final class PublishAnnouncementAction
{
    public function __construct(private readonly AdminAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $operator): Announcement
    {
        return DB::transaction(function () use ($attributes, $operator): Announcement {
            /** @var Announcement $announcement */
            $announcement = Announcement::query()->create([
                ...$attributes,
                'created_by' => $operator->getKey(),
            ]);

            $this->audit->record('admin.announcement.published', $announcement, [
                'level' => $announcement->level->value,
                'notify' => $announcement->notify,
                'plans' => $announcement->plans,
                'markets' => $announcement->markets,
                'operator_id' => $operator->getKey(),
            ]);

            if ($announcement->notify) {
                DB::afterCommit(static function () use ($announcement): void {
                    AnnounceToTenantsJob::dispatch((int) $announcement->getKey());
                });
            }

            return $announcement;
        });
    }

    /**
     * Take an announcement down.
     *
     * By setting `ends_at`, not by deleting: an announcement is a record of something we told
     * people, and "when did we say the product would be down?" is a question somebody asks later.
     * It stops showing immediately either way.
     */
    public function retire(Announcement $announcement, User $operator): Announcement
    {
        // Already over: leave the date alone. Overwriting it would move the record of when we told
        // people the product would be down, which is the question this row is kept to answer.
        if ($announcement->ends_at !== null && $announcement->ends_at->isPast()) {
            return $announcement;
        }

        return DB::transaction(function () use ($announcement, $operator): Announcement {
            $announcement->forceFill(['ends_at' => now()])->save();

            $this->audit->record('admin.announcement.retired', $announcement, [
                'operator_id' => $operator->getKey(),
            ]);

            return $announcement;
        });
    }
}
