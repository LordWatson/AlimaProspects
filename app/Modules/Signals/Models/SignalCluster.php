<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Services\SignalClusterer;
use Database\Factories\SignalClusterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One real-world event, however many outlets reported it (S11).
 *
 * A Dubai acquisition covered by Gulf News, Khaleej Times, Zawya, Arabian Business and three
 * aggregators was five to eight {@see Signal} rows, each costing an AI classification and each
 * needing its own dismiss. This is the row that makes them one card: the members stay, because each
 * one is a real article with a real link worth reading, and the cluster is what the feed lists and
 * counts.
 *
 * Three things about it are deliberate:
 *
 * - **The counters are a record, not a tally.** `sources_count`, `first_published_at` and
 *   `last_published_at` are recomputed from the member rows by {@see SignalClusterer::reconcile()}
 *   whenever the membership changes, the same shape `usage_meters` has over the ledgers (ADR 0012)
 *   and for the same reason: a counter the app increments and the rows it counts disagree
 *   eventually, and the rows are the truth.
 * - **The primary is the cluster's representative for status as well as display.** A member is
 *   never actioned on its own — dismissing a cluster dismisses its members — so the primary is what
 *   carries `lead_id` on conversion, and {@see SignalClusterer::reconcile()} will not move it once
 *   it has been actioned. Re-pointing a converted cluster at a different article would strand the
 *   provenance of a real lead.
 * - **`subject_fingerprint` is a company name and never a person's.** A cluster is not swept by
 *   retention — its signals are — so a person's name on it would outlive the rows it came from.
 *
 * Tenant-scoped via {@see BelongsToTeam}. Nothing routes to it: like {@see SignalRun}, it is a
 * record the module keeps about its own work, reached through the signals that belong to it, so it
 * carries no policy of its own and every query that reaches it is scoped twice.
 *
 * @property int $id
 * @property int $team_id
 * @property string $cluster_key
 * @property int|null $signal_definition_id
 * @property string $event_key
 * @property int|null $primary_signal_id
 * @property int $sources_count
 * @property Carbon|null $first_published_at
 * @property Carbon|null $last_published_at
 * @property string|null $subject_fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalCluster extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalClusterFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'cluster_key',
        'signal_definition_id',
        'event_key',
        'primary_signal_id',
        'sources_count',
        'first_published_at',
        'last_published_at',
        'subject_fingerprint',
    ];

    /**
     * Every article that reported this event, the primary included.
     *
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'signal_cluster_id');
    }

    /**
     * The article this event is shown as: the highest-confidence one, earliest publication breaking
     * ties, because the first report of an event is usually the best one.
     *
     * Nullable, and `nullOnDelete` at the database level: retention can take the primary without
     * taking the cluster, and the next reconcile picks a replacement.
     *
     * @return BelongsTo<Signal, $this>
     */
    public function primarySignal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'primary_signal_id');
    }

    /**
     * The type this event is, when the definition still exists.
     *
     * `withTrashed` for the same reason {@see Signal::definition()} needs it: a type a team deleted
     * must still name the events it found (ADR 0018 §4).
     *
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SignalDefinition::class, 'signal_definition_id')->withTrashed();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'sources_count' => 'integer',
            'first_published_at' => 'datetime',
            'last_published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalClusterFactory
    {
        return SignalClusterFactory::new();
    }
}
