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

    // **How ready a lead is to be contacted (Q4).** A second figure beside the fit score,
    // answering the other question: the score says whether we should want them and is
    // deliberately blind to missing data, which is why an A-grade lead can have no website, no
    // contact and no routable email. Readiness is a measure of what we hold, so it is the one
    // figure allowed to punish a gap.
    //
    // Weights are relative, not percentages: `LeadReadiness` normalises by the total of the ones
    // in play, so adding a key cannot inflate a lead and setting one to **0** switches that
    // factor off entirely. The shape of the defaults is the deliberate part — the three contact
    // factors together outweigh the four company ones, because a complete company record you
    // cannot write to is not a lead you can work.
    'readiness_weights' => [
        'website' => (int) env('ENRICHMENT_READINESS_WEIGHT_WEBSITE', 10),
        'industry' => (int) env('ENRICHMENT_READINESS_WEIGHT_INDUSTRY', 10),
        'company_size' => (int) env('ENRICHMENT_READINESS_WEIGHT_COMPANY_SIZE', 5),
        'location' => (int) env('ENRICHMENT_READINESS_WEIGHT_LOCATION', 10),
        'contact' => (int) env('ENRICHMENT_READINESS_WEIGHT_CONTACT', 25),
        // Routable, never proven deliverable — `EmailVerificationStatus::confidence()` tops out
        // at 60 for the same reason, and there is no SMTP probe in the MVP (ADR 0008).
        'routable_email' => (int) env('ENRICHMENT_READINESS_WEIGHT_ROUTABLE_EMAIL', 25),
        // `info@` is a letterbox; a named mailbox is somebody who can answer.
        'named_contact' => (int) env('ENRICHMENT_READINESS_WEIGHT_NAMED_CONTACT', 15),
    ],

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
