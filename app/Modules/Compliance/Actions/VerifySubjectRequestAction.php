<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Enums\SubjectRequestStatus;
use App\Modules\Compliance\Enums\SubjectRequestType;
use App\Modules\Compliance\Jobs\PurgeSubjectDataJob;
use App\Modules\Compliance\Models\DataSubjectRequest;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The person clicked the link in their own inbox, so the request becomes real (PL5).
 *
 * Everything PL5 promises happens here, in this order and for these reasons:
 *
 * 1. **The clock starts.** `due_at` is stamped now rather than at submission, because the statutory
 *    month runs from a request we can act on.
 * 2. **The suppression is written first, at `global` scope.** First because it is the part that
 *    must not be able to fail after the data is gone — that would leave the person erased and
 *    re-contactable, the one outcome worse than doing nothing (the same ordering
 *    {@see EraseSubjectDataAction} keeps inside a team). `global` because the request was made to
 *    **Alima**, not to one tenant: they wrote to the platform, so the platform's whole customer
 *    base is bound. That is the one scope a tenant can neither create nor lift.
 * 3. **The purge is queued, not run.** It walks every team and erases per team; a web request
 *    cannot hold that, and a person clicking a link should get an answer immediately rather than a
 *    timeout.
 * 4. **An access or rectification request stops at step 1** — it is not automatic
 *    ({@see SubjectRequestType::isAutomatic()}) and goes to the
 *    operator queue, because somebody has to read it. It still suppresses, which is deliberate: a
 *    person asking what we hold should not be marketed to while they wait for the answer.
 *
 * **Only the email address is acted on.** The click proves control of a mailbox and nothing else,
 * so a phone number on the request is recorded for an operator rather than suppressed and erased —
 * otherwise the form is a way to have a stranger's number blocked across every tenant. See
 * {@see self::suppressIdentities()}.
 *
 * **Idempotent, and safe to repeat.** A second click, a mail client prefetching the link, a
 * forwarded email — all say the same thing as the first. The row is locked and re-read inside the
 * transaction so only one of them writes the audit entry, and the purge is re-queued when
 * `verified_at` is set with no `purged_at`, because a queue that was down for the first click must
 * not leave an erasure lost for good.
 */
final class VerifySubjectRequestAction
{
    public function __construct(
        private readonly SuppressionList $suppressions,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(DataSubjectRequest $request): DataSubjectRequest
    {
        // The row is **locked and re-read inside** the transaction. Two concurrent clicks — a
        // person and their mail client's prefetcher — would otherwise both pass a check made
        // outside it, and the second would write a second audit entry for one decision.
        $verified = DB::transaction(function () use ($request): bool {
            $fresh = DataSubjectRequest::query()->lockForUpdate()->find($request->getKey());

            if (! $fresh instanceof DataSubjectRequest || $fresh->verified_at !== null) {
                return false;
            }

            $this->suppressIdentities($fresh);

            $fresh->forceFill([
                'status' => SubjectRequestStatus::Open->value,
                'verified_at' => Carbon::now(),
                'due_at' => Carbon::now()->addDays(
                    max(1, (int) config('compliance.subject_requests.response_days', 30)),
                ),
            ])->save();

            return true;
        });

        $request->refresh();

        // **Queued after the commit, and queued again if it never was.** The purge used to be
        // dispatched straight after the transaction: a queue blip there left the row verified, the
        // person told it was done, and every later click returning early — an erasure lost for
        // good. So the trigger is `verified_at` set with no `purged_at`, which a second click
        // re-asserts, and `ShouldBeUnique` is what stops a re-click queueing a second walk while
        // the first is still running.
        if ($request->type->erasesData() && $request->verified_at !== null && $request->purged_at === null) {
            // Only an erasure purges. An opt-out stops contact and keeps the company record: the
            // person asked us to stop writing to them, not to delete a business from a CRM, and
            // deleting more than was asked for is its own failure.
            DB::afterCommit(static fn () => PurgeSubjectDataJob::dispatch((int) $request->getKey()));
        }

        if (! $verified) {
            // Somebody else's click did the work. Everything above is safe to repeat; the audit
            // entry and the log line below are not.
            return $request;
        }

        // No actor: nobody at Alima decided this, the data subject did. `AuditLogger` takes a null
        // actor, and a system-attributed entry is the honest record of a request we were obliged
        // to honour.
        $this->audit->log('compliance.subject_request_verified', $request, [
            'type' => $request->type->value,
            'automatic' => $request->type->isAutomatic(),
        ]);

        Log::channel('compliance')->info('Data-subject request verified.', [
            'request_id' => (int) $request->getKey(),
            'type' => $request->type->value,
        ]);

        return $request;
    }

    /**
     * Put **the address, and only the address**, on the platform list.
     *
     * Reason `opt_out`, which is unliftable by anybody in the product
     * ({@see SuppressionReason::isLiftable()}), and the right word even for an erasure: what the
     * entry records is the person's instruction to stop, and it is what stops the next discovery
     * run re-sourcing the same company and re-deriving the same address.
     *
     * **The phone number on the request is deliberately not suppressed here.** The click proves
     * control of one thing: the mailbox. Anyone can type a stranger's — or a competitor's
     * switchboard — number into the form, verify it from their own inbox, and have that number
     * globally suppressed across every tenant and erased from all of them. That is the exact threat
     * the verification step exists to close, and it is a denial rather than a leak, which is the
     * half nobody tests for (ADR 0023 §4a's lesson, one surface further out).
     *
     * So a phone-bearing request is recorded with its number and shown to an operator, who can
     * suppress it once they are satisfied. Honouring it automatically would need the number
     * verified too, which is an SMS round trip and a §5 question
     * (`docs/prelaunch/progress.md`, open items).
     */
    private function suppressIdentities(DataSubjectRequest $request): void
    {
        $this->suppressions->suppress(
            type: SuppressionType::Email,
            value: $request->email,
            scope: SuppressionScope::Global,
            reason: SuppressionReason::OptOut,
            note: 'Verified request #'.$request->getKey(),
        );
    }
}
