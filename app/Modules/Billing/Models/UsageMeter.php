<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;
use Database\Factories\UsageMeterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One team's record of one metric over one billing period — written by {@see UsageLedger}, never
 * by the thing being metered.
 *
 * **It is not the counter the guards read.** Enforcement reads the authoritative ledgers directly
 * (`ai_requests`, `discovery_runs`), so a limit can never be wrong because a worker died between
 * doing the work and incrementing a row. What this table is for is the two jobs those ledgers
 * cannot do: remembering what a closed period cost after retention has pruned the rows behind it,
 * and remembering — in `reported` — how much overage has already been pushed to Stripe, so a
 * retried command cannot bill the same units twice.
 *
 * @property int $id
 * @property int $team_id
 * @property BillingMetric $metric
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $used
 * @property int $included
 * @property int $reported
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UsageMeter extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<UsageMeterFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'metric',
        'period_start',
        'period_end',
        'used',
        'included',
        'reported',
        'synced_at',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForMetric(Builder $query, BillingMetric $metric): Builder
    {
        return $query->where('metric', $metric->value);
    }

    /**
     * Overage units consumed but not yet reported to Stripe. The only number the overage reporter
     * sends, which is what makes reporting idempotent.
     */
    public function unreportedOverage(): int
    {
        return max(0, ($this->used - $this->included) - $this->reported);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'metric' => BillingMetric::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'used' => 'integer',
            'included' => 'integer',
            'reported' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UsageMeterFactory
    {
        return UsageMeterFactory::new();
    }
}
