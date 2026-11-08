<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors for Compliance & Privacy, rendered centrally (bootstrap/app.php): a friendly
 * message for the member, a stable machine code for the API envelope. Each cause is a named
 * constructor so the code and the status are set in one place.
 */
final class ComplianceException extends DomainException
{
    protected string $errorCode = 'compliance.invalid';

    /**
     * The retention windows were given the wrong way round. Refused rather than swapped silently,
     * because the numbers mean different things — one deletes, one anonymises — and guessing which
     * the team meant would be guessing about deletion.
     */
    public static function retentionWindowsInverted(): self
    {
        $e = new self('Keep contacted leads at least as long as uncontacted ones — a company you have approached is a record you need for longer, not less.');
        $e->errorCode = 'compliance.retention_windows_inverted';

        return $e;
    }

    /**
     * A data-subject search was too vague to run. Refused rather than returning half the database:
     * a DSAR answered with hundreds of unrelated people is a disclosure, not a response.
     */
    public static function subjectQueryTooShort(int $minimum): self
    {
        $e = new self(__('Give us at least :count characters to search for — a shorter query would match people the request is not about.', ['count' => $minimum]));
        $e->errorCode = 'compliance.subject_query_too_short';

        return $e;
    }

    /**
     * An assessment cannot be signed off because a required test is missing or perfunctory.
     */
    public static function assessmentIncomplete(): self
    {
        $e = new self('Fill in all three tests before signing the assessment off — it is the lawful basis for every lead you source.');
        $e->errorCode = 'compliance.assessment_incomplete';

        return $e;
    }

    /**
     * The hosted unsubscribe endpoint is switched off for this deployment, so a link that reached
     * it cannot be honoured here. The `mailto:` opt-out in the same footer still works.
     */
    public static function unsubscribeUnavailable(): self
    {
        $e = new self('This unsubscribe link is no longer active. Please reply to the message with “unsubscribe” and we will stop contacting you.');
        $e->errorCode = 'compliance.unsubscribe_unavailable';
        $e->status = 410;

        return $e;
    }

    /**
     * An operator tried to close a request that is not open — unverified, or already closed.
     *
     * Refused rather than tolerated, because completing an unverified request would make the
     * verification step optional through a different door (PL5).
     */
    public static function subjectRequestNotOpen(): self
    {
        $e = new self('That request is not open. Only a verified request that has not been closed can be completed or rejected.');
        $e->errorCode = 'compliance.subject_request_not_open';

        return $e;
    }

    /**
     * A caller asked to move a request to a status that is not an outcome. A programming error
     * rather than a member's mistake, and named so it cannot be reached by accident.
     */
    public static function subjectRequestNotClosable(): self
    {
        $e = new self('A request can only be completed or rejected.');
        $e->errorCode = 'compliance.subject_request_not_closable';

        return $e;
    }

    /**
     * A refusal with no reason. The one outcome a regulator will ask about, so it is the one that
     * cannot be recorded as a bare status.
     */
    public static function subjectRequestRejectionNeedsReason(): self
    {
        $e = new self('Say why the request is being refused — a rejection without a reason is not an accountability record.');
        $e->errorCode = 'compliance.subject_request_rejection_needs_reason';

        return $e;
    }

    /**
     * The public request form could not satisfy its challenge. Deliberately vague: a precise
     * message is a precise instruction for the next attempt.
     */
    public static function subjectRequestChallengeFailed(): self
    {
        $e = new self('We could not verify that form submission. Please try again.');
        $e->errorCode = 'compliance.subject_request_challenge_failed';

        return $e;
    }

    /**
     * There was nothing identifiable to suppress — a sent message with no recorded recipient. It
     * should not happen (a send records what it sent to), and it must not look like success.
     */
    public static function nothingToSuppress(): self
    {
        $e = new self('We couldn’t find the address this message was sent to. Please reply to the message with “unsubscribe” instead.');
        $e->errorCode = 'compliance.nothing_to_suppress';

        return $e;
    }
}
