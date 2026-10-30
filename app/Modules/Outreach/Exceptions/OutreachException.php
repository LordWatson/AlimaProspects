<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Exceptions;

use App\Modules\Core\Exceptions\DomainException;
use App\Modules\Outreach\Services\SuppressionList;

/**
 * Base for the Outreach module's domain failures (Phase 11). Rendered centrally
 * (bootstrap/app.php): a friendly message to the member, the detail to logs and Sentry.
 */
class OutreachException extends DomainException
{
    protected string $errorCode = 'outreach_error';

    /**
     * The drafting model came back with nothing usable. Not a transport failure — that bubbles so
     * the queue can retry it — but output that validated and still contained no message.
     */
    public static function draftUnavailable(): self
    {
        $exception = new self(__('We could not write a draft for this lead. Please try again.'));
        $exception->errorCode = 'outreach_draft_unavailable';

        return $exception;
    }

    /**
     * The draft has already been sent or logged. Returned rather than silently re-sending, which is
     * what a double-tapped Send button would otherwise do.
     */
    public static function alreadyUsed(): self
    {
        $exception = new self(__('This message has already gone out.'));
        $exception->errorCode = 'outreach_already_used';
        $exception->status = 409;

        return $exception;
    }

    /**
     * Asked to send on a channel Alima does not transmit — a call script, say. The member copies
     * those and logs the attempt.
     */
    public static function notSendable(): self
    {
        $exception = new self(__('Alima does not send on this channel — copy the message and log the attempt.'));
        $exception->errorCode = 'outreach_not_sendable';

        return $exception;
    }

    /**
     * The mail provider refused or errored. The message is left `failed` with the provider's
     * message on the row, so the member can see what happened and try again.
     */
    public static function sendFailed(): self
    {
        $exception = new self(__('The message could not be sent. Please try again in a moment.'));
        $exception->errorCode = 'outreach_send_failed';
        $exception->status = 502;

        return $exception;
    }

    /**
     * The value a member typed into the suppression list isn't a usable identity of its type.
     */
    public static function unusableSuppressionValue(): self
    {
        $exception = new self(__("That doesn't look like an address, domain or phone number we can match on."));
        $exception->errorCode = 'outreach_unusable_suppression_value';

        return $exception;
    }

    /**
     * Someone tried to lift a global opt-out. The model, the policy and
     * {@see SuppressionList::lift()} all refuse it; this is what the
     * caller sees.
     */
    public static function suppressionNotRemovable(): self
    {
        $exception = new self(__('An opt-out cannot be removed. The person asked not to be contacted.'));
        $exception->errorCode = 'outreach_suppression_not_removable';
        $exception->status = 403;

        return $exception;
    }
}
