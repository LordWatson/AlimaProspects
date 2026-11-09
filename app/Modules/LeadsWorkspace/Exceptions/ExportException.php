<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors a member can hit taking leads out (PL6).
 *
 * Rendered centrally (bootstrap/app.php): a friendly message, a stable code for the API envelope.
 */
final class ExportException extends DomainException
{
    protected string $errorCode = 'export.invalid';

    /**
     * This month's export allowance will not cover the file they asked for.
     *
     * 402 rather than 403, as everywhere else a plan limit bites: it is a question of what the team
     * is on, not of what this member may do. The sentence names the figure, because "your allowance
     * has run out" with no number is something an Owner cannot act on — and unlike a contact
     * reveal, an export allowance cannot be topped up (`BillingMetric::isToppable()`), so the only
     * honest advice is to narrow the export or change the plan.
     */
    public static function allowanceSpent(int $requested, ?int $remaining): self
    {
        $e = new self($remaining === null
            ? 'That export is bigger than this month’s allowance. Filter the list down and try again.'
            : __('This export needs :requested rows and you have :remaining left this month. Filter the list down, or change plan.', [
                'requested' => $requested,
                'remaining' => $remaining,
            ]));

        $e->errorCode = 'export.allowance_spent';
        $e->status = 402;

        return $e;
    }

    /**
     * The file is gone, or was never written.
     *
     * One answer for an expired file, a pruned one and a failed export, because they are the same
     * thing to a member holding a stale link — and distinguishing them would say more about our
     * storage than about their export. 410 rather than 404: the row exists and is theirs.
     */
    public static function fileGone(): self
    {
        $e = new self('That export has expired. Run a new one — exports are kept for a few days.');
        $e->errorCode = 'export.file_gone';
        $e->status = 410;

        return $e;
    }
}
