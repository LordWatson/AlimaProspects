<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Contracts\CrmRowAllowance;
use App\Modules\LeadsWorkspace\Services\UnmeteredExportRows;

/**
 * The default {@see CrmRowAllowance} (PL7): every push is allowed and nothing is recorded.
 *
 * Permissive, for the reason PL6's {@see UnmeteredExportRows}
 * is — there is no cost floor to protect, because a push costs us nothing. Billing binds the real
 * one, and this is what makes the module runnable on its own and in tests that have no plan.
 *
 * The empty-string handle is the "nothing was booked" sentinel, so a caller settles
 * unconditionally rather than remembering to ask whether it needs to.
 */
final class UnmeteredCrmRows implements CrmRowAllowance
{
    public function remaining(Team $team): ?int
    {
        return null;
    }

    /**
     * Narrowed to `string`: this one never refuses, and saying so stops a caller writing a branch
     * for a refusal that cannot happen here.
     */
    public function reserve(Team $team, int $records): string
    {
        return '';
    }

    public function commit(string $handle, int $records): void {}

    public function release(string $handle): void {}
}
