<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Integrations\Contracts\CrmConnector;
use App\Modules\Integrations\Enums\CrmProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * The `state` that makes an OAuth callback ours (PL7).
 *
 * What it is defending against: a callback is a **GET to our application with somebody else's
 * code in it**. Without `state`, an attacker who gets a member to open a crafted callback URL
 * connects *their* CRM account to the member's team, and from then on every lead that team finds is
 * pushed into the attacker's Pipedrive. That is CSRF with a data exfiltration payload, and it is why
 * the parameter exists at all.
 *
 * **Opaque random in a cache entry, not a signed payload**, although the plan said signed. A signed
 * value proves we issued it and nothing more: it can be replayed until it expires, and it cannot be
 * revoked. An entry that is *deleted as it is read* is single-use by construction, and ten minutes
 * is a ceiling rather than the whole of the protection. The cache is already the store this
 * application trusts for exactly this shape of thing (the feed poll floors, the breakers), and the
 * trade — a dropped cache means a member clicks Connect again — is the right way round.
 *
 * It is bound to the **team, the user and the provider**, and all three are checked on consumption.
 * The provider matters as much as the other two: a state issued for Pipedrive that completed a
 * HubSpot callback would store HubSpot tokens on a Pipedrive row, and every later call would be made
 * against the wrong API with a credential that works.
 *
 * **PKCE is not here.** It is in the plan, and it belongs with a connector: the challenge method a
 * provider accepts, and whether it accepts one at all, is provider knowledge, and PL7 ships no
 * provider. The hook is {@see CrmConnector::authorizeUrl()},
 * which composes the whole URL and can add its own `code_challenge` without this class changing.
 */
final class OAuthStateService
{
    /** Bytes of entropy. 32 is 64 hex characters — far past guessable in ten minutes. */
    private const int STATE_BYTES = 32;

    public function __construct(private readonly Repository $cache) {}

    /**
     * Mint a state for this member, this team and this provider.
     */
    public function issue(Team $team, User $user, CrmProvider $provider): string
    {
        $state = bin2hex(random_bytes(self::STATE_BYTES));

        $this->cache->put($this->key($state), [
            'team_id' => (int) $team->getKey(),
            'user_id' => (int) $user->getKey(),
            'provider' => $provider->value,
        ], $this->ttl());

        return $state;
    }

    /**
     * Spend a state, or refuse.
     *
     * Deleted **before** the checks rather than after them, so a wrong answer still burns the
     * entry: an attacker who can replay a captured state against a different team must not be able
     * to keep trying with it. Absent, expired, already used and somebody else's all return null —
     * the caller renders one message for all four, because a callback that says which guess was
     * closer is a callback that helps.
     */
    public function consume(string $state, Team $team, User $user, CrmProvider $provider): bool
    {
        $key = $this->key($state);

        /** @var array{team_id: int, user_id: int, provider: string}|null $stored */
        $stored = $this->cache->pull($key);

        if ($stored === null) {
            return false;
        }

        return (int) $stored['team_id'] === (int) $team->getKey()
            && (int) $stored['user_id'] === (int) $user->getKey()
            && $stored['provider'] === $provider->value;
    }

    /**
     * Namespaced by the module and never by the team: the state *is* the secret, and putting a team
     * id in the key would let one team's cache prefix be guessed from another's.
     */
    private function key(string $state): string
    {
        return 'integrations:oauth-state:'.$state;
    }

    private function ttl(): int
    {
        return max(60, (int) config('integrations.oauth.state_ttl', 600));
    }
}
