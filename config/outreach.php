<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | Whether Alima may transmit email at all. Off leaves drafting, copying and
    | logging working — the member sends from their own mailbox instead — which
    | is the right behaviour for a deployment with no verified sending domain.
    |
    | The envelope sender is **ours**: email goes out over the platform's
    | verified Resend domain, named for the team ("Acme Ltd via Alima"), with
    | the member as Reply-To. The team's own domain is not used, because
    | sending as a domain we don't hold SPF/DKIM for is how a tenant's mail
    | ends up in spam folders — per-tenant sending domains are post-MVP (see
    | docs/modules/outreach.md).
    |
    */

    'sending_enabled' => (bool) env('OUTREACH_SENDING_ENABLED', true),

    'from' => [
        // Falls back to the app's own from-address, which is already the
        // Resend-verified one.
        'address' => env('OUTREACH_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
        // Appended to the team's name in the From header, so a recipient can
        // always see which platform transmitted it (UAE TDRA sender
        // identification, docs/compliance.md §3.3).
        'via' => env('OUTREACH_FROM_VIA', 'via Alima'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Opt-out and identification
    |--------------------------------------------------------------------------
    |
    | Every sent message carries both (docs/compliance.md §3.3). The opt-out
    | address is monitored by the platform, not the tenant: an opt-out has to
    | reach the **global** suppression list, and a tenant mailbox cannot be
    | relied on to forward one.
    |
    */

    'opt_out' => [
        'address' => env('OUTREACH_OPT_OUT_ADDRESS', env('MAIL_FROM_ADDRESS')),
    ],

    /*
    | The privacy notice linked from every footer. Left unset, the footer links
    | the platform's own hosted notice (Compliance, Phase 15) — so a deployment
    | has a working notice without configuring one. Set this only to point at a
    | notice hosted elsewhere.
    */
    'privacy_notice_url' => env('OUTREACH_PRIVACY_NOTICE_URL'),

    /*
    |--------------------------------------------------------------------------
    | One-click unsubscribe
    |--------------------------------------------------------------------------
    |
    | RFC 8058 one-click: an HTTPS link in the footer and in the
    | `List-Unsubscribe` header, with `List-Unsubscribe-Post` beside it, handled
    | by the Compliance module's endpoint (Phase 15). A recipient who uses their
    | mail client's own unsubscribe button instead of reaching for "report spam"
    | protects the deliverability of every team on the platform.
    |
    | Off leaves the Phase 11 behaviour: a `mailto:` opt-out and no
    | `List-Unsubscribe-Post`, which is what PECR accepts and what a deployment
    | without the route published should send. Advertising one-click against a
    | `mailto:`-only header is worse than not advertising it at all.
    |
    | The link is a **signed URL carrying the message id** and nothing else, so
    | it cannot be guessed, reversed into an address or enumerated.
    |
    */

    'unsubscribe' => [
        'enabled' => (bool) env('OUTREACH_UNSUBSCRIBE_ENABLED', true),

        // How long a link stays good. Long, because somebody may unsubscribe
        // from a year-old email in their archive, and refusing them then is the
        // worst possible moment to refuse them.
        'link_days' => (int) env('OUTREACH_UNSUBSCRIBE_LINK_DAYS', 400),

        // Requests a minute from one IP. The signature already rules out
        // enumeration; this is only so an unauthenticated endpoint that writes a
        // suppression and an audit entry is not unbounded. Generous on purpose —
        // a recipient who clicks twice must never be turned away from an
        // opt-out.
        'rate_limit' => (int) env('OUTREACH_UNSUBSCRIBE_RATE_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Drafting spends AI credit and sending spends reputation, so both are
    | capped per member. Deliberately generous for a person working a list and
    | tight enough that a loop over a token is noticed.
    |
    */

    'draft_rate_limit' => (int) env('OUTREACH_DRAFT_RATE_LIMIT', 30),

    'send_rate_limit' => (int) env('OUTREACH_SEND_RATE_LIMIT', 60),

];
