<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Compliance & Privacy configuration (Phase 15)
|--------------------------------------------------------------------------
|
| The knobs behind the guarantees in docs/compliance.md: how long data is
| kept, when a legitimate-interest assessment goes stale, and what the hosted
| privacy notice says about who is processing and where.
|
| Two of these are **legal** settings rather than technical ones, and are
| deliberately not buried in code: the retention defaults (section 3.4) and
| the privacy-notice particulars (section 3.7). A deployment's counsel changes
| them in the environment; nobody edits a class to shorten a retention window.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Storage limitation, enforced by `compliance:purge` (docs/compliance.md
    | section 3.4). Two windows, because the two cases are not alike:
    |
    | - A lead **nobody ever approached** is a company record we hold on a
    |   legitimate-interest basis that was never acted on. It is deleted.
    | - A lead the team **did approach** and has since left alone is
    |   **anonymised**, not deleted: the personal data goes, the company record
    |   and the fact that an approach happened stay, because that is the team's
    |   own accountability record and holds no personal data.
    |
    | Both windows are per team and editable in the product; these are the
    | defaults a team starts with. Generous on purpose — a window shorter than
    | a sales cycle deletes live work, and the stricter reading of storage
    | limitation is not the one that makes the product unusable.
    |
    */

    'retention' => [
        'defaults' => [
            // Six months with no approach. A company sourced and never contacted
            // in half a year was not a prospect.
            'uncontacted_lead_days' => (int) env('COMPLIANCE_RETENTION_UNCONTACTED_DAYS', 180),

            // Eighteen months of silence after the last approach or activity.
            'contacted_lead_days' => (int) env('COMPLIANCE_RETENTION_CONTACTED_DAYS', 540),
        ],

        // The shortest window a team may set. A team that sets seven days has
        // misread the field, and retention is irreversible.
        'min_days' => (int) env('COMPLIANCE_RETENTION_MIN_DAYS', 30),

        // The longest. Past this, "we keep it for as long as it is useful" has
        // stopped being a retention policy.
        'max_days' => (int) env('COMPLIANCE_RETENTION_MAX_DAYS', 1825),

        // Leads per pass. The sweep touches several tables per lead, so it works
        // in chunks and stays interruptible.
        'chunk' => (int) env('COMPLIANCE_RETENTION_CHUNK', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legitimate Interest Assessment
    |--------------------------------------------------------------------------
    |
    | The recorded LIA gates discovery (docs/compliance.md section 2). An
    | assessment is a judgement about a balance that moves — the team's market,
    | its data sources, what it sends — so it expires and has to be re-affirmed
    | rather than being a one-off checkbox. Twelve months matches the ICO's own
    | suggested review cadence.
    |
    */

    'lia' => [
        'review_after_months' => (int) env('COMPLIANCE_LIA_REVIEW_MONTHS', 12),

        // How long each written section may be. Long enough for a real
        // assessment, short enough that the column holds it.
        'section_limit' => (int) env('COMPLIANCE_LIA_SECTION_LIMIT', 4000),

        // The shortest answer that can be a considered one. A single word in the
        // balancing test is not an assessment, and the gate it opens is the
        // lawful basis for every lead the team sources.
        'section_minimum' => (int) env('COMPLIANCE_LIA_SECTION_MINIMUM', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data-subject requests
    |--------------------------------------------------------------------------
    |
    | MVP-light: locate, then erase on a human's confirmation (section 3.5).
    | Matching is on an identity the subject gives us — an address, a number or
    | a name — and a name match is **candidates for review**, never an
    | automatic erasure. Identity verification for non-users is an open legal
    | question (section 5).
    |
    */

    'dsar' => [
        // Shorter than this matches half the database.
        'min_query_length' => (int) env('COMPLIANCE_DSAR_MIN_QUERY', 3),

        // How many matching records one search reports. A request about one
        // person does not return a thousand rows; a search that hits the cap is
        // a search that needs narrowing.
        'max_results' => (int) env('COMPLIANCE_DSAR_MAX_RESULTS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Team data export
    |--------------------------------------------------------------------------
    |
    | A team's own data, in a portable format (section 3.6). Built
    | synchronously into a temporary ZIP and streamed back, which is fine at MVP
    | volumes and metered per member; a queued export writing to object storage
    | is the documented next step.
    |
    */

    'export' => [
        // Exports per member per hour. One is the normal case; three allows for
        // a failed download.
        'rate_limit' => (int) env('COMPLIANCE_EXPORT_RATE_LIMIT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosted privacy notice
    |--------------------------------------------------------------------------
    |
    | Linked from every outreach message (sections 3.3, 3.7). The particulars
    | below are the platform's own — Alima is the processor; each tenant
    | business is the controller for its own prospects — and must match the DPA.
    | Counsel signs off the wording before launch.
    |
    */

    'privacy_notice' => [
        // Who to write to about this notice, and the address an opt-out reaches.
        // Falls back to the outreach opt-out box, which is monitored by the
        // platform rather than by a tenant.
        'contact_email' => env('COMPLIANCE_PRIVACY_CONTACT', env('OUTREACH_OPT_OUT_ADDRESS', env('MAIL_FROM_ADDRESS'))),

        // Where the data lives, for the cross-border transfer position
        // (section 3.10).
        'hosting_location' => env('COMPLIANCE_HOSTING_LOCATION', 'Germany (EU)'),

        // Bumped whenever the wording changes, so a prospect can tell which
        // version they were shown.
        'version' => env('COMPLIANCE_PRIVACY_VERSION', '1.0'),
        'updated_on' => env('COMPLIANCE_PRIVACY_UPDATED_ON', '2026-10-30'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosted one-click unsubscribe
    |--------------------------------------------------------------------------
    |
    | The *endpoint* lives in this module; the *link* is part of the outreach
    | footer, so its switch and lifetime are configured beside the rest of that
    | footer in `config/outreach.php` under `unsubscribe`. Nothing to set here.
    |
    */

];
