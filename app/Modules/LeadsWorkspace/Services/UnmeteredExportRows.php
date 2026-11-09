<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Services;

use App\Modules\Core\Models\Team;
use App\Modules\LeadsWorkspace\Contracts\ExportRowAllowance;

/**
 * The default {@see ExportRowAllowance} (PL6): every export is allowed and nothing is recorded.
 *
 * Permissive, like the four ADR 0012 stubs — see the contract's note on why there is no cost floor
 * to protect here. Billing binds the real one over the top, and this is what makes the module
 * runnable on its own and in the tests that have no plan at all.
 *
 * The empty-string handle is the "nothing was booked" sentinel, so a caller settles unconditionally
 * rather than remembering to ask whether it needs to.
 */
final class UnmeteredExportRows implements ExportRowAllowance
{
    public function remaining(Team $team): ?int
    {
        return null;
    }

    /**
     * Narrowed to `string`: this one never refuses, and saying so is what stops a caller writing a
     * branch for a refusal that cannot happen here.
     */
    public function reserve(Team $team, int $rows): string
    {
        return '';
    }

    public function commit(string $handle, int $rows): void {}

    public function release(string $handle): void {}
}
