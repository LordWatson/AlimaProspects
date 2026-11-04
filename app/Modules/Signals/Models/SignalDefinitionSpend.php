<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Services\SignalSpendLedger;
use Database\Factories\SignalDefinitionSpendFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one signal type cost a team on one day (S15 task 2).
 *
 * A **record over the run, not a counter**: a finished run computes its day's figures and
 * {@see SignalSpendLedger} writes them once. Nothing in the application increments a row, so a row
 * says what that day's searching cost at that day's prices, and changing
 * `signals.sources.*.cost_micros_per_request` later cannot rewrite it.
 *
 * `event_key` is the identity and `signal_definition_id` only the live attribution, which is the
 * arrangement `signals` itself uses (ADR 0018 §4): the record of what a type cost is the most
 * useful thing to keep about one a team has since deleted.
 *
 * The two cost columns are not interchangeable. `search_cost_micros` is **measured** — a query
 * belongs to exactly one type. `ai_cost_micros` is **allocated** by share of results, because one
 * classification call is offered every type on the watch at once. Keeping them apart is what stops
 * a screen presenting an allocation as a measurement.
 *
 * Tenant-scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property string $event_key
 * @property int|null $signal_definition_id
 * @property Carbon $day
 * @property int $queries
 * @property int $results
 * @property int $search_cost_micros
 * @property int $ai_cost_micros
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalDefinitionSpend extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalDefinitionSpendFactory> */
    use HasFactory;

    protected $table = 'signal_definition_spend';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'event_key',
        'signal_definition_id',
        'day',
        'queries',
        'results',
        'search_cost_micros',
        'ai_cost_micros',
    ];

    /**
     * The type, when it still exists. `withTrashed` for ADR 0018 §4's reason — a cost history about
     * a removed type must still render.
     *
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SignalDefinition::class, 'signal_definition_id')->withTrashed();
    }

    /** Measured plus allocated. The figure a cost-per-lead is divided from. */
    public function totalCostMicros(): int
    {
        return $this->search_cost_micros + $this->ai_cost_micros;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'queries' => 'integer',
            'results' => 'integer',
            'search_cost_micros' => 'integer',
            'ai_cost_micros' => 'integer',
        ];
    }

    protected static function newFactory(): SignalDefinitionSpendFactory
    {
        return SignalDefinitionSpendFactory::new();
    }
}
