<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Services\WebhookSignalSource;
use Database\Factories\SignalInboundEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event a customer pushed to `POST /api/v1/signal-events` (S13b, ADR 0023 §7).
 *
 * **Stored, not processed.** The endpoint's whole job is to accept this row safely; the next run of
 * a watch reads it through {@see WebhookSignalSource} like any other source, and from that point it
 * is classified by the same prompt, thresholded against the same floors, clustered, scored and
 * matched by exactly the code that handles a news article. That is what ADR 0023 §7 means by "the
 * customer supplies an observation; Alima still decides what it means" — and it is why accepting an
 * event is cheap and synchronous while the expensive, subtle half is code that already existed.
 *
 * **Nothing on this row is believed about meaning.** The payload cannot set a score, a jurisdiction,
 * a market, a lead or a team — see the ADR for why each one matters, and the request's own rules for
 * where each is refused. `occurred_at` is believed only as a date, and only inside a sanity window.
 *
 * **There is no `processed_at`, deliberately.** It would be a second source of truth about work the
 * product already tracks: a classified event becomes a `Signal` whose `(team_id, dedupe_hash)` is
 * unique, so a re-read creates nothing; one the classifier rejected is remembered by
 * `SignalRejectionLedger`. The source reads a **window** of recent events instead, which is what
 * keeps an event nobody ever classified from being re-offered for ever, and `signals:prune` sweeps
 * the rest. A flag we had to keep in step with two other mechanisms would be the thing that drifts.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $signal_definition_id
 * @property string $idempotency_key
 * @property string|null $dedupe_hash
 * @property array<array-key, mixed> $payload
 * @property string $title
 * @property string|null $link
 * @property string|null $summary
 * @property string|null $source_name
 * @property Carbon|null $occurred_at
 * @property Carbon $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalInboundEvent extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalInboundEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'signal_definition_id',
        'idempotency_key',
        'dedupe_hash',
        'payload',
        'title',
        'link',
        'summary',
        'source_name',
        'occurred_at',
        'received_at',
    ];

    /**
     * Stamp the derived identity on the way in, whoever is writing the row.
     *
     * The same shape as `BelongsToTeam`'s auto-assign and for the same reason: the value is derived
     * from two columns already on the row, so a writer that forgot it would produce a row the
     * exclusion query cannot match — and a null compares equal to nothing, so the failure would be
     * silent and in the permissive direction. The Action sets it explicitly as well, which is the
     * belt beside these braces; a factory and a seeder only get it from here.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $event): void {
            // No guard on `idempotency_key`: the column is NOT NULL and the request requires it, so
            // a row without one does not reach an insert.
            if ($event->dedupe_hash === null) {
                $event->dedupe_hash = self::dedupeIdentityFor((int) $event->team_id, $event->idempotency_key);
            }
        });
    }

    /**
     * The identity this event will have as a `Signal` — one definition, two readers.
     *
     * **The idempotency key, not the link.** A customer's events may share a URL or carry none at
     * all, and the key is the one thing they promised would be unique. Prefixed and salted with the
     * team so it cannot collide with a news article's content hash or with another tenant's event.
     *
     * Stored on the row as well as computed, because the exclusion in
     * {@see WebhookSignalSource::fetch()} is a `whereNotExists` against `signals.dedupe_hash` and
     * `sha1()` is not a function SQL agrees about across SQLite and MySQL (S13b review, finding 8).
     */
    public static function dedupeIdentityFor(int $teamId, string $idempotencyKey): string
    {
        return sha1('webhook:'.$teamId.':'.$idempotencyKey);
    }

    /** This row's signal identity, computed where an older row has none stored. */
    public function dedupeIdentity(): string
    {
        return $this->dedupe_hash
            ?? self::dedupeIdentityFor((int) $this->team_id, $this->idempotency_key);
    }

    /**
     * The signal type this event claims to be.
     *
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SignalDefinition::class, 'signal_definition_id');
    }

    /**
     * Events received within the source's window, newest first.
     *
     * The window is what makes "no `processed_at`" safe: an event that never became a signal — too
     * low-confidence, or arriving while a watch was paused — stops being offered rather than being
     * re-classified every run for ever. It has to be **shorter than** the rejection ledger's memory
     * (30 days), or an event the classifier refused would come back the day that memory expired.
     *
     * @param  Builder<SignalInboundEvent>  $query
     * @return Builder<SignalInboundEvent>
     */
    public function scopeRecent(Builder $query, int $days): Builder
    {
        return $query
            ->where('received_at', '>=', now()->subDays(max(1, $days)))
            ->orderByDesc('received_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalInboundEventFactory
    {
        return SignalInboundEventFactory::new();
    }
}
