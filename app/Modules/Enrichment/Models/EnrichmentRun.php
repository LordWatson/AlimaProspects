<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Enrichment\Enums\EnrichmentRunState;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\LeadSourcing\Models\Lead;
use Database\Factories\EnrichmentRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One thing a member asked for: "find decision-makers at this company", "check these addresses"
 * (PL3). A run makes several {@see EnrichmentAttempt}s — one per vendor tried.
 *
 * `summary` is a **record over the attempts**, not a second source of truth. If the two ever
 * disagree the attempts are right: they are append-only and written as each call returns, while
 * the summary is composed once at the end for a screen to read without re-aggregating
 * (ADR 0012's rule for a derived figure).
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property int|null $requested_by null for a run the system started rather than a member
 * @property EnrichmentScope $scope
 * @property EnrichmentRunState $state
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $summary
 * @property string|null $error_code why it refused or failed — by code, never by message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EnrichmentRun extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<EnrichmentRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'requested_by',
        'scope',
        'state',
        'started_at',
        'finished_at',
        'summary',
        'error_code',
    ];

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<EnrichmentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(EnrichmentAttempt::class)->orderBy('id');
    }

    /**
     * What this run actually cost, read from the attempts rather than from `summary`.
     *
     * The figure PL4 bills against, which is why it is the attempts and not the snapshot: a run
     * whose worker died between its last call and its summary still owes for the calls it made.
     */
    public function spentMicros(): int
    {
        return (int) $this->attempts()->sum('cost_micros');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'scope' => EnrichmentScope::class,
            'state' => EnrichmentRunState::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    protected static function newFactory(): EnrichmentRunFactory
    {
        return EnrichmentRunFactory::new();
    }
}
