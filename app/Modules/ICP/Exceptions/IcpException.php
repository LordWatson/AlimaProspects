<?php

declare(strict_types=1);

namespace App\Modules\ICP\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * The ICP module's domain failures, rendered centrally (bootstrap/app.php).
 */
final class IcpException extends DomainException
{
    /**
     * The composer could not produce a draft: no AI credit, or no schema-valid answer after a retry.
     *
     * One failure mode for both, following the Signals composer: a member cannot act differently on
     * "we are out of credit" and "the model answered badly twice" — both mean *try again later, or
     * write it yourself* — and the hand-written form is still right there, which is what the message
     * says. The distinction is in the log, where somebody can do something about it.
     */
    public static function composerUnavailable(): self
    {
        $e = new self('Alima could not draft a profile from that. Try describing your customers in a sentence or two, or add the signals yourself.');
        $e->errorCode = 'icp.composer_unavailable';
        $e->status = 503;

        return $e;
    }
}
