<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Integrations\Contracts\CrmConnector;
use App\Modules\Integrations\Data\CrmCredentials;
use App\Modules\Integrations\Data\CrmRecord;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Models\Integration;
use App\Modules\LeadSourcing\Models\Lead;
use RuntimeException;

/**
 * A CRM that records what it was asked to do (PL7).
 *
 * PL7 ships no connector on purpose — the framework comes first and Pipedrive and HubSpot are PL8
 * and PL9 — so this is what makes the whole push path testable: the suppression check, the
 * allowance, the `synced_hash` skip, the token refresh and the note all happen around a connector,
 * and this one lets a test assert exactly what reached it.
 *
 * It is also how the tests prove the **negative** cases that matter most: that an unrevealed
 * contact value never arrives here, and that a suppressed company never arrives at all.
 */
final class FakeCrmConnector implements CrmConnector
{
    /** @var list<array{lead: int, name: string|null}> */
    public array $companies = [];

    /** @var list<array{contact: int, email: string|null, phone: string|null}> */
    public array $people = [];

    /** @var list<string> */
    public array $notes = [];

    public int $deals = 0;

    public int $refreshes = 0;

    /** Throw `unauthorized` on the next company upsert, once. */
    public bool $expireOnce = false;

    /** Refuse to refresh — the case that becomes `needs_reauth`. */
    public bool $refreshFails = false;

    /** Blow up on the deal, which must not lose the company. */
    public bool $dealFails = false;

    /** Blow up on the note, which must leave the lead un-synced rather than falsely complete. */
    public bool $noteFails = false;

    /** What `handleCallback()` reports, so a reconnect can change accounts. */
    public string $accountId = 'acct-1';

    public function __construct(private readonly CrmProvider $provider = CrmProvider::Pipedrive) {}

    public function provider(): CrmProvider
    {
        return $this->provider;
    }

    public function authorizeUrl(string $state): string
    {
        return 'https://crm.test/oauth/authorize?state='.$state;
    }

    public function handleCallback(string $code): CrmCredentials
    {
        return new CrmCredentials(
            accessToken: 'access-'.$code,
            refreshToken: 'refresh-'.$code,
            expiresAt: now()->addHour(),
            accountId: $this->accountId,
            accountName: 'Acme CRM',
        );
    }

    public function refresh(Integration $integration): CrmCredentials
    {
        $this->refreshes++;

        if ($this->refreshFails) {
            throw new RuntimeException('refresh token is dead');
        }

        return new CrmCredentials(
            accessToken: 'access-fresh',
            refreshToken: 'refresh-fresh',
            expiresAt: now()->addHour(),
            accountId: $this->accountId,
            accountName: 'Acme CRM',
        );
    }

    public function testConnection(Integration $integration): bool
    {
        return true;
    }

    public function upsertCompany(Integration $integration, Lead $lead): CrmRecord
    {
        if ($this->expireOnce) {
            $this->expireOnce = false;

            throw IntegrationException::unauthorized($this->provider);
        }

        $this->companies[] = ['lead' => (int) $lead->getKey(), 'name' => $lead->company_name];

        return new CrmRecord('organization', 'org-'.$lead->getKey());
    }

    public function upsertPerson(Integration $integration, LeadContact $contact, CrmRecord $company): CrmRecord
    {
        $this->people[] = [
            'contact' => (int) $contact->getKey(),
            // Read off the model as the Action handed it over, so a test can assert that a masked
            // value never got this far.
            'email' => $contact->requiresEmailReveal() ? null : $contact->email,
            'phone' => $contact->requiresPhoneReveal() ? null : $contact->phoneValue(),
        ];

        return new CrmRecord('person', 'person-'.$contact->getKey());
    }

    public function createNote(Integration $integration, CrmRecord $on, string $text): void
    {
        if ($this->noteFails) {
            throw new RuntimeException('note endpoint is down');
        }

        $this->notes[] = $text;
    }

    public function createDealOrLead(Integration $integration, Lead $lead, CrmRecord $company): ?CrmRecord
    {
        if ($this->dealFails) {
            throw new RuntimeException('no such pipeline');
        }

        $this->deals++;

        return new CrmRecord('deal', 'deal-'.$lead->getKey());
    }
}
