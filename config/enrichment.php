<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Enrichment & Data Quality configuration
|--------------------------------------------------------------------------
|
| Settings for the enrichment pipeline (Phase 8): the free-tier email check
| and how many contacts a single lead may keep. No paid enrichment provider
| is used in the MVP (ADR 0008) — facts come from AI extraction of public web
| text, and emails are checked by syntax + disposable-domain + MX/DNS only.
| See docs/modules/enrichment-data-quality.md.
|
*/

return [

    // Cap the number of contacts persisted per lead, so a runaway extraction can't flood a lead.
    'max_contacts_per_lead' => (int) env('ENRICHMENT_MAX_CONTACTS', 10),

    // Local-parts that mark a role/business mailbox rather than a personal identity. A role address
    // is preferred for outreach; a non-role one is flagged as personal for compliance.
    'role_local_parts' => [
        'info', 'sales', 'contact', 'contactus', 'hello', 'hi', 'team', 'office', 'admin',
        'support', 'help', 'helpdesk', 'enquiries', 'enquiry', 'inquiries', 'inquiry', 'mail',
        'marketing', 'press', 'media', 'pr', 'careers', 'jobs', 'recruitment', 'hr', 'billing',
        'accounts', 'finance', 'orders', 'bookings', 'reservations', 'noreply', 'no-reply', 'donotreply',
    ],

    // Known throwaway / disposable-mail domains. Addresses on these get the lowest confidence and are
    // never treated as a real business contact. Kept deliberately small; extend as needed.
    'disposable_domains' => [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'throwaway.email', 'yopmail.com', 'getnada.com',
        'trashmail.com', 'sharklasers.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com',
        'mailnesia.com', 'mohmal.com', 'spam4.me', 'tmpmail.org', 'moakt.com', 'mintemail.com',
    ],

    // Shared hosts that are NOT a single company's own site: free-mail providers plus social /
    // directory / link-aggregator domains. De-duplication never treats a website domain or a contact
    // email on one of these as an identity signal, so unrelated companies that merely share a
    // social page or a gmail address are not wrongly merged.
    'blocked_dedupe_domains' => [
        // Free / consumer mail.
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com',
        'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com',
        'zoho.com', 'yandex.com', 'qq.com',
        // Social / directory / aggregator / site builders.
        'facebook.com', 'linkedin.com', 'twitter.com', 'x.com', 'instagram.com', 'youtube.com',
        'tiktok.com', 'pinterest.com', 'yelp.com', 'trustpilot.com', 'crunchbase.com',
        'glassdoor.com', 'indeed.com', 'wikipedia.org', 'amazon.com', 'google.com', 'bing.com',
        'yell.com', 'yellowpages.com', 'maps.google.com', 'linktr.ee', 'wixsite.com', 'wix.com',
        'squarespace.com', 'godaddysites.com', 'wordpress.com', 'blogspot.com', 'weebly.com',
    ],

];
