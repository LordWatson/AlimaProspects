<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors a **member** can hit in Enrichment (PL3) — as distinct from
 * {@see EnrichmentProviderException}, which is a vendor letting us down and which a member sees at
 * most as "Alima couldn't look just now".
 *
 * Rendered centrally (bootstrap/app.php): a friendly message, a stable code for the API envelope.
 */
final class EnrichmentException extends DomainException
{
    protected string $errorCode = 'enrichment.invalid';

    /**
     * A reveal was asked for on a contact that holds nothing to reveal.
     */
    public static function nothingToReveal(): self
    {
        $e = new self('There’s nothing to show for this contact yet.');
        $e->errorCode = 'enrichment.nothing_to_reveal';
        $e->status = 404;

        return $e;
    }

    /**
     * The team may not hold or use this value — a suppression, a register, a market rule (PL5).
     */
    public static function revealNotPermitted(): self
    {
        $e = new self('This contact has asked not to be approached, so Alima won’t show their details.');
        $e->errorCode = 'enrichment.reveal_not_permitted';
        $e->status = 403;

        return $e;
    }

    /**
     * The team's allowance for this is spent.
     *
     * Carries the reserver's own refusal code where it gave one, so the billing screen can say
     * *which* allowance — a plan ceiling and a monthly allowance are different conversations.
     */
    public static function allowanceSpent(?string $code = null): self
    {
        $e = new self('You’ve used this month’s contact reveals. Top up or upgrade to see more.');
        $e->errorCode = $code ?? 'enrichment.allowance_spent';
        $e->status = 402;

        return $e;
    }

    /**
     * A bulk request asked for more leads than one batch may carry.
     */
    public static function tooManyLeads(int $max): self
    {
        $e = new self(__('Alima can enrich up to :max leads at a time.', ['max' => $max]));
        $e->errorCode = 'enrichment.too_many_leads';
        $e->details = ['max' => $max];

        return $e;
    }
}
