<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\EraseSubjectDataAction;
use App\Modules\Compliance\Data\SubjectDataReport;
use App\Modules\Compliance\Enums\SubjectDataCategory;
use App\Modules\Compliance\Http\Controllers\Web\Concerns\ResolvesCompliance;
use App\Modules\Compliance\Http\Requests\LocateSubjectRequest;
use App\Modules\Compliance\Services\PersonalDataLocator;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Data-subject requests (Inertia) — locate, then erase on a human's confirmation
 * (docs/compliance.md §3.5).
 *
 * **The search is a GET with the query in the URL**, like the signals and insights feeds: it is a
 * read, it is shareable with a colleague handling the same request, and a POST would leave a
 * "confirm form resubmission" between a member and a legal deadline.
 *
 * **Searching is audited.** Looking up everything a team holds about a named person is a
 * privacy-relevant act whether or not anything is then erased (§3.8), and an audit trail that
 * recorded only the erasures would be missing the lookups worth reviewing. The entry carries the
 * match type and the counts — never the identity searched for, which would put the person's
 * address in an append-only table that no retention sweep touches (§3.9).
 *
 * Erasure is a DELETE with an explicit confirmation, and it **re-locates** rather than acting on the
 * ids the screen was showing — see {@see EraseSubjectDataAction}.
 */
final class DsarController extends Controller
{
    use ResolvesCompliance;

    public function index(
        Request $request,
        PersonalDataLocator $locator,
        TeamContext $teamContext,
        AuditLogger $audit,
    ): Response {
        $team = $this->team($request);

        $this->authorize('compliance.dsar', $team);

        $query = trim((string) $request->query('query', ''));
        $minimum = max(1, (int) config('compliance.dsar.min_query_length', 3));

        $report = null;

        if (mb_strlen($query) >= $minimum) {
            $report = $locator->locate($team, $query);

            $teamContext->run($team, function () use ($audit, $request, $report): void {
                $audit->log('compliance.subject_data_located', null, [
                    'matched_on' => $report->matchedOn,
                    'results' => $report->total(),
                    'suppressed' => $report->suppressed,
                ], $this->user($request));
            });
        }

        return Inertia::render('compliance/Dsar', [
            'filters' => ['query' => $query],
            'minimum' => $minimum,
            'report' => $report === null ? null : $this->payload($report),
            'options' => [
                'categories' => array_map(static fn (SubjectDataCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ], SubjectDataCategory::cases()),
            ],
        ]);
    }

    public function erase(LocateSubjectRequest $request, EraseSubjectDataAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('compliance.dsar', $team);

        $result = $action->handle(
            $team,
            $request->subjectQuery(),
            $this->user($request),
            $request->confirmedIds(),
        );

        // The real counts the Action returned, not the report's — a name match erases only what the
        // member confirmed, so the two differ, and the toast must not claim more than was done.
        $erased = array_sum($result['erased']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{0} Nothing matched, so nothing was erased.|{1} One record erased, and this identity is now on your do-not-contact list.|[2,*] :count records erased, and this identity is now on your do-not-contact list.',
                $erased,
                ['count' => $erased],
            ),
        ]);

        // Back to an empty search, not to the results: the records are gone, and re-running the
        // query to show a member an empty table would be the screen asking them to check our work.
        return redirect()->route('compliance.dsar.index');
    }

    /**
     * The report, flattened for the screen. The records are already a DTO; `counts` is sent beside
     * them so the summary doesn't depend on the client re-deriving it from a truncated list.
     *
     * @return array<string, mixed>
     */
    private function payload(SubjectDataReport $report): array
    {
        return [
            'query' => $report->query,
            'matched_on' => $report->matchedOn,
            'records' => array_map(static fn ($record): array => [
                'category' => $record->category->value,
                'category_label' => $record->category->label(),
                'id' => $record->id,
                'lead_id' => $record->leadId,
                'company_name' => $record->companyName,
                'detail' => $record->detail,
                'labels' => $record->labels,
                'matched_on' => $record->matchedOn,
                'recorded_at' => $record->recordedAt,
                'erasable' => $record->erasable,
                'retained_reason' => $record->retainedReason,
            ], $report->records),
            'counts' => $report->counts(),
            'total' => $report->total(),
            'erasable_count' => $report->erasableCount(),
            'truncated' => $report->truncated,
            'suppressed' => $report->suppressed,
        ];
    }
}
