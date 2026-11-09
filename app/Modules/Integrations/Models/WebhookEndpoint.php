<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Signals\Models\SignalWebhookSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One URL a team wants Alima's events posted to, and the secret they are signed with (PL7).
 *
 * **The secret is encrypted, not hashed**, and that is forced by the cryptography rather than
 * chosen — the same argument {@see SignalWebhookSecret} makes for the
 * inbound direction, and the reason the two are deliberately the same shape. A signature is an HMAC
 * over the body, so producing one needs the secret itself; a hash could check a secret somebody
 * presented but cannot produce a signature. So it lives under Laravel's `encrypted` cast: a
 * database dump yields ciphertext and reading it needs `APP_KEY`.
 *
 * It is shown in the clear **exactly once**, by the call that mints it. The screen never reads it
 * back, so a screenshot or a support ticket cannot leak it later; a customer who loses it rotates
 * it, which replaces the row's secret rather than keeping a second valid one — two live secrets is
 * a grace period nobody asked for and a second thing to find when one leaks.
 *
 * **`failure_count` counts consecutive failures and is reset by a success.** That is what makes the
 * auto-disable threshold mean "this endpoint is gone" rather than "this endpoint has had a bad day
 * since March".
 *
 * @property int $id
 * @property int $team_id
 * @property string $url
 * @property string $secret
 * @property string $hint
 * @property list<string> $events
 * @property bool $is_active
 * @property int $failure_count
 * @property Carbon|null $disabled_at
 * @property Carbon|null $last_delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookEndpoint extends Model
{
    use BelongsToTeam;

    /** Bytes of entropy in a generated secret. 32 bytes is 64 hex characters. */
    public const int SECRET_BYTES = 32;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'url',
        'events',
        'is_active',
    ];

    /**
     * A fresh secret and the row's two columns for it.
     *
     * @return array{secret: string, hint: string}
     */
    public static function mint(): array
    {
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        return [
            'secret' => $secret,
            // The **last** characters, not the first: a leading prefix is the part a format makes
            // predictable, and the tail is what differs between two secrets somebody is trying to
            // tell apart, which is all a hint is for.
            'hint' => Str::substr($secret, -8),
        ];
    }

    /**
     * The signature this endpoint's secret produces for a timestamp and a raw body.
     *
     * The signed message is `timestamp.body`. The timestamp is **inside** the signature's scope, so
     * a captured request cannot have its timestamp edited to refresh it, and the body is the exact
     * bytes sent, because `json_encode(json_decode($body))` is not `$body` — signing a re-encoded
     * payload would let anybody who can reorder keys keep a valid signature over different content.
     *
     * Here rather than in the sender, so the one definition of a valid signature sits beside the
     * secret it is made with and a test, the docs and a customer's own code all derive it the same
     * way.
     */
    public function signatureFor(string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
    }

    /**
     * The whole `X-Alima-Signature` header value.
     *
     * Versioned (`v1=`) because a signing scheme that cannot be changed is a signing scheme that
     * never is: a `v2` can be sent alongside `v1` for a release, and a verifier written against the
     * documented format keeps working.
     */
    public function signatureHeader(string $timestamp, string $rawBody): string
    {
        return 't='.$timestamp.',v1='.$this->signatureFor($timestamp, $rawBody);
    }

    /**
     * Whether a presented signature is the one this secret would have produced.
     *
     * `hash_equals` and never `===`: a comparison that returns early leaks, through timing, how
     * much of a guess was right. Used by the tests and by anybody verifying our own output.
     */
    public function verify(string $timestamp, string $rawBody, string $presented): bool
    {
        return hash_equals($this->signatureFor($timestamp, $rawBody), $presented);
    }

    public function subscribesTo(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events, strict: true);
    }

    /**
     * Whether Alima should be sending to this endpoint at all.
     */
    public function isDeliverable(): bool
    {
        return $this->is_active && $this->disabled_at === null;
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // See the class note: a verifier must be able to recompute an HMAC.
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'failure_count' => 'integer',
            'disabled_at' => 'datetime',
            'last_delivered_at' => 'datetime',
        ];
    }
}
