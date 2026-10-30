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
