<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Integrations\Contracts\CrmConnector;
use App\Modules\Integrations\Contracts\CrmRowAllowance;
use App\Modules\Integrations\Data\CrmRecord;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Enums\SyncOutcome;
use App\Modules\Integrations\Enums\SyncTarget;
use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLink;
use App\Modules\Integrations\Models\IntegrationSyncLog;
use App\Modules\Integrations\Services\CrmConnectorRegistry;
use App\Modules\Integrations\Services\CrmNoteComposer;
use App\Modules\Integrations\Services\TokenRefresher;
use App\Modules\Integrations\Services\WebhookDispatcher;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes one lead to one CRM (PL7).
 *
 * The order below is the design, and three of the steps are deliberately **before** any connector
 * is reached:
 *
 * 1. **May we?** `ContactApprovalGate::allowsDomain()` — PL5's seam, PL6's third question. A CRM is
 *    a place a record goes in order to be contacted from, and once an organisation is in somebody's
 *    Pipedrive no later opt-out can reach it. That is ADR 0033's argument for the export, and it is
 *    the same argument: this is a path where honouring the list at the point of sending is not good
 *    enough. A refusal is a **logged row**, not a silence — "why is this company not in my CRM" has
 *    to have an answer.
 * 2. **Has anything changed?** The `synced_hash` on the link. Without it an auto-sync rule that
 *    fires on every re-score would re-push every organisation a team owns, nightly, for ever.
 * 3. **Can they afford it?** One unit of `BillingMetric::CrmSync`, held before the call and settled
 *    after it. Held rather than counted afterwards so two concurrent pushes cannot both be told
 *    there is room, and released on a skip or a refusal because neither moved a record.
 *
 * Then the connector does its part, and it is the only part that varies by provider: upsert the
 * company, link it, attach the note, upsert the people whose details may actually leave, and open a
 * deal if the team asked for one.
 *
 * **A failed deal does not fail the push.** It is the optional half, and losing the organisation
 * because the pipeline id in the team's settings was stale would be the wrong trade.
 */
final class SyncLeadToCrmAction
{
    /**
     * The `synced_hash` of a push that has started and not finished.
     *
     * Empty rather than a sentinel string, because the only property it needs is that it can never
     * equal a `sha256` — and "" is the shortest thing with that property.
     */
    private const string INCOMPLETE = '';

