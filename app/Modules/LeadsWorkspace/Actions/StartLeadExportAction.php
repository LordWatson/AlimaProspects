<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadsWorkspace\Contracts\ExportRowAllowance;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Enums\ExportPreset;
use App\Modules\LeadsWorkspace\Enums\ExportState;
use App\Modules\LeadsWorkspace\Exceptions\ExportException;
use App\Modules\LeadsWorkspace\Jobs\GenerateLeadExportJob;
use App\Modules\LeadsWorkspace\Models\LeadExport;
use App\Modules\LeadsWorkspace\Services\LeadQueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Takes the member's export request and either serves it or queues it (PL6).
 *
 * Three things happen here and the order is the whole design:
 *
 * 1. **Count the rows first.** It is one indexed `COUNT` and it answers both of the questions that
 *    follow — whether the allowance covers it, and whether a member can wait for it.
 * 2. **Reserve the allowance now, not in the worker.** A refusal a member reads while they are
 *    still looking at the screen is worth far more than one that arrives as a failed notification
 *    ten minutes later, and two large exports asked for at once must not both be told there is
 *    room (`ExportRowAllowance`).
 * 3. **Then decide who does the work.** Up to `leads-workspace.export.sync_max_rows` the file is
 *    written in the request, because a member who clicked Export wants a file and not a promise.
 *    Past it the job does it and the member is told when it lands.
 *
 * The export is audited — who, what preset, how many rows — and the file's contents are not: it is
 * the team's own lead data, and the audit trail has no business holding a second copy.
 */
final class StartLeadExportAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly LeadQueryBuilder $leadQuery,
        private readonly ExportRowAllowance $allowance,
        private readonly GenerateLeadExportAction $generate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $columns  a custom column set; empty means the preset's own
     */
    public function handle(
        Team $team,
        User $actor,
        LeadFilters $filters,
        ExportPreset $preset = ExportPreset::LeadsOnly,
        array $columns = [],
    ): LeadExport {
        $estimate = $this->estimateRows($team, $filters, $preset);

        $reservation = $this->reserve($team, $estimate);

        $export = DB::transaction(function () use ($team, $actor, $filters, $preset, $columns, $reservation): LeadExport {
            $export = new LeadExport([
                'team_id' => $team->getKey(),
                'user_id' => $actor->getKey(),
                'preset' => $preset->value,
                'columns' => $preset === ExportPreset::Custom ? $columns : null,
                'filters' => $filters->toParams(),
                'state' => ExportState::Pending->value,
            ]);

            // Not fillable: the handle is money held against the team's allowance, which no
            // request may assert (see the model's note).
            $export->forceFill(['reservation' => $reservation])->save();

            return $export;
        });

        $this->audit->log('leads.exported', $export, [
            'preset' => $preset->value,
            'rows' => $estimate,
            'filtered' => $filters->isFiltering(),
            // The filter *keys*, so the trail says what kind of export this was without copying
            // the team's search terms into it.
            'filters' => array_keys($filters->toParams()),
        ], $actor);

        if ($estimate <= $this->syncLimit()) {
            try {
                return $this->generate->handle($export);
            } catch (Throwable $e) {
                // The request never reaches the job's `failed()`, so the hold would sit against
                // the team's allowance until the month turned over — and their retry would hold
                // again. A full disk is a 500 either way; it must not also be a billing problem.
                $this->generate->fail($export);

                throw $e;
            }
        }

        // After the commit, so the worker cannot pick up a row the request is still writing.
        DB::afterCommit(static fn () => GenerateLeadExportJob::dispatch((int) $export->getKey()));

        return $export;
    }

    /**
     * Hold the rows, or refuse.
     *
     * An empty export is not reserved at all: `UsageAllowance::reserve()` floors a quantity at one,
     * and charging a row for a file with nothing in it would be a charge for a filter that matched
     * nothing.
     */
    private function reserve(Team $team, int $estimate): ?string
    {
        if ($estimate === 0) {
            return null;
        }

        $handle = $this->allowance->reserve($team, $estimate);

        if ($handle === null) {
            throw ExportException::allowanceSpent($estimate, $this->allowance->remaining($team));
        }

        return $handle;
    }

    /**
     * How many rows the file will have.
     *
     * For every preset but the CRM-ready one that is the number of leads. For that one it is a row
     * per contact **plus** a row for each lead that has none — counted rather than guessed at,
     * because `UsageAllowance::commit()` clamps what is settled to what was held: an estimate too
     * low would quietly under-meter an export rather than fail, which is the kind of billing bug
     * nobody notices for a quarter.
     *
     * Suppressed companies are included in the estimate and released at the commit. The other way
     * round — counting the suppression first — would mean a second walk over the whole list before
     * the member is told anything.
     */
    private function estimateRows(Team $team, LeadFilters $filters, ExportPreset $preset): int
    {
        return $this->teamContext->run($team, function () use ($team, $filters, $preset): int {
            $leads = $this->leadQuery->filtered($team, $filters)->reorder()->count();

            if (! $preset->rowPerContact()) {
                return $leads;
            }

            $ids = $this->leadQuery->filtered($team, $filters)->reorder()->select('leads.id');

            $contacts = LeadContact::query()->whereIn('lead_id', $ids)->count();

            $withoutContacts = $this->leadQuery->filtered($team, $filters)
                ->reorder()
                ->whereDoesntHave('contacts')
                ->count();

            return $contacts + $withoutContacts;
        });
    }

    /**
     * The biggest export a member waits for in the request.
     */
    private function syncLimit(): int
    {
        return max(1, (int) config('leads-workspace.export.sync_max_rows', 500));
    }
}
