<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadsWorkspace\Models\SavedView;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a saved leads view (Phase 10). The audit entry is written in the **same transaction** as
 * the delete — and before it, so the trail keeps the name that went — which means a failed delete
 * can't leave a "deleted" entry behind for a view that is still there. Authorisation is the
 * policy's job, not this Action's.
 */
final class DeleteSavedViewAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SavedView $view, User $actor): void
    {
        DB::transaction(function () use ($view, $actor): void {
            $this->audit->log('leads.view_deleted', $view, ['name' => $view->name], $actor);

            $view->delete();
        });
    }
}
