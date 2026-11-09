<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One CRM connection, as a screen sees it (PL7).
 *
 * No tokens, and the model hides them as well — two layers, because a token reaching a browser is
 * the one mistake in this module that cannot be taken back.
 *
 * `last_error` is sent, and it holds an exception class name rather than a provider's error body
 * for exactly that reason: a 401 body has been known to echo the token back.
 *
 * @mixin Integration
 */
final class IntegrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'account_name' => $this->external_account_name,
            'auto_sync' => $this->autoSync(),
            'create_deals' => (bool) ($this->settings['create_deals'] ?? false),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
