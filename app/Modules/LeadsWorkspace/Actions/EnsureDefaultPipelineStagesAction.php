<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gives a team a board the first time it needs one, from `leads-workspace.default_stages`.
 *
 * Created **lazily** rather than at sign-up: a team that never opens the pipeline never needs
 * stages, and doing it here means teams that already existed before this phase get a board the
 * moment they look at it, with no backfill migration. Idempotent — a team that already has stages
 * gets them back untouched, and two simultaneous first visits can't produce two boards, because
 * the second one loses the `(team_id, slug)` unique race and re-reads instead.
 */
final class EnsureDefaultPipelineStagesAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return Collection<int, PipelineStage>
     */
    public function handle(Team $team): Collection
    {
        return $this->teamContext->run($team, function () use ($team): Collection {
            $existing = $this->stages($team);

            if ($existing->isNotEmpty()) {
                return $existing;
            }

            try {
                DB::transaction(function () use ($team): void {
                    foreach ($this->defaults() as $position => $stage) {
                        PipelineStage::query()->create([
                            'team_id' => $team->getKey(),
                            'name' => $stage['name'],
                            'slug' => $stage['slug'],
                            'position' => $position,
                            'is_default' => $stage['default'],
                            'outcome' => $stage['outcome'],
                        ]);
                    }
                });
            } catch (UniqueConstraintViolationException) {
                // Another request got there first; its board is the board.
            }

            return $this->stages($team);
        });
    }

    /**
     * @return Collection<int, PipelineStage>
     */
    private function stages(Team $team): Collection
    {
        return PipelineStage::query()
            ->where('team_id', $team->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * The configured starting board, narrowed so a bad config can't write a nonsense stage. The
     * first entry is treated as the default when the config marks none.
     *
     * @return list<array{name: string, slug: string, default: bool, outcome: StageOutcome|null}>
     */
    private function defaults(): array
    {
        /** @var array<int, array<string, mixed>> $configured */
        $configured = config('leads-workspace.default_stages', []);

        $stages = [];

        foreach (array_values($configured) as $stage) {
            $name = trim((string) ($stage['name'] ?? ''));
            $slug = trim((string) ($stage['slug'] ?? ''));

            if ($name === '' || $slug === '') {
                continue;
            }

            $stages[] = [
                'name' => $name,
                'slug' => $slug,
                'default' => (bool) ($stage['default'] ?? false),
                'outcome' => StageOutcome::tryFrom((string) ($stage['outcome'] ?? '')),
            ];
        }

        // A board with no default column would leave every unplaced lead invisible, so if the
        // config marks none, the first column takes the job.
        if ($stages !== [] && ! $this->hasDefault($stages)) {
            $stages[0]['default'] = true;
        }

        return $stages;
    }

    /**
     * @param  list<array{name: string, slug: string, default: bool, outcome: StageOutcome|null}>  $stages
     */
    private function hasDefault(array $stages): bool
    {
        foreach ($stages as $stage) {
            if ($stage['default']) {
                return true;
            }
        }

        return false;
    }
}
