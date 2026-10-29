<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Onboarding\Enums\OnboardingStep;
use Database\Factories\OnboardingProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Resumable wizard state for a team: the step they're on and the steps they've saved. One row per
 * team. Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property OnboardingStep $current_step
 * @property list<string>|null $completed_steps
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OnboardingProgress extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<OnboardingProgressFactory> */
    use HasFactory;

    protected $table = 'onboarding_progress';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'current_step',
        'completed_steps',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'current_step' => 'company',
    ];

    /**
     * Record a data step as completed (idempotent) and advance the pointer to the next step.
     */
    public function markStepCompleted(OnboardingStep $step): void
    {
        $completed = $this->completed_steps ?? [];

        if (! in_array($step->value, $completed, strict: true)) {
            $completed[] = $step->value;
        }

        $this->completed_steps = $completed;
        $this->current_step = $step->next() ?? $step;
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'current_step' => OnboardingStep::class,
            'completed_steps' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OnboardingProgressFactory
    {
        return OnboardingProgressFactory::new();
    }
}
