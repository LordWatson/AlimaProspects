<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model. Adds a global scope constraining rows to the active team
 * (from {@see TeamContext}) and auto-assigns `team_id` on create. Policies still double-check
 * ownership (ADR 0006): a global scope is a convenience, never the sole tenancy guarantee. When no
 * team is active (console, migrations, unauthenticated) the scope is a no-op.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope('team', static function (Builder $builder): void {
            $teamId = app(TeamContext::class)->id();

            if ($teamId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('team_id'), $teamId);
            }
        });

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('team_id') === null) {
                $teamId = app(TeamContext::class)->id();

                if ($teamId !== null) {
                    $model->setAttribute('team_id', $teamId);
                }
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
