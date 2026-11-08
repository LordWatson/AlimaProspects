<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Http\Resources;

use App\Modules\Enrichment\Models\LeadContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One contact, as every screen and every API client reads it.
 *
 * **`email` and `phone` are what a client may show, masked where a reveal is owed** (PL2). The raw
 * value is not sent alongside under another name, which is the only way masking means anything: a
 * `masked_email` beside a plain `email` is a mask any client can walk around, and the first one
 * that does is the one that gives away what PL4 charges for.
 *
 * `tps_status` and `allows_dial_link` are the PL5 pair. A client offers a `tel:` link when
 * `allows_dial_link` is true and shows the label either way — it must not compare the status to a
 * single case, because `unchecked`, `clear` and `not_applicable` all permit a call and only one of
 * them means the number was screened.
 *
 * `requires_email_reveal` is what the Reveal button binds to. It is **not** "do we hold an
 * address": an address we read off the company's own contact page needs no reveal (ADR 0029), so a
 * client that wanted to know whether a contact is reachable at all reads
 * `email_verification_status` and `has_phone`.
 *
 * @mixin LeadContact
 */
final class LeadContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'title' => $this->title,
            'department' => $this->department,
            'seniority' => $this->seniority,
            'linkedin_url' => $this->linkedin_url,

            // Masked where a vendor was paid and nobody has revealed it yet. One field, so there
            // is nothing to walk around.
            'email' => $this->displayEmail(),
            'phone' => $this->displayPhone(),
            'requires_email_reveal' => $this->requiresEmailReveal(),
            'requires_phone_reveal' => $this->requiresPhoneReveal(),
            'revealed_email_at' => $this->revealed_email_at?->toIso8601String(),
            'revealed_phone_at' => $this->revealed_phone_at?->toIso8601String(),

            'email_verification_status' => $this->email_verification_status?->value,
            'email_verification_label' => $this->email_verification_status?->label(),
            'email_confidence' => $this->email_confidence,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            // The provider key, so a screen can say where a verdict came from and an operator can
            // tell a vendor's answer from our own MX check. Not a secret: it is our supplier, not
            // a credential.
            'email_source' => $this->email_source,

            // Whether a number exists at all, for a client that must decide between "Reveal" and
            // "Find a number" without being told the number.
            'has_phone' => $this->phoneValue() !== null,
            'phone_type' => $this->phone_type?->value,
            'phone_type_label' => $this->phone_type?->label(),
            // Only a mobile can take a WhatsApp deep link; a landline would open a chat that can
            // never be delivered.
            'phone_accepts_messaging' => $this->phone_type?->acceptsMessaging() ?? false,
            'phone_source' => $this->phone_source,

            // The do-not-call verdict (PL5). `unchecked` is **not** `clear`, and the label says so
            // — the distinction is the whole value of the column. `allows_dial_link` is what a
            // client binds to rather than comparing the status itself: a literal compare against
            // one case is a bug waiting for the enum to grow (ADR 0029's own lesson).
            'tps_status' => $this->tps_status->value,
            'tps_status_label' => $this->tps_status->label(),
            'tps_checked_at' => $this->tps_checked_at?->toIso8601String(),
            'allows_dial_link' => $this->tps_status->allowsDialLink(),

            'is_role_based' => $this->is_role_based,
            'is_personal' => $this->is_personal,
            'is_inferred' => $this->is_inferred,

            'enrichment_state' => $this->enrichment_state->value,
            'enrichment_state_label' => $this->enrichment_state->label(),
            'enrichment_in_flight' => $this->enrichment_state->isInFlight(),
            'enriched_at' => $this->enriched_at?->toIso8601String(),

            'source' => $this->source,
            'source_url' => $this->source_url,
            'collected_at' => $this->collected_at?->toIso8601String(),
        ];
    }
}
