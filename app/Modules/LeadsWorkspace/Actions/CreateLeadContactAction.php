<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\EmailVerifier;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Exceptions\LeadsWorkspaceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One contact on an existing lead, typed in by a member — the answer to a lead whose Contacts panel
 * is empty because nothing published an address.
 *
 * Three things about it, and none of them is new policy:
 *
 * - **It is not a reveal.** `email_source` and `phone_source` stay null, which is what tells
 *   {@see LeadContact::requiresEmailReveal()} there is nothing to charge for: nobody paid a vendor
 *   for a number the member read off a business card (ADR 0029).
 * - **It still asks the gate.** Storing is the first of the gate's two moments, so an address on
 *   the team's do-not-contact list is refused here rather than stored and filtered at send time
 *   (ADR 0032, `docs/compliance.md` §3.9). A refusal is a message to the member, not a silent drop:
 *   they typed it, so they are owed the reason.
 * - **It is written as unverified.** `email_verification_status` is left null for the normal
 *   verification to judge, exactly as {@see CreateLeadAction} leaves a contact typed into the
 *   add-a-lead form. The classification rules are that Action's, kept in step by both asking
 *   {@see EmailVerifier::isRoleBased()} — the one definition of a role mailbox.
 *
 * Must be called inside the lead's team context.
 */
final class CreateLeadContactAction
{
    public function __construct(
        private readonly RecordLeadActivityAction $record,
        private readonly ContactApprovalGate $approval,
        // For its role-mailbox classification only — a pure config lookup. Verification itself
        // resolves DNS, which has no business running inside this write.
        private readonly EmailVerifier $emailVerifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: ?string, title: ?string, email: ?string, phone: ?string}  $fields
     *
     * @throws LeadsWorkspaceException when the team may not hold this address or number, or when
     *                                 the lead already has this contact
     */
    public function handle(Team $team, Lead $lead, User $actor, array $fields): LeadContact
    {
        $email = $fields['email'] === null ? null : ContactIdentity::email($fields['email']);
        $phone = $fields['phone'];

        // Refused rather than stored with the unusable half quietly dropped. A value we cannot
        // reduce to a comparable form is one no suppression entry can ever match, and "ask
        // reception" typed into the phone field is a typo the member can fix — storing the row
        // without it would tell them it worked.
        if (($email === null && $phone === null)
            || ($fields['email'] !== null && $email === null)
            || ($phone !== null && ContactIdentity::phone($phone) === null)) {
            throw LeadsWorkspaceException::unusableContact();
        }

        if ($email !== null && ! $this->approval->allowsEmail($team, $email)) {
            throw LeadsWorkspaceException::contactSuppressed();
        }

        if ($phone !== null && ! $this->approval->allowsPhone($team, $phone)) {
            throw LeadsWorkspaceException::contactSuppressed();
        }

        if ($this->duplicate($lead, $email, $fields['name'])) {
            throw LeadsWorkspaceException::contactExists();
        }

        $isRoleBased = $email !== null && $this->emailVerifier->isRoleBased($email);

        // What was typed amounts to personal data unless it is nothing but a role mailbox:
        // `info@` on its own is a company's front door, not a person. A number with no name
        // attached is still somebody's number. The same rule the add-a-lead form applies, so the
        // compliance screens read one answer.
        $isPersonal = $fields['name'] !== null || $phone !== null || ! $isRoleBased;

        return DB::transaction(function () use ($lead, $actor, $fields, $email, $phone, $isRoleBased, $isPersonal): LeadContact {
            $contact = new LeadContact;

            $contact->forceFill([
                'team_id' => $lead->team_id,
                'lead_id' => $lead->getKey(),
                'name' => $fields['name'],
                'title' => $fields['title'],
                'is_role_based' => $isRoleBased,
                'is_personal' => $isPersonal,
                'source' => CreateLeadAction::CONTACT_SOURCE,
                'method' => CreateLeadAction::CONTACT_METHOD,
                'collected_at' => now(),
            ]);

            // Through the setters, so the identity hash is written with the value: an `email`
            // stored without its `email_hash` matches no suppression entry, and the failure mode
            // of that is a message to somebody who opted out.
            $contact->setEmail($email);
            $contact->setPhone($phone);

            $contact->save();

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::ContactAdded,
                subject: $contact,
                properties: ['contact' => $this->describe($contact)],
                actor: $actor,
            );

            // A named person, or anyone's direct line, makes the lead hold personal data. Raised
            // but never dropped, the same way the add-a-lead form does it.
            if ($isPersonal && ! $lead->has_personal_data) {
                $lead->forceFill(['has_personal_data' => true])->save();
            }

            // The audit row says that a contact was added and never what it is: an address is
            // exactly the kind of personal detail the audit trail has no business duplicating.
            $this->audit->log('leads.contact_added', $contact, [
                'lead_id' => $lead->getKey(),
                'has_email' => $contact->email !== null,
                'has_phone' => $contact->phone !== null,
            ], $actor);

            return $contact;
        });
    }

    /**
     * Whether this lead already has this contact: the same address, or the same name where there is
     * no address to go on. Adding the same card twice is a double submit, not a second person.
     */
    private function duplicate(Lead $lead, ?string $email, ?string $name): bool
    {
        if ($email !== null) {
            return $lead->contacts()->where('email', $email)->exists();
        }

        return $name !== null
            && $lead->contacts()->whereNull('email')->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists();
    }

    /**
     * What the timeline says happened. The name where there is one, else the address — the timeline
     * is the team's own record of this company, so "a contact was added" would be useless, and the
     * values here are already on the screen above it.
     */
    private function describe(LeadContact $contact): string
    {
        return $contact->name ?? $contact->email ?? (string) $contact->phone;
    }
}
