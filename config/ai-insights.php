<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI Insights configuration (Phase 14)
|--------------------------------------------------------------------------
|
| Stored, dismissable insights: the stale-lead alert, the "contact today"
| list, and the model's read of the week. See docs/modules/ai-insights.md.
|
| The knobs here are mostly about **noise and cost**, which are the two ways
| this feature fails. A feed nobody trusts is one that cried wolf, and an AI
| call that runs daily per team is the only thing in the platform that spends
| money on a timer — so both the thresholds and the AI switch live here
| rather than in code.
|
*/

return [

    // The hour (0–23) in the **team's own timezone** at which the day's insights are generated.
    // `insights:generate` runs hourly and fires per team when its local clock reaches this, so a
    // UK team and a Dubai team both get theirs before the working day (see the command).
    'generate_at_hour' => (int) env('INSIGHTS_GENERATE_AT_HOUR', 7),

    'stale' => [
        // How long a lead the team has *started* may sit untouched before it is going cold.
        // A fortnight: shorter and a member on holiday comes back to an alert about every lead
        // they own; longer and the alert arrives after the moment has passed.
        'after_days' => (int) env('INSIGHTS_STALE_AFTER_DAYS', 14),

        // How many companies the alert names. The count is always the real total — this bounds
        // the card, not the figure.
        'max_items' => (int) env('INSIGHTS_STALE_MAX_ITEMS', 5),
    ],

    'contact_today' => [
        // How long today's call list is. Five is a morning's work; twenty is a list nobody starts.
        'max_items' => (int) env('INSIGHTS_CONTACT_TODAY_MAX_ITEMS', 5),
    ],

    'ai' => [
        // The one AI call this module makes. Off means the two deterministic insights still run
        // daily and the feed simply has no commentary — which is also what happens on a provider
        // outage or an exhausted allowance (see `InsightGenerator`). A deployment watching its
        // margin can switch this off without losing the feature.
        'enabled' => (bool) env('INSIGHTS_AI_ENABLED', true),

        // How many of the model's points are kept. It is asked for at most three; this is the
        // ceiling applied to what comes back, so a loosened schema in a later prompt version
        // cannot turn one run into a wall of cards.
        'max_items' => (int) env('INSIGHTS_AI_MAX_ITEMS', 3),
    ],

    // Retention. Insights are derived data — every row can be rebuilt tomorrow from the leads and
    // the Reporting read-model — and they carry company names, so there is no reason to hold them
    // past their usefulness (docs/compliance.md §5a).
    'retention' => [
        'dismissed_days' => (int) env('INSIGHTS_RETENTION_DISMISSED_DAYS', 30),
        'unactioned_days' => (int) env('INSIGHTS_RETENTION_UNACTIONED_DAYS', 60),
    ],

];
