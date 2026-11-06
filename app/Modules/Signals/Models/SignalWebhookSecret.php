<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use Database\Factories\SignalWebhookSecretFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The shared secret one signal type's webhook is signed with (S13b, ADR 0023 §7).
 *
 * **Encrypted, not hashed**, and that is forced by the cryptography rather than chosen. A signature
 * is an HMAC over the raw body, so verifying one means *recomputing* it — which needs the secret
 * itself. A hash can check a secret somebody presents; it cannot check a signature they derived
 * from one. The alternatives were both worse: ask the caller to send the secret on every request
 * (throwing away the body integrity and replay binding the HMAC exists for) or not verify at all.
 *
 * So it lives under Laravel's `encrypted` cast. A database dump yields ciphertext and reading it
 * needs `APP_KEY` — which is the protection actually available for a shared secret the verifier
 * must be able to use, and it is worth being plain about that rather than implying a one-way hash.
 *
 * It is still shown in the clear **exactly once**, by the call that creates it: the UI never reads
 * it back, so a support ticket or a screenshot cannot leak it later. A customer who loses it
 * rotates it.
 *
 * **One live secret per type.** Rotation replaces the row rather than keeping a history: two valid
 * secrets at once is a grace period nobody asked for and a second thing to find when one leaks. One
 * secret per *type* rather than per team, so revoking a leaked credential costs one integration.
 *
 * @property int $id
 * @property int $team_id
 * @property int $signal_definition_id
 * @property string $secret
 * @property string $hint
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalWebhookSecret extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalWebhookSecretFactory> */
    use HasFactory;

    /** Bytes of entropy in a generated secret. 32 bytes is 64 hex characters. */
    public const int SECRET_BYTES = 32;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'signal_definition_id',
        'secret',
        'hint',
        'last_used_at',
    ];

    /**
     * A fresh secret, and the row to store it in.
     *
     * @return array<string, string>
     */
    public static function mint(): array
    {
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        return [
            'secret' => $secret,
            // The **last** characters, not the first. A leading prefix is the part a format tends to
            // make predictable; the tail is what differs between two secrets a customer is trying to
            // tell apart, which is the only thing a hint is for.
            'hint' => Str::substr($secret, -8),
        ];
    }

    /**
     * The signature this secret would produce for a timestamp and a raw body.
     *
     * Here rather than in the request, so the one definition of "what a valid signature is" is
     * beside the secret it is made with — and so a test and the documentation can produce one the
     * same way a customer's own code will.
     *
     * The signed message is `timestamp.body`: the timestamp is **inside** the signature's scope, so
     * it cannot be edited to refresh a captured request, and the body is the raw bytes, because
     * `json_encode(json_decode($body))` is not `$body` and signing a re-encoded payload would let
     * anybody who can reorder keys keep a valid signature over different content.
     */
    public function signatureFor(string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
    }

    /**
     * Whether a presented signature is the one this secret would have produced.
     *
     * `hash_equals` and never `===`: a comparison that returns early leaks, through timing, how much
     * of a guess was right, which is enough to recover a signature a character at a time.
     */
    public function verify(string $timestamp, string $rawBody, string $presented): bool
    {
        return hash_equals($this->signatureFor($timestamp, $rawBody), $presented);
    }

    /**
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SignalDefinition::class, 'signal_definition_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalWebhookSecretFactory
    {
        return SignalWebhookSecretFactory::new();
    }
}
