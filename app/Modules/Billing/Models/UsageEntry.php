<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\LedgerDirection;
use App\Modules\Core\Concerns\BelongsToTeam;
use Database\Factories\UsageEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of `usage_ledger` (PL4): allowance held, spent, released, granted or bought.
 *
 * Append-only. There is no `updated_at` and nothing updates a row — a reservation is settled by
 * **writing** a commit or a release beside it, which is what keeps a disputed month readable.
 *
 * `quantity` is always positive and in the metric's own unit; {@see LedgerDirection} says which way
 * it counts. Signed quantities were the alternative and they are ambiguous exactly when it matters:
 * a negative 3 in a month a customer is querying could be a release, a refund or a correction, and
 * nobody can remember which.
 *
 * @property int $id
 * @property int $team_id
 * @property BillingMetric $meter
 * @property LedgerDirection $direction
 * @property int $quantity always positive; the direction carries the sign
 * @property string $period `YYYY-MM`, UTC
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reservation the handle a commit or release settles a hold by
 * @property string|null $idempotency_key the external fact that caused this (a Stripe event id)
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
class UsageEntry extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<UsageEntryFactory> */
    use HasFactory;

    protected $table = 'usage_ledger';

    /** Append-only: written once, never touched again. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'meter',
        'direction',
        'quantity',
        'period',
        'reference_type',
        'reference_id',
        'reservation',
        'idempotency_key',
        'meta',
    ];

    /**
     * One team's rows for one metric in one period — the scope every aggregate here starts from.
     *
     * @param  Builder<UsageEntry>  $query
     * @return Builder<UsageEntry>
     */
    public function scopeForMeter(Builder $query, int $teamId, BillingMetric $meter, string $period): Builder
    {
        return $query
            ->where('team_id', $teamId)
            ->where('meter', $meter)
            ->where('period', $period);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'meter' => BillingMetric::class,
            'direction' => LedgerDirection::class,
            'quantity' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): UsageEntryFactory
    {
        return UsageEntryFactory::new();
    }
}
