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
    | relied on to forward one. A one-click unsubscribe link is post-MVP; until
    | then the footer says to reply, which PECR accepts.
    |
    */

    'opt_out' => [
        'address' => env('OUTREACH_OPT_OUT_ADDRESS', env('MAIL_FROM_ADDRESS')),
    ],

    'privacy_notice_url' => env('OUTREACH_PRIVACY_NOTICE_URL'),

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