    public function __construct(
        private readonly CrmConnectorRegistry $connectors,
        private readonly TokenRefresher $tokens,
        private readonly ContactApprovalGate $approval,
        private readonly CrmRowAllowance $allowance,
        private readonly CrmNoteComposer $notes,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    public function handle(Integration $integration, Lead $lead): SyncOutcome
    {
        $team = $integration->team;

        if ($team === null || ! $integration->status->canSync()) {
            return $this->log($integration, $lead, SyncOutcome::Refused, 'The connection is not usable.');
        }

        if (! $this->isApproved($team, $lead)) {
            return $this->log($integration, $lead, SyncOutcome::Refused, 'The company is on your do-not-contact list.');
        }

        $link = $this->existingLink($integration, $lead);
        $fingerprint = $this->fingerprint($lead);

        if ($link !== null && $link->synced_hash === $fingerprint) {
            return $this->log($integration, $lead, SyncOutcome::Skipped);
        }

        $reservation = $this->allowance->reserve($team, 1);

        if ($reservation === null) {
            return $this->log($integration, $lead, SyncOutcome::Refused, 'Your CRM sync allowance is spent.');
        }

        try {
            $outcome = $this->push($integration, $lead, $link, $fingerprint);
        } catch (Throwable $e) {
            $this->allowance->release($reservation);

            // Ids and the exception class. A provider's error body can carry a token.
            Log::warning('CRM push failed.', [
                'integration_id' => $integration->getKey(),
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'exception' => $e::class,
            ]);

            // **A dead credential is not downgraded to an ordinary error.** The refresher has
            // already set `needs_reauth` and told the team's admins, and the two statuses mean
            // different things to the next run: `error` is pushable, because the next attempt is
            // how it recovers. Overwriting it here would have the sync retrying a credential only
            // a person can replace, for ever, with nobody told.
            if ($integration->fresh()?->status !== IntegrationStatus::NeedsReauth) {
                $integration->forceFill([
                    'status' => IntegrationStatus::Error,
                    // The exception **class**, not its message, for the reason the refresher gives:
                    // a provider's error body has been known to echo the token back, and this
                    // column is sent to every member of the team by `IntegrationResource`.
                    'last_error' => $e::class,
                ])->save();
            }

            return $this->log($integration, $lead, SyncOutcome::Failed, $e::class);
        }

        // Settled against what happened: a skip we did not predict costs nothing. Wrapped,
        // because the push has already happened — a `commit()` that throws here must not leave the
        // hold open against the team's allowance until the month turns over.
        try {
            $this->allowance->commit($reservation, $outcome->isBilled() ? 1 : 0);
        } catch (Throwable $e) {
            Log::error('Could not settle a CRM sync hold; releasing it.', [
                'integration_id' => $integration->getKey(),
                'exception' => $e::class,
            ]);

            $this->allowance->release($reservation);
        }

        $integration->forceFill([
            'status' => IntegrationStatus::Connected,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        $this->webhooks->dispatch($team, WebhookEvent::LeadSentToCrm, [
            'lead_id' => (int) $lead->getKey(),
            'company_name' => $lead->company_name,
            'provider' => $integration->provider->value,
            'outcome' => $outcome->value,
        ]);

        return $this->log($integration, $lead, $outcome);
    }

    /**
     * The connector's half, inside the token refresher.
     */
    private function push(
        Integration $integration,
        Lead $lead,
        ?IntegrationLink $link,
        string $fingerprint,
    ): SyncOutcome {
        $connector = $this->connectors->for($integration->provider);

        $company = $this->tokens->call(
            $integration,
            $connector,
            fn (Integration $live): CrmRecord => $connector->upsertCompany($live, $lead),
        );

        // **The link is written immediately, but with no fingerprint yet.** Two things have to be
        // true at once and they pull in opposite directions: a retry must find the organisation we
        // just created rather than create a second one in somebody's CRM, and it must not be told
        // that this lead is already up to date. So the external id is recorded now and
        // `synced_hash` is left empty until the note and the people are through — an empty hash can
        // never equal a fingerprint, so a partial push is a real attempt next time rather than a
        // `Skipped` that strands the company with no note and no contacts, for ever, silently.
        $this->writeLink($integration, $lead->getKey(), SyncTarget::Lead, $company, self::INCOMPLETE);

        $connector->createNote($integration, $company, $this->note($lead));

        $this->pushContacts($integration, $connector, $lead, $company);
        $this->openDeal($integration, $connector, $lead, $company);

        // Everything that was going to reach the CRM has. *Now* the lead is up to date.
        $this->writeLink($integration, $lead->getKey(), SyncTarget::Lead, $company, $fingerprint);

        return $link === null ? SyncOutcome::Created : SyncOutcome::Updated;
    }

    /**
     * The people whose details may actually leave.
     *
     * Two filters, and they are the same two the export applies (ADR 0033 §2). A value nobody has
     *
     * paid to reveal is **not sent at all** rather than sent masked, because `j***@acme.com` in a
     * CRM is worse than a blank — something there will eventually try to send to it. A suppressed
     * address is dropped although the company is fine: the company is not the address.
     *
     * A contact with nothing left to send is skipped entirely. A person's name in a CRM with no way
     * to reach them is a record somebody will go and find a number for.
     */
    private function pushContacts(
        Integration $integration,
        CrmConnector $connector,
        Lead $lead,
        CrmRecord $company,
    ): void {
        $team = $integration->team;

        if ($team === null) {
            return;
        }

        foreach ($lead->contacts as $contact) {
            if (! $this->isSendable($team, $contact)) {
                continue;
            }

            $record = $this->tokens->call(
                $integration,
                $connector,
                fn (Integration $live): CrmRecord => $connector->upsertPerson($live, $contact, $company),
            );

            $this->writeLink(
                $integration,
                $contact->getKey(),
                SyncTarget::Contact,
                $record,
                $this->contactFingerprint($contact),
            );
        }
    }

    /**
     * A deal, where the team asked for one — and never at the cost of the organisation.
     */
    private function openDeal(
        Integration $integration,
        CrmConnector $connector,
        Lead $lead,
        CrmRecord $company,
    ): void {
        if ((bool) ($integration->settings['create_deals'] ?? false) !== true) {
            return;
        }

        try {
            $connector->createDealOrLead($integration, $lead, $company);
        } catch (Throwable $e) {
            Log::warning('CRM deal creation failed; the company was still pushed.', [
                'integration_id' => $integration->getKey(),
                'lead_id' => $lead->getKey(),
                'exception' => $e::class,
            ]);
        }
    }

    private function note(Lead $lead): string
    {
        return $this->notes->compose($lead, $lead->evidence()->newestFirst()->limit(10)->get());
    }

    /**
     * Whether this company may be in a CRM at all.
     *
     * A lead with no website cannot be asked the domain question, and is allowed: refusing it would
     * mean a company nobody has found a site for can never be synced, which is a lot of perfectly
     * contactable small businesses.
     */
    private function isApproved(Team $team, Lead $lead): bool
    {
        $website = (string) $lead->website;

        return $website === '' || $this->approval->allowsDomain($team, $website);
    }

    private function isSendable(Team $team, LeadContact $contact): bool
    {
        $email = $contact->requiresEmailReveal() ? null : $contact->email;
        $phone = $contact->requiresPhoneReveal() ? null : $contact->phoneValue();

        if ($email !== null && $email !== '' && $this->approval->allowsEmail($team, $email)) {
            return true;
        }

        return $phone !== null && $phone !== '' && $this->approval->allowsPhone($team, $phone);
    }

    private function existingLink(Integration $integration, Lead $lead): ?IntegrationLink
    {
        return IntegrationLink::query()
            ->withoutGlobalScopes()
            ->where('integration_id', $integration->getKey())
            ->where('local_type', SyncTarget::Lead->value)
            ->where('local_id', $lead->getKey())
            ->first();
    }

    private function writeLink(
        Integration $integration,
        int|string $localId,
        SyncTarget $type,
        CrmRecord $record,
        string $fingerprint,
    ): void {
        IntegrationLink::query()->withoutGlobalScopes()->updateOrCreate([
            'integration_id' => $integration->getKey(),
            'local_type' => $type->value,
            'local_id' => (int) $localId,
            'external_type' => $record->type,
        ], [
            'team_id' => $integration->team_id,
            'external_id' => $record->id,
            'synced_hash' => $fingerprint,
            'synced_at' => now(),
        ]);
    }

    /**
     * What we last sent, hashed.
     *
     * Over the **fields a CRM can see** rather than over the lead row, which is the only version of
     * this that is useful: `updated_at` moves when somebody adds a private note, and a hash keyed
     * on that would re-push every organisation a team owns on the next sweep. The why-now summary
     * is in it because it is in the note, so a regenerated sentence does reach the CRM.
     *
     * A shortcut: the hash is over what *we* think the connector sends, not over its actual
     * request. A connector that mapped a field this list does not name would not re-push when that
     * field changed. Upgrade when the first connector's mapping is configurable per team.
     */
    private function fingerprint(Lead $lead): string
    {
        return hash('sha256', (string) json_encode([
            $lead->company_name,
            $lead->company_number,
            $lead->website,
            $lead->industry,
            $lead->employee_range,
            $lead->locality,
            $lead->region,
            $lead->postal_code,
            $lead->country,
            $lead->why_now_summary,
        ]));
    }

    private function contactFingerprint(LeadContact $contact): string
    {
        return hash('sha256', (string) json_encode([
            $contact->name,
            $contact->title,
            $contact->requiresEmailReveal() ? null : $contact->email,
            $contact->requiresPhoneReveal() ? null : $contact->phoneValue(),
        ]));
    }

    /**
     * One row per push, whatever happened, and the outcome handed straight back to the caller.
     */
    private function log(
        Integration $integration,
        Lead $lead,
        SyncOutcome $outcome,
        ?string $error = null,
    ): SyncOutcome {
        IntegrationSyncLog::query()->withoutGlobalScopes()->create([
            'team_id' => $integration->team_id,
            'integration_id' => $integration->getKey(),
            'local_type' => SyncTarget::Lead->value,
            'local_id' => $lead->getKey(),
            'outcome' => $outcome->value,
            'error' => $error,
        ]);

        return $outcome;
    }
}
