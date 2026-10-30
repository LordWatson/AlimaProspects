<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Models;

use App\Models\User;
use App\Modules\AiInsights\Enums\InsightSeverity;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\Core\Concerns\BelongsToTeam;
use Database\Factories\InsightFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored, dismissable insight (Phase 14): what Alima noticed about a team's prospecting, with
 * the ids and counts behind it. Tenant-scoped via {@see BelongsToTeam}.
 *
 * Insights are **derived** — nothing else in the platform reads them, and every row could be
 * rebuilt from the leads and the Reporting read-model. That is what makes the retention sweep
 * uncontroversial, and it is why a reader should never treat an insight as a source of truth: the
 * payload's counts are a snapshot of `generated_at`, and the lead it points at may have moved on
 * since.
 *
 * @property int $id
 * @property int $team_id
 * @property InsightType $type
 * @property InsightStatus $status
 * @property InsightSeverity $severity
 * @property string|null $headline
 * @property string|null $body
 * @property array<string, mixed> $payload
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string|null $prompt_key
 * @property int|null $prompt_version
 * @property string|null $model
 * @property int $cost_micros
 * @property Carbon $generated_at
 * @property string $dedupe_key
 * @property int|null $dismissed_by
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Insight extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<InsightFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'type',
        'status',
        'severity',
        'headline',
        'body',
        'payload',
        'period_start',
        'period_end',
        'prompt_key',
        'prompt_version',
        'model',
        'cost_micros',
        'generated_at',
        'dedupe_key',
        'dismissed_by',
        'dismissed_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => InsightStatus::New->value,
        'severity' => InsightSeverity::Info->value,
        'cost_micros' => 0,
    ];

    /**
     * The member who cleared it away.
     *
     * @return BelongsTo<User, $this>
     */
    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    /**
     * The feed's order: what to do first, then the newest.
     *
     * Severity is sorted by its stored value rather than by {@see InsightSeverity::weight()},
     * because the ordering has to happen in SQL for the paginator — so the `case` is written out
     * once, here, instead of the rows being sorted in PHP after a page has already been chosen.
     *
     * @param  Builder<Insight>  $query
     * @return Builder<Insight>
     */
    public function scopeFeedOrder(Builder $query): Builder
    {
        $cases = [];
        $bindings = [];

        foreach (InsightSeverity::cases() as $severity) {
            $cases[] = 'when severity = ? then ?';
            $bindings[] = $severity->value;
            $bindings[] = $severity->weight();
        }

        return $query
            ->orderByRaw('case '.implode(' ', $cases).' else 0 end desc', $bindings)
            ->orderByDesc('generated_at')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => InsightType::class,
            'status' => InsightStatus::class,
            'severity' => InsightSeverity::class,
            'payload' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'prompt_version' => 'integer',
            'cost_micros' => 'integer',
            'generated_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): InsightFactory
    {
        return InsightFactory::new();
    }
}
