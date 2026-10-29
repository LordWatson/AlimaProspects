<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Currency;

/*
|--------------------------------------------------------------------------
| AI Platform configuration
|--------------------------------------------------------------------------
|
| Settings for the AlimaProspects AI Platform module (the wrapper around the
| laravel/ai transport). Tiers map a capability to a concrete provider+model;
| pricing turns tokens into cost; credits bound per-team spend; caching and
| retries tune behaviour. See docs/modules/ai-platform.md.
|
*/

return [

    /*
    | Capability tiers → concrete provider + model. Prompts declare a tier, never a model id, so the
    | model can change here without touching any caller (ADR 0005).
    */
    'tiers' => [
        'reasoning' => [
            'provider' => env('AI_DEFAULT_PROVIDER', 'anthropic'),
            'model' => env('AI_MODEL_REASONING', 'claude-sonnet-5-5'),
        ],
        'cheap' => [
            'provider' => env('AI_DEFAULT_PROVIDER', 'anthropic'),
            'model' => env('AI_MODEL_CHEAP', 'claude-haiku-5-5'),
        ],
    ],

    /*
    | Generation defaults, used when a prompt template does not override them.
    */
    'defaults' => [
        'max_tokens' => (int) env('AI_MAX_TOKENS', 2048),
        'temperature' => (float) env('AI_TEMPERATURE', 0.2),
        'timeout' => (int) env('AI_TIMEOUT', 60),
    ],

    /*
    | Schema-validation retries. On an invalid/malformed response the request is retried this many
    | times before an InvalidAiResponseException is thrown (total attempts = schema + 1).
    */
    'retries' => [
        'schema' => (int) env('AI_SCHEMA_RETRIES', 1),
    ],

    /*
    | Response cache. Identical calls (same prompt key+version+model+normalised input) are served
    | from cache for `ttl` seconds, avoiding a duplicate provider call (and its cost).
    */
    'cache' => [
        'enabled' => (bool) env('AI_CACHE_ENABLED', true),
        'store' => env('AI_CACHE_STORE'), // null = default cache store
        'ttl' => (int) env('AI_CACHE_TTL', 3600),
    ],

    /*
    | Cost model. Prices are micro-units of `currency` per MILLION tokens, so cost stays an exact
    | integer in micro-units (1_000_000 micros = one major unit, e.g. $1). Indicative values — verify
    | against live provider pricing (mvp.md cost model, ADR 0005 risk).
    */
    'cost' => [
        'currency' => Currency::Usd->value,

        'models' => [
            // Claude Sonnet (reasoning tier): ~$3 / Mtok input, ~$15 / Mtok output.
            'claude-sonnet-5-5' => [
                'input_micros_per_mtok' => 3_000_000,
                'output_micros_per_mtok' => 15_000_000,
            ],
            // Claude Haiku (cheap tier): ~$1 / Mtok input, ~$5 / Mtok output.
            'claude-haiku-5-5' => [
                'input_micros_per_mtok' => 1_000_000,
                'output_micros_per_mtok' => 5_000_000,
            ],
        ],

        // Applied to any model not listed above (defaults to the reasoning-tier rate).
        'fallback' => [
            'input_micros_per_mtok' => 3_000_000,
            'output_micros_per_mtok' => 15_000_000,
        ],
    ],

    /*
    | Per-team monthly allowance (the margin guard). A "credit" is a unit of metered AI+data spend;
    | here 1 credit = `micros_per_credit` cost micro-units (default 1_000 = $0.001), so the default
    | 100_000 credits ≈ $100 of AI spend per month. Billing (Phase 16) replaces this flat stub with a
    | plan-aware allowance.
    */
    'credits' => [
        'monthly_default' => (int) env('AI_MONTHLY_CREDIT_DEFAULT', 100_000),
        'micros_per_credit' => (int) env('AI_MICROS_PER_CREDIT', 1_000),
    ],

];
