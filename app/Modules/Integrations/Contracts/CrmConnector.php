<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Integrations\Data\CrmCredentials;
use App\Modules\Integrations\Data\CrmRecord;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Services\CrmConnectorRegistry;
use App\Modules\Integrations\Services\CrmNoteComposer;
use App\Modules\Integrations\Services\OAuthStateService;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Everything Alima needs one CRM to do (PL7). PL8 implements it for Pipedrive, PL9 for HubSpot.
 *
 * Registered through {@see CrmConnectorRegistry}, which is the fourth appearance of ADR 0008's
 * pattern here (lead sources, signal sources, enrichment vendors, and now this). The shape is the
 * same every time for the same reason: the job that pushes a lead must not contain a `match` over
 * providers, because that `match` is where the second provider's behaviour gets bolted onto the
 * first one's.
 *
 * **Three rules a connector does not get to decide, and they are the reason this is a narrow
 * contract rather than a "do the sync" method.**
 *
 * 1. **Whether a lead may be pushed at all.** The suppression check is in the Action, before a
 *    connector is reached. A CRM is a place a record goes to be contacted from; a connector that
 *    could skip that check would be a second copy of a compliance rule (ADR 0032, and ADR 0033's
 *    argument for the export, which is the same argument).
 * 2. **Whether the team can afford it.** The allowance is held and settled by the Action (PL4).
 * 3. **What the note says.** {@see CrmNoteComposer} composes it
 *    once, so the thing a prospect's own account manager reads is the same sentence whichever CRM
 *    they use.
 *
 * **A connector throws rather than returning a status.** The two cases that matter are
 * {@see IntegrationException::unauthorized()} — the credential was refused, which the refresher
 * recovers from exactly once — and anything else, which is a failed push the Action logs. There is
 * deliberately no "miss", unlike an enrichment vendor (ADR 0029): a CRM asked to create an
 * organisation either creates one or fails. Nothing here can legitimately find nothing.
 */
interface CrmConnector
{
    public function provider(): CrmProvider;

    /**
     * Where to send the member to authorise us.
     *
     * Takes the opaque `state` that {@see OAuthStateService}
     * minted; a connector never invents one, because the thing `state` protects against is a
     * callback we did not start and only one issuer can know what we started.
     */
    public function authorizeUrl(string $state): string;

    /**
     * Exchange the authorisation code for tokens, and say whose account they are for.
     */
    public function handleCallback(string $code): CrmCredentials;

    /**
     * A fresh access token from the stored refresh token.
     *
     * @throws IntegrationException when the refresh token is itself dead — the caller turns that
     *                              into `needs_reauth`
     */
    public function refresh(Integration $integration): CrmCredentials;

    /**
     * Whether the stored credential still works, as a question with no side effects.
     *
     * For the "Test connection" button and for a status check that must not write into a
     * customer's CRM to find out.
     */
    public function testConnection(Integration $integration): bool;

    /**
     * Create or update the company, and say which record it is.
     *
     * "Upsert" is the connector's problem: a provider with a search-by-domain endpoint should use
     * it, and `integration_links` is consulted first by the Action anyway. Returning a
     * {@see CrmRecord} rather than a bare id so a link row can be written without the Action
     * knowing what that provider calls a company.
     */
    public function upsertCompany(Integration $integration, Lead $lead): CrmRecord;

    /**
     * Create or update a person against a company record.
     *
     * The contact arrives already filtered: a value nobody has paid to reveal is not passed, and
     * neither is a suppressed address (ADR 0029, ADR 0032). A connector receives what may leave.
     */
    public function upsertPerson(
        Integration $integration,
        LeadContact $contact,
        CrmRecord $company,
    ): CrmRecord;

    /**
     * Attach a note to a record.
     */
    public function createNote(Integration $integration, CrmRecord $on, string $text): void;

    /**
     * Open a deal or a CRM-side lead for this company, where the team asked for one.
     *
     * Nullable, because not every provider has the concept under every plan and a team may not want
     * one. A connector that cannot do it returns null rather than throwing: a deal is the optional
     * part of a push, and failing the whole sync over it would lose the organisation too.
     */
    public function createDealOrLead(
        Integration $integration,
        Lead $lead,
        CrmRecord $company,
    ): ?CrmRecord;
}
