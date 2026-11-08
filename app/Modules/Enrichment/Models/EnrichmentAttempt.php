<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Enrichment\Enums\AttemptOutcome;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\LeadSourcing\Models\Lead;
use Database\Factories\EnrichmentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One call to one vendor (PL3). **The money table.**
 *
 * Append-only: there is no `updated_at`, nothing updates a row, and every paid call lands here with
 * its cost whether it found anything or not. That last part is the point — the questions that
 * matter commercially are all about the misses. Which vendor is being paid to say "no"; what a hit
 * really costs once the failures are counted; whether a team's reveals are worth their plan.
 *
 * `status_returned` is the vendor's own word, kept short. It exists for the one failure mode that
 * is otherwise invisible: our mapping being wrong. A status we have never seen appearing a thousand
 * times is how that gets noticed.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $enrichment_run_id
 * @property int $lead_id
 * @property int|null $contact_id null for a people-find, which has no contact yet
 * @property ProviderCapability $capability
 * @property string $provider the provider's own key
 * @property AttemptOutcome $outcome
 * @property string|null $status_returned the vendor's own word, for spotting a bad mapping
 * @property int|null $latency_ms
 * @property int $cost_micros
 * @property string|null $error_code ours, not the vendor's
 * @property Carbon|null $created_at
 */
class EnrichmentAttempt extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<EnrichmentAttemptFactory> */
    use HasFactory;

    /** Append-only: a row is written once and never touched again. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'enrichment_run_id',
        'lead_id',
        'contact_id',
        'capability',
        'provider',
        'outcome',
        'status_returned',
        'latency_ms',
        'cost_micros',
        'error_code',
    ];

    /**
     * @return BelongsTo<EnrichmentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(EnrichmentRun::class, 'enrichment_run_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<LeadContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(LeadContact::class, 'contact_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'capability' => ProviderCapability::class,
            'outcome' => AttemptOutcome::class,
            'latency_ms' => 'integer',
            'cost_micros' => 'integer',
        ];
    }

    protected static function newFactory(): EnrichmentAttemptFactory
    {
        return EnrichmentAttemptFactory::new();
    }
}
