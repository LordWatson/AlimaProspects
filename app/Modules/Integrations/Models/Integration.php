<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One team's connection to one CRM (PL7).
 *
 * Both tokens are under the `encrypted` cast, and `$hidden` as well — a model serialised into an
 * Inertia prop or a log context must not carry them, and relying on every caller to remember that
 * is how a token reaches a browser once.
 *
 * **Disconnecting does not delete the row.** It sets the status and clears the tokens, which keeps
 * `integration_links` intact: a team that reconnects the same CRM account the next morning should
 * update the organisations they already have rather than create every one of them again. The links
 * are only wrong if the *account* changed, which is why `external_account_id` is checked on
 * reconnect rather than merely displayed.
 *
 * @property int $id
 * @property int $team_id
 * @property CrmProvider $provider
 * @property IntegrationStatus $status
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property string|null $external_account_id
 * @property string|null $external_account_name
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Integration extends Model
{
    use BelongsToTeam;

    /**
     * Refresh this far before the stated expiry.
     *
     * A token that expires in forty seconds is a token that expires mid-request. Sixty seconds of
     * slack costs one extra refresh and removes a whole class of flake.
     */
    public const int REFRESH_SKEW_SECONDS = 60;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'provider',
        'status',
        'settings',
    ];

    /**
     * Never serialised, whatever a caller forgets.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Whether the access token is past its stated life (or close enough to it).
     *
     * A null `expires_at` reads as **not expiring**: some providers issue tokens with no stated
     * life, and treating "we were not told" as "it has expired" would refresh on every single call.
     */
    public function tokenIsStale(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->subSeconds(self::REFRESH_SKEW_SECONDS)->isPast();
    }

    /**
     * The auto-sync rule, with the defaults a team gets before they have set one.
     *
     * **Off by default**, and that is the setting that matters most on this screen: switching it on
     * means Alima starts writing into somebody's CRM without a person present, and nobody should
     * discover that as a default. The two thresholds are read with `null` meaning "do not care",
     * because a team that wants everything pushed should not have to type a zero.
     *
     * @return array{enabled: bool, min_confidence: int|null, min_fit: int|null}
     */
    public function autoSync(): array
    {
        $rule = $this->settings['auto_sync'] ?? [];
        $rule = is_array($rule) ? $rule : [];

        return [
            'enabled' => (bool) ($rule['enabled'] ?? false),
            'min_confidence' => isset($rule['min_confidence']) ? (int) $rule['min_confidence'] : null,
            'min_fit' => isset($rule['min_fit']) ? (int) $rule['min_fit'] : null,
        ];
    }

    /**
     * @return HasMany<IntegrationLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(IntegrationLink::class);
    }

    /**
     * @return HasMany<IntegrationSyncLog, $this>
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(IntegrationSyncLog::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => CrmProvider::class,
            'status' => IntegrationStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'settings' => 'array',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
