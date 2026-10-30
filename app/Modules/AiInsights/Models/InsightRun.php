<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Models;

use App\Modules\AiInsights\Console\GenerateInsightsCommand;
use App\Modules\AiInsights\Services\InsightGenerator;
use App\Modules\Core\Concerns\BelongsToTeam;
use Database\Factories\InsightRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "This team's insights have been worked out for this date" (Phase 14).
 *
 * The marker {@see GenerateInsightsCommand} reads to decide whether a team is due, and
 * {@see InsightGenerator} writes whatever the run found — **including nothing**. That is the whole
 * reason the table exists: a day with no stale leads, nobody to call and a model with nothing to
 * say produces no `insights` rows, and a sweep that asked the insights table whether the team had
 * been done would pay for the aggregates and an AI call again every hour until midnight.
 *
 * `local_date` is the date in the **team's** timezone, because that is the unit "once a day" is
 * measured in. Tenant-scoped via {@see BelongsToTeam}; the sweep reads it across every team with
 * no active team set, where the global scope is a no-op.
 *
 * @property int $id
 * @property int $team_id
 * @property string $local_date
 * @property Carbon $generated_at
 * @property int $insights_created
 * @property bool $ai_called
 * @property int $cost_micros
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InsightRun extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<InsightRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'local_date',
        'generated_at',
        'insights_created',
        'ai_called',
        'cost_micros',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            // **Not** a date cast: this is a calendar date in the team's own timezone, compared as
            // a string against another such date. Casting it would hand back a Carbon in the app
            // timezone and invite exactly the conversion the column exists to avoid.
            'generated_at' => 'datetime',
            'insights_created' => 'integer',
            'ai_called' => 'boolean',
            'cost_micros' => 'integer',
        ];
    }

    protected static function newFactory(): InsightRunFactory
    {
        return InsightRunFactory::new();
    }
}
