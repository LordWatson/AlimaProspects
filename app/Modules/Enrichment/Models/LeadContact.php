<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Enrichment\Enums\ContactEnrichmentState;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\PhoneType;
use App\Modules\LeadSourcing\Models\Lead;
use Database\Factories\LeadContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A contact point for a {@see Lead}. Carries its own source attribution and, for emails, the
 * verification result. Tenant scoped via {@see BelongsToTeam}. The platform prefers business/role
 * contacts; personal-looking ones are flagged (`is_personal`) for compliance rather than silently
 * used.
 *
 * Two eras live on one row, and the row says which is which. Phase 8 fills it from AI extraction of
 * the lead's public web text, free and unverified beyond an MX check (ADR 0008). PL2 adds the
 * columns a **paid** vendor fills: the person's parts, a verified address, a mobile, and the stamps
 * saying who paid for what. `email_source` and `phone_source` are how a reader tells them apart,
 * and the distinction is not academic — it decides whether a value is masked (ADR 0029).
 *
 * **The reveal rule, because it is the one thing about this model that is easy to get wrong.**
 * Masking exists so the product can show that a contact *exists* and charge to show *which*. That
 * only makes sense for something we paid a vendor for. An address read off the company's own
 * contact page is already visible to the member on that page, cost nothing, and is what Outreach
 * has been sending to since Phase 11 — masking it would be theatre and a regression in the same
 * change. So {@see self::requiresReveal()} asks whether a vendor was paid, not whether a value
 * exists.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property string|null $name
 * @property string|null $title
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $department
 * @property string|null $seniority the vendor's own word for the level; ranked by SeniorityLadder
 * @property string|null $linkedin_url
 * @property string|null $email_hash sha-256 of the normalised address — the identity, not the value
 * @property EmailVerificationStatus|null $email_verification_status
 * @property int|null $email_confidence
 * @property Carbon|null $email_verified_at
 * @property string|null $email_source the provider key; null means our own free-tier check
 * @property string|null $phone_e164 the dialable form; null until PL3 can normalise one
 * @property string|null $phone_hash bare digits, hashed — matches a suppression, unlike E.164
 * @property PhoneType|null $phone_type
 * @property string|null $phone_source
 * @property Carbon|null $phone_found_at
 * @property Carbon|null $revealed_email_at when a member spent on seeing it; null means masked
 * @property Carbon|null $revealed_phone_at
 * @property ContactEnrichmentState $enrichment_state per contact, not per lead
 * @property Carbon|null $enriched_at
 * @property bool $is_role_based
 * @property bool $is_personal
 * @property bool $is_inferred whether the email was inferred from the domain + a named person rather
 *                             than read off a page — a guess (no SMTP probe, ADR 0008), so it counts
 *                             toward nothing in a lead's readiness
 * @property string $source
 * @property string|null $source_url
 * @property string|null $method
 * @property Carbon|null $collected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadContact extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadContactFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enrichment_state' => ContactEnrichmentState::Idle->value,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'name',
        'first_name',
        'last_name',
        'title',
        'department',
        'seniority',
        'linkedin_url',
        'email',
        'email_hash',
        'phone',
        'phone_e164',
        'phone_hash',
        'phone_type',
        'phone_source',
        'phone_found_at',
        'email_verification_status',
        'email_confidence',
        'email_verified_at',
        'email_source',
        'enrichment_state',
        'enriched_at',
        'is_role_based',
        'is_personal',
        'is_inferred',
        'source',
        'source_url',
        'method',
        'collected_at',
    ];

    /**
     * The lead this contact belongs to.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Whether this contact's **email** is something a member has to spend to see.
     *
     * True only for an address a paid vendor produced. See the class note: masking an address we
     * read off the company's own contact page would be theatre, and it would break the outreach
     * that has been sending to those addresses since Phase 11.
     */
    public function requiresEmailReveal(): bool
    {
        return $this->email !== null
            && $this->email_source !== null
            && $this->revealed_email_at === null;
    }

    /**
     * Whether this contact's **phone** is something a member has to spend to see.
     *
     * A switchboard number is never masked even when a vendor supplied it: it is the company's own
     * published number, a business fact rather than a person's contact detail, and charging to
     * reveal something a prospect prints on its homepage is not a product, it is a toll.
     */
    public function requiresPhoneReveal(): bool
    {
        return $this->phoneValue() !== null
            && $this->phone_source !== null
            && $this->phone_type?->isPersonal() !== false
            && $this->revealed_phone_at === null;
    }

    /**
     * The address as a screen should show it: whole, or `j***@acme.com`.
     *
     * The domain survives masking because the domain is the **company**, which is not the private
     * half and is usually already on the lead — and because a member comparing two masked
     * addresses needs something to tell them apart.
     */
    public function displayEmail(): ?string
    {
        if ($this->email === null) {
            return null;
        }

        return $this->requiresEmailReveal()
            ? ContactIdentity::maskEmail($this->email)
            : $this->email;
    }

    /**
     * The number as a screen should show it: whole, or `***4821`.
     */
    public function displayPhone(): ?string
    {
        $value = $this->phoneValue();

        if ($value === null) {
            return null;
        }

        return $this->requiresPhoneReveal() ? ContactIdentity::maskPhone($value) : $value;
    }

    /**
     * The best form of the number we hold: the dialable one where PL3 has normalised it, else
     * whatever the source gave us.
     */
    public function phoneValue(): ?string
    {
        return $this->phone_e164 ?? $this->phone;
    }

    /**
     * Set the address and its identity together.
     *
     * One method because they must never disagree: an `email` written without its `email_hash` is
     * an address that matches no suppression entry, and the failure mode of that is a message to
     * somebody who opted out. Returns false when the value is not an address at all.
     */
    public function setEmail(?string $email, ?string $source = null): bool
    {
        if ($email === null) {
            $this->email = null;
            $this->email_hash = null;
            $this->email_source = $source;

            return true;
        }

        $normalised = ContactIdentity::email($email);

        if ($normalised === null) {
            return false;
        }

        $this->email = $normalised;
        $this->email_hash = ContactIdentity::hash($normalised);
        $this->email_source = $source;

        return true;
    }

    /**
     * Set the number, its identity and its kind together, for the same reason as
     * {@see self::setEmail()}.
     *
     * `$e164` is passed separately rather than derived: turning a number into E.164 needs a country
     * and a library (PL3), and guessing it here would store a number that dials somewhere else.
     */
    public function setPhone(?string $phone, ?PhoneType $type = null, ?string $source = null, ?string $e164 = null): bool
    {
        if ($phone === null) {
            $this->phone = null;
            $this->phone_e164 = null;
            $this->phone_hash = null;
            $this->phone_type = null;
            $this->phone_source = $source;

            return true;
        }

        $digits = ContactIdentity::phone($phone);

        if ($digits === null) {
            return false;
        }

        $this->phone = $phone;
        $this->phone_e164 = $e164;
        $this->phone_hash = ContactIdentity::hash($digits);
        $this->phone_type = $type ?? PhoneType::Unknown;
        $this->phone_source = $source;

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verification_status' => EmailVerificationStatus::class,
            'email_confidence' => 'integer',
            'email_verified_at' => 'datetime',
            'phone_type' => PhoneType::class,
            'phone_found_at' => 'datetime',
            'enrichment_state' => ContactEnrichmentState::class,
            'enriched_at' => 'datetime',
            'is_role_based' => 'boolean',
            'is_personal' => 'boolean',
            'is_inferred' => 'boolean',
            'collected_at' => 'datetime',
            // Neither reveal stamp is fillable: a reveal is a **metered act** (PL4), and the only
            // thing allowed to claim one happened is the Action that charged for it. A mass-assign
            // from a form that could set these would be a free reveal.
            'revealed_email_at' => 'datetime',
            'revealed_phone_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadContactFactory
    {
        return LeadContactFactory::new();
    }
}
