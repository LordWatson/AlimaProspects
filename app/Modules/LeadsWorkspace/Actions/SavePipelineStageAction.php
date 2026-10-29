<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Exceptions\LeadsWorkspaceException;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds a stage to a team's board, or edits one (Phase 10b).
 *
 * The `slug` is set once, on creation, and never moves: a rename is a label change, and a saved
 * view, an export or a Phase 13 report that referred to the stage must survive the team deciding
 * to call "Working" something else. Exactly one stage is the default, enforced here — the schema
 * can't, because a partial unique index isn't portable between SQLite and MySQL.
 */
final class SavePipelineStageAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        Team $team,
        User $actor,
        string $name,
        ?StageOutcome $outcome = null,
        bool $isDefault = false,
        ?PipelineStage $stage = null,
    ): PipelineStage {
        return DB::transaction(function () use ($team, $actor, $name, $outcome, $isDefault, $stage): PipelineStage {
            if ($stage === null) {
                $count = PipelineStage::query()->where('team_id', $team->getKey())->count();
                $max = max(1, (int) config('leads-workspace.max_stages', 20));

                if ($count >= $max) {
                    throw LeadsWorkspaceException::tooManyStages($max);
                }

                $stage = new PipelineStage;
                $stage->forceFill([
                    'team_id' => $team->getKey(),
                    'slug' => $this->uniqueSlug($team, $name),
                    // New columns go on the end, where a board is read left to right.
                    'position' => (int) PipelineStage::query()
                        ->where('team_id', $team->getKey())
                        ->max('position') + 1,
                ]);
            }

            $stage->fill(['name' => $name, 'outcome' => $outcome]);

            // A stage can't be both the starting column and an ending one; the outcome wins,
            // because that is the stronger statement about what the stage means.
            $stage->is_default = $isDefault && $outcome === null;
            $stage->save();

            if ($stage->is_default) {
                PipelineStage::query()
                    ->where('team_id', $team->getKey())
                    ->whereKeyNot($stage->getKey())
                    ->update(['is_default' => false]);
            } else {
                $this->keepADefault($team, $stage);
            }

            $this->audit->log('leads.stage_saved', $stage, [
                'slug' => $stage->slug,
                'outcome' => $stage->outcome?->value,
                'is_default' => $stage->is_default,
            ], $actor);

            return $stage;
        });
    }

    /**
     * A board must always have exactly one default column, because that is where every lead nobody
     * has placed yet lives (`leads.pipeline_stage_id` is null for them). Editing the current
     * default and un-ticking the box — or giving it an outcome, which clears the flag — would
     * otherwise leave the team with none, and those leads would vanish from the board entirely.
     *
     * So if this save has left the team without one, the first column that isn't an ending stage
     * takes the job. Promoting rather than refusing keeps the invariant without making the member
     * guess which edit was the problem.
     */
    private function keepADefault(Team $team, PipelineStage $saved): void
    {
        $hasDefault = PipelineStage::query()
            ->where('team_id', $team->getKey())
            ->where('is_default', true)
            ->exists();

        if ($hasDefault) {
            return;
        }

        $promote = PipelineStage::query()
            ->where('team_id', $team->getKey())
            ->whereNull('outcome')
            ->orderBy('position')
            ->orderBy('id')
            ->first()
            // A board of nothing but Won and Lost is a strange board, but it still needs somewhere
            // for a new lead to sit.
            ?? PipelineStage::query()
                ->where('team_id', $team->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->first();

        if (! $promote instanceof PipelineStage) {
            return;
        }

        $promote->forceFill(['is_default' => true])->save();

        if ((int) $promote->getKey() === (int) $saved->getKey()) {
            // Keep the instance the caller is holding in step with what was written.
            $saved->setAttribute('is_default', true);
        }
    }

    /**
     * A stable, team-unique key for a new stage. Falls back to a counter rather than a random
     * string so a board's slugs stay readable in an export.
     */
    private function uniqueSlug(Team $team, string $name): string
    {
        // Str::slug strips Arabic entirely, so a lower-cased, squished, space-to-dash form is used
        // instead and a team whose stage names are all Arabic still gets distinct keys.
        $base = Str::limit(Str::replace(' ', '-', Str::lower(Str::squish($name))), 50, '');
        $base = $base === '' ? 'stage' : $base;

        $slug = $base;
        $suffix = 2;

        while (PipelineStage::query()->where('team_id', $team->getKey())->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
