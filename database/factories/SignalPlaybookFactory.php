<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Data\SignalPlaybookConditions;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Enums\PlaybookNotifyAudience;
use App\Modules\Signals\Enums\PlaybookWatchlistCondition;
use App\Modules\Signals\Models\SignalPlaybook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalPlaybook>
 */
class SignalPlaybookFactory extends Factory
{
    protected $model = SignalPlaybook::class;

    /**
     * A **live** rule with one harmless step, which is the state most tests want to assert about.
     *
     * Live rather than rehearsing, which is the opposite of the save path's default — deliberately.
     * `SignalPlaybookData` defaults to a rehearsal because a rule somebody created without thinking
     * about the mode should watch; a factory defaults to live because a test that built a rule and
     * asserted nothing happened would pass for the wrong reason.
     *
     * The conditions are empty, which means "every signal" — so a test has to narrow them to assert
     * about narrowing, rather than having to widen them to assert about firing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => null,
            'name' => 'Handle '.$this->faker->unique()->word(),
            'is_active' => true,
            'mode' => PlaybookMode::Live,
            'conditions' => (new SignalPlaybookConditions)->toStored(),
            'actions' => [
                [
                    'kind' => PlaybookActionKind::Notify->value,
                    'params' => ['audience' => PlaybookNotifyAudience::Team->value],
                ],
            ],
            'stop_after_first_match' => false,
            'priority' => 100,
        ];
    }

    public function rehearsing(): static
    {
        return $this->state(fn (array $attributes): array => ['mode' => PlaybookMode::DryRun]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    /**
     * The acceptance-criteria rule: convert, assign, stage, draft.
     *
     * @param  list<int>  $memberIds
     */
    public function fullChain(int $stageId, array $memberIds, string $channel = 'linkedin', string $tone = 'warm'): static
    {
        return $this->state(fn (array $attributes): array => [
            'actions' => [
                ['kind' => PlaybookActionKind::ConvertToLead->value, 'params' => []],
                [
                    'kind' => PlaybookActionKind::AssignToMember->value,
                    'params' => ['user_ids' => $memberIds],
                ],
                [
                    'kind' => PlaybookActionKind::MoveToPipelineStage->value,
                    'params' => ['stage_id' => $stageId],
                ],
                [
                    'kind' => PlaybookActionKind::DraftOutreach->value,
                    'params' => ['channel' => $channel, 'tone' => $tone],
                ],
            ],
        ]);
    }

    /**
     * One named step with its parameters, for the handler tests.
     *
     * @param  array<string, mixed>  $params
     */
    public function withStep(PlaybookActionKind $kind, array $params = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'actions' => [['kind' => $kind->value, 'params' => $params]],
        ]);
    }

    /**
     * Narrow the rule. Takes the stored shape, so a test reads the same keys the API posts.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function matching(array $conditions): static
    {
        return $this->state(fn (array $attributes): array => [
            'conditions' => SignalPlaybookConditions::fromStored($conditions)->toStored(),
        ]);
    }

    /** Only events about companies the team already has. */
    public function onlyMatchedAccounts(): static
    {
        return $this->matching(['watchlist' => PlaybookWatchlistCondition::Matched->value]);
    }
}
