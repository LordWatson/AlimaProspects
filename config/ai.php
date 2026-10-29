<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| laravel/ai transport configuration (ADR 0005)
|--------------------------------------------------------------------------
|
| This is the configuration for the underlying AI SDK (laravel/ai). Our own
| AI Platform settings — tiers, pricing, credits, caching — live in
| config/ai-platform.php. Product code never reads this file directly; it
| goes through App\Modules\AiPlatform\Contracts\AiClient.
|
| The default provider is Anthropic Claude. Additional providers can be added
| here if the transport is ever pointed at a different backend.
|
*/

return [

    'default' => env('AI_DEFAULT_PROVIDER', 'anthropic'),

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],
    ],

];
