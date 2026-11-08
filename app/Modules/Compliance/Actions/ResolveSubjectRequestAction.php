<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\SubjectRequestStatus;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Compliance\Models\DataSubjectRequest;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An operator closes a verified request (PL5).
 *
 * It only ever changes a status and records who did it. Everything that *acts* on a request has
 * already happened — the suppression at verification, the purge in its job — because the parts
 * that follow mechanically from the person's instruction must not wait for somebody to be at a
 * desk. What is left here is the half that needs a human: answering an access request, correcting
 * what was wrong, or refusing one with a reason.
 *
 * **An unverified request cannot be closed.** It is not a request yet, and letting an operator
 * complete one would make the verification step optional through a different door.
 *
 * **A rejection needs a note.** "Refused" with no reason is not an accountability record, and a
 * refusal is the one outcome a regulator will ask about.
 */
final class ResolveSubjectRequestAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ComplianceException when the request is unverified, already closed, or a rejection
     *                             arrives without a reason
     */
    public function handle(
        DataSubjectRequest $request,
        SubjectRequestStatus $status,
        User $actor,
        ?string $note = null,
    ): DataSubjectRequest {
        if (! $status->isClosed()) {
            throw ComplianceException::subjectRequestNotClosable();
        }

        if ($request->verified_at === null || $request->status->isClosed()) {
            throw ComplianceException::subjectRequestNotOpen();
        }

        if ($status === SubjectRequestStatus::Rejected && ($note === null || trim($note) === '')) {
            throw ComplianceException::subjectRequestRejectionNeedsReason();
        }

        DB::transaction(static function () use ($request, $status, $actor, $note): void {
            $request->forceFill([
                'status' => $status->value,
                'handled_by' => $actor->getKey(),
                'resolution_note' => $note,
                'completed_at' => Carbon::now(),
            ])->save();
        });

        $this->audit->log('compliance.subject_request_resolved', $request, [
            'type' => $request->type->value,
            'status' => $status->value,
            // The note is the operator's own words about how it was handled, not the subject's data,
            // so it belongs in the trail.
            'note' => $note,
        ], $actor);

        return $request;
    }
}
