<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPlaybookRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalPlaybookRun>
 */
class SignalPlaybookRunFactory extends Factory
{
    protected $model = SignalPlaybookRun::class;

    /**
     * A live run whose one step was applied — the ordinary row the log is mostly made of.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'signal_playbook_id' => SignalPlaybook::factory(),
            'playbook_name' => 'Handle acquisitions',
            'signal_id' => Signal::factory(),
            'lead_id' => null,
            'actor_id' => null,
            'mode' => PlaybookMode::Live,
            'results' => [
                PlaybookStepOutcome::applied(PlaybookActionKind::Notify, ['recipients' => 2])->toStored(),
            ],
            'applied_count' => 1,
            'refused_count' => 0,
            'failed_count' => 0,
        ];
    }

    /**
     * A run whose drafting step was refused by the outreach guard — the row the log exists for, and
     * the one the tests assert about most.
     */
    public function refusedDraft(string $reasonKey = 'restricted'): static
    {
        return $this->state(fn (array $attributes): array => [
            'results' => [
                PlaybookStepOutcome::refused(
                    PlaybookActionKind::DraftOutreach,
                    PlaybookOutcomeReason::OutreachBlocked,
                    [
                        'block_reason' => $reasonKey,
                        'block_label' => 'Get consent first',
                        'block_guidance' => 'Approach via LinkedIn or a referral — not cold email.',
                        'is_compliance' => true,
                        'channel' => 'email',
                    ],
                )->toStored(),
            ],
            'applied_count' => 0,
            'refused_count' => 1,
        ]);
    }

    public function rehearsal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => PlaybookMode::DryRun,
            'results' => [
                PlaybookStepOutcome::simulated(PlaybookActionKind::ConvertToLead, ['company' => 'Acme Ltd'])->toStored(),
            ],
            'applied_count' => 0,
        ]);
    }
}
