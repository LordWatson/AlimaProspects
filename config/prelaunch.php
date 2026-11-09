<?php

declare(strict_types=1);

use App\Modules\Core\Enums\PrelaunchFeature;

return [

    /*
    |--------------------------------------------------------------------------
    | Pre-launch feature flags
    |--------------------------------------------------------------------------
    |
    | The default answer for each flag in `PrelaunchFeature`, resolved per **team** through
    | Pennant. These exist so the pre-launch work (`pre-launch.md`) can be merged and deployed
    | before it is finished, and switched on for one team at a time.
    |
    | A flag here answers *is this code finished enough to be seen*. It is **not** a plan
    | entitlement — that is `Core\Enums\Capability` behind `CapabilityGate` (ADR 0015), and
    | conflating the two means a customer's plan is decided by whether a deploy went out
    | (ADR 0026 §4).
    |
    | Pennant stores a per-team override in `features`, so turning one on for a pilot is a database
    | write rather than a deploy. **The flag is deleted once its feature is live** — a flag left
    | behind after launch is a second code path nobody tests.
    |
    */

    'flags' => [
        PrelaunchFeature::WhyNow->value => (bool) env('PRELAUNCH_WHY_NOW', false),
        PrelaunchFeature::Enrichment->value => (bool) env('PRELAUNCH_ENRICHMENT', false),
        PrelaunchFeature::Export->value => (bool) env('PRELAUNCH_EXPORT', false),
        PrelaunchFeature::Webhooks->value => (bool) env('PRELAUNCH_WEBHOOKS', false),
    ],

];
