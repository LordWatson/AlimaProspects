<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Actions\EraseSubjectDataAction;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Compliance\Models\DataSubjectRequest;
use App\Modules\Core\Models\Team;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Honours a verified erasure request across **every** team (PL5).
 *
 * The one job in the product that deliberately crosses tenants. It is allowed to because the
 * request was made to Alima rather than to a customer: a person who asks to be erased has not asked
 * to be erased by whichever customer happens to hold them.
 *
 * **It reuses {@see EraseSubjectDataAction} per team rather than writing its own erasure.** That
 * Action already knows the hard parts — which records are erasable, that a message keeps its row
 * and loses its text, that Signals answers for its own tables, that the suppression must be written
 * in the same transaction — and a second implementation here would be a second answer to "what does
 * erasure mean", diverging the first time either changed.
 *
 * Four things about how it runs:
 *
 * - **Per team, and a failure in one does not stop the rest — but it does fail the job.** A tenant
 *   with a corrupt row must not leave the remaining ninety-nine teams holding the person, so each
 *   team is tried and failures are logged by id and counted. The job then **throws**, because
 *   `purged_at` is what the admin queue reads as "done" and stamping it with failures counted
 *   beside it reported success while some tenants still held the person. The retry re-walks
 *   everything, which is safe: a team whose records are already gone finds nothing.
 * - **`ComplianceException` is not a failure.** It is what the locator throws when there is nothing
 *   to find, which is the normal case: most teams have never heard of this person.
 * - **An exact identity needs no confirmation, and only one identity is exact here.** The Action's
 *   name-match rule requires a human to tick records; an address is exact, so the whole located set
 *   is erased. This job passes **the email and nothing else**: the verification click proved control
 *   of a mailbox, and erasing the records matching a phone number anybody could have typed into a
 *   public form would be erasing a stranger's data on a stranger's say-so. A name-only or
 *   phone-bearing request is the operator queue's to finish.
 * - **`ShouldBeUnique` on the request id.** A re-clicked link, a retried dispatch and a duplicate
 *   webhook would otherwise walk every team again over data that is already gone.
 *
 * The summary it writes holds **counts keyed by category and never a value**, the same rule the
 * Action's own audit entry keeps: a record of what we erased for privacy reasons must not be a copy
 * of it.
 */
final class PurgeSubjectDataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $requestId) {}

    public function uniqueId(): string
    {
        return 'subject-purge:'.$this->requestId;
    }

    /**
     * How long the uniqueness lock survives a worker that never finishes.
     *
     * Without it a process killed mid-walk holds the lock for ever and the re-dispatch that
     * `VerifySubjectRequestAction` makes on the next click is silently dropped — which is the one
     * failure mode that leaves an erasure unfinished with nothing to notice it. Comfortably longer
     * than `$timeout`, so it can only expire after the job itself has given up.
     */
    public function uniqueFor(): int
    {
        return 1800;
    }

    public function handle(EraseSubjectDataAction $erase): void
    {
        $request = DataSubjectRequest::query()->find($this->requestId);

        if (! $request instanceof DataSubjectRequest || $request->verified_at === null) {
            // Never purge on an unverified request, however it got here.
            return;
        }

        // **The email only.** The verification click proved control of one mailbox and nothing
        // else, so erasing the records matching a phone number anybody could have typed into the
        // form would be erasing a stranger's data on a stranger's say-so — the same reason
        // `VerifySubjectRequestAction` does not suppress it. A phone-bearing request is an
        // operator's to finish.
        $identities = [$request->email];

        $totals = [];
        $teamsTouched = 0;
        $teamsFailed = 0;

        // Chunked by id so a growing tenant list cannot be held in memory, and `withoutGlobalScopes`
        // is not needed: `Team` is the tenancy root rather than a tenant-scoped model.
        Team::query()->orderBy('id')->chunkById(100, function ($teams) use (
            $erase, $identities, &$totals, &$teamsTouched, &$teamsFailed
        ): void {
            foreach ($teams as $team) {
                foreach ($identities as $identity) {
                    try {
                        $result = $erase->handle($team, $identity);
                    } catch (ComplianceException) {
                        // Nothing matched, or the identity is too short to search on. Not an error:
                        // most teams have never held this person.
                        continue;
                    } catch (Throwable $e) {
                        $teamsFailed++;

                        Log::channel('compliance')->error('Subject purge failed for one team.', [
                            'request_id' => $this->requestId,
                            'team_id' => (int) $team->getKey(),
                            'exception' => $e::class,
                        ]);

                        continue;
                    }

                    $erasedHere = 0;

                    foreach ($result['erased'] as $category => $count) {
                        $totals[$category] = ($totals[$category] ?? 0) + $count;
                        $erasedHere += $count;
                    }

                    if ($erasedHere > 0) {
                        $teamsTouched++;
                    }
                }
            }
        });

        // **`purged_at` is only stamped when every team came back clean**, because it is what the
        // admin queue reads as "the deletion half is done" and what
        // `VerifySubjectRequestAction` reads before re-queueing. Stamping it with failures counted
        // beside it reported success while some tenants still held the person, and took the job
        // out of reach of its own `$tries`.
        $request->forceFill([
            'purge_summary' => $totals + ['teams_touched' => $teamsTouched, 'teams_failed' => $teamsFailed],
            'purged_at' => $teamsFailed === 0 ? Carbon::now() : null,
        ])->save();

        Log::channel('compliance')->info('Subject purge finished a pass.', [
            'request_id' => $this->requestId,
            'teams_touched' => $teamsTouched,
            'teams_failed' => $teamsFailed,
        ]);

        if ($teamsFailed > 0) {
            // Thrown **after** the summary is written, so the attempt is visible either way, and
            // safe to retry because the walk is idempotent: a team whose records are already gone
            // simply finds nothing. The retry re-walks every team rather than only the failures,
            // which costs a query per team and needs no second piece of state to get wrong.
            throw new RuntimeException(sprintf(
                'Subject purge left %d team(s) unswept for request %d.',
                $teamsFailed,
                $this->requestId,
            ));
        }
    }

    public function failed(?Throwable $e): void
    {
        // The suppression is already in place, written before this job was queued, so the person is
        // not contactable whatever happened here. What is left is for an operator to see that the
        // deletion half did not finish — which is why the request stays `open` with no `purged_at`.
        //
        // The exception **class**, never its message: a message can carry the value that caused it
        // (a driver quoting the offending SQL parameter), and the one thing this log must not
        // become is a copy of the identity somebody asked us to forget.
        Log::channel('compliance')->error('Subject purge job failed.', [
            'request_id' => $this->requestId,
            'exception' => $e === null ? null : $e::class,
        ]);
    }
}
