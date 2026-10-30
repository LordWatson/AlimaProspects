<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;

/**
 * Hand a team the URL of Stripe's customer portal.
 *
 * Card details, billing address, VAT number and the invoice history live there rather than in
 * Alima, and deliberately: a form of ours that collected a card number would put this application
 * in scope for PCI compliance to reimplement a screen Stripe maintains, translates and keeps
 * current with card-network rules.
 */
final class OpenBillingPortalAction
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BillingException when the team has no Stripe customer, or Stripe is unreachable.
     */
    public function handle(Team $team, User $actor, string $returnUrl): string
    {
        $url = $this->gateway->portalUrl($team, $returnUrl);

        $this->audit->log('billing.portal_opened', $team, [], $actor);

        return $url;
    }
}
