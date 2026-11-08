<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a contact needs to hold once a paid vendor can fill it in (PL2).
 *
 * `lead_contacts` is already the product's contact record — it is the plan's `contacts` table under
 * the name it shipped with — so this extends it rather than building a second one beside it
 * (ADR 0026 §2d). Nothing here changes what the free-tier extraction writes; every column is
 * nullable or defaulted, and a contact read off a public page today looks exactly as it did.
 *
 * Three pairs of columns are worth reading carefully, because each pair is two different questions
 * that look like one:
 *
 * - **`email` and `email_hash`.** The address, and its identity. The hash is what matches a
 *   suppression entry, and it is computed by the *same* normaliser `outreach_suppressions` uses
 *   (`Core\Support\ContactIdentity`) — if the two ever diverged, the consequence would not be a
 *   missed index, it would be an email sent to somebody who opted out.
 * - **`phone_e164` and `phone_hash`.** The dialable form, and the matchable one. They are
 *   deliberately different: E.164 needs a country and carries a `+`, while the hash is bare digits
 *   so that one number written four ways still matches one suppression entry.
 * - **`revealed_*_at` and `*_found_at`.** When a member spent on it, and when we got it. Only the
 *   first decides what a screen shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_contacts', function (Blueprint $table): void {
            // --- The person -------------------------------------------------------------------
            // `name` stays the canonical display name and is NOT duplicated as `full_name`: a
            // vendor returns the parts, a page returns the whole, and the product shows one thing.
            // `title` likewise stays the job title rather than gaining a `job_title` beside it.
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('department')->nullable()->after('title');
            // Free text, not an enum: a team writes "Head of Ops" because that is what the job is
            // called at the companies they sell to, and `Scoring\Support\SeniorityLadder` is what
            // makes that comparable. Nothing branches on the value (ADR 0018 §1).
            $table->string('seniority', 64)->nullable()->after('department');
            $table->string('linkedin_url', 512)->nullable()->after('seniority');

            // --- Email ------------------------------------------------------------------------
            // SHA-256 of the normalised address, for suppression matching and de-duplication.
            // Nullable, because a phone-only contact has no address — and both MySQL and SQLite
            // treat nulls in a unique index as distinct, so those rows never collide.
            $table->char('email_hash', 64)->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('email_confidence');
            // The provider key that produced the verdict. Null means our own free-tier check.
            $table->string('email_source', 32)->nullable()->after('email_verified_at');

            // --- Phone ------------------------------------------------------------------------
            // The dialable form. Normalising to it needs a country, which is PL3's job with
            // libphonenumber; until then a vendor's own formatting is stored on `phone` and this
            // stays null rather than holding a half-normalised number.
            $table->string('phone_e164', 32)->nullable()->after('phone');
            $table->char('phone_hash', 64)->nullable()->after('phone_e164');
            $table->string('phone_type', 16)->nullable()->after('phone_hash');  // PhoneType value
            $table->string('phone_source', 32)->nullable()->after('phone_type');
            $table->timestamp('phone_found_at')->nullable()->after('phone_source');

            // --- Reveals (metered in PL4) -----------------------------------------------------
            // When a member spent on seeing it. Null means masked — but only for a value a vendor
            // was paid for: what we read off a public page needs no reveal (ADR 0029).
            $table->timestamp('revealed_email_at')->nullable()->after('phone_found_at');
            $table->timestamp('revealed_phone_at')->nullable()->after('revealed_email_at');

            // --- Pipeline ---------------------------------------------------------------------
            // Per **contact**, not per lead: `leads.enrichment_status` tracks the free-tier pass
            // over the company, and a lead can be fully enriched while one of its four contacts is
            // still having its mobile looked for. One column answering both would make "enriched"
            // mean nothing on either.
            $table->string('enrichment_state', 16)->default('idle')->after('revealed_phone_at');
            $table->timestamp('enriched_at')->nullable()->after('enrichment_state');

            // One contact per (team, lead, address). The existing `(lead_id, email)` unique stays —
            // it is the same guarantee on the plain value and has shipped — and this one is the
            // guarantee on the *identity*, which is what catches `Jane@Acme.com` arriving beside
            // `jane@acme.com`. A partial index ("where not null") is not portable, and is not
            // needed: nulls are distinct in both engines.
            $table->unique(['team_id', 'lead_id', 'email_hash'], 'lead_contacts_team_lead_email_hash_unique');

            // The suppression-style lookups: "do we already hold this address / this number".
            $table->index(['team_id', 'email_hash'], 'lead_contacts_team_email_hash_index');
            $table->index(['team_id', 'phone_hash'], 'lead_contacts_team_phone_hash_index');
            // The reveal and spend screens, and PL3's "has this contact been looked at recently".
            $table->index(['team_id', 'enrichment_state'], 'lead_contacts_team_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('lead_contacts', function (Blueprint $table): void {
            $table->dropUnique('lead_contacts_team_lead_email_hash_unique');
            $table->dropIndex('lead_contacts_team_email_hash_index');
            $table->dropIndex('lead_contacts_team_phone_hash_index');
            $table->dropIndex('lead_contacts_team_state_index');

            $table->dropColumn([
                'first_name',
                'last_name',
                'department',
                'seniority',
                'linkedin_url',
                'email_hash',
                'email_verified_at',
                'email_source',
                'phone_e164',
                'phone_hash',
                'phone_type',
                'phone_source',
                'phone_found_at',
                'revealed_email_at',
                'revealed_phone_at',
                'enrichment_state',
                'enriched_at',
            ]);
        });
    }
};
