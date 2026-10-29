<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors for the Leads Workspace, rendered centrally (bootstrap/app.php): a friendly message
 * for the member, a stable machine code for the API envelope. Each cause is a named constructor so
 * the code is set in one place.
 */
final class LeadsWorkspaceException extends DomainException
{
    protected string $errorCode = 'leads.invalid';

    /**
     * A board needs somewhere for leads to be. Removing the last column isn't a customisation.
     */
    public static function lastStage(): self
    {
        $e = new self('A board needs at least one stage, so this one can’t be removed.');
        $e->errorCode = 'leads.last_stage';

        return $e;
    }

    /**
     * The default stage is where unplaced leads live, so it can't simply be deleted — another
     * column has to take the job first.
     */
    public static function defaultStage(): self
    {
        $e = new self('Make another stage the starting one, then you can remove this.');
        $e->errorCode = 'leads.default_stage';

        return $e;
    }

    public static function tooManyStages(int $max): self
    {
        $e = new self(__('A board can hold up to :max stages.', ['max' => $max]));
        $e->errorCode = 'leads.too_many_stages';

        return $e;
    }

    /**
     * A CSV that can't be read as one — no header, or nothing but a header.
     */
    public static function unreadableCsv(): self
    {
        $e = new self('That file doesn’t look like a CSV with a header row and at least one lead.');
        $e->errorCode = 'leads.unreadable_csv';

        return $e;
    }

    /**
     * An import that maps nothing to the company name has nothing to create a lead from.
     */
    public static function missingCompanyColumn(): self
    {
        $e = new self('Tell us which column holds the company name — Alima can’t import a lead without it.');
        $e->errorCode = 'leads.missing_company_column';

        return $e;
    }
}
