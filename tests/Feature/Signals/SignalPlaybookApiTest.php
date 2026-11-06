<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Data\SignalPlaybookConditions;
use App\Modules\Signals\Data\SignalPlaybookData;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Enums\PlaybookNotifyAudience;
use App\Modules\Signals\Enums\PlaybookWatchlistCondition;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPlaybookRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/*
 * `/api/v1` and web parity for playbooks (S14) — the same Actions behind both, so a team
 * configuring rules from their own tooling gets the same ceilings, the same semantic checks and the
 * same rehearsal default as a member using the builder.
 *
 * The default is the thing worth noticing in these tests: a rule posted with no `mode` **rehearses**.
 * It is the only safety default in this phase, and it is here rather than in the column because a
 * caller who did not think about the mode should watch rather than act.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = createUserWithTeam();
    $this->team = $this->user->currentTeam()->firstOrFail();

    app(EnsureDefaultPipelineStagesAction::class)->handle($this->team);
});

function apiStage(object $test): PipelineStage
{
    return app(TeamContext::class)->run($test->team, fn (): PipelineStage => PipelineStage::query()
        ->orderBy('position')
        ->firstOrFail());
}

it('lists, shows, creates, updates and deletes playbooks', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $created = $this->postJson(route('api.v1.signal-playbooks.store'), [
        'name' => 'Handle big acquisitions',
        'mode' => PlaybookMode::Live->value,
        'conditions' => [
            'min_heat' => 70,
            'watchlist' => PlaybookWatchlistCondition::Matched->value,
        ],
        'actions' => [
            ['kind' => PlaybookActionKind::AttachToMatchedLead->value],
            [
                'kind' => PlaybookActionKind::MoveToPipelineStage->value,
                'params' => ['stage_id' => (int) apiStage($this)->getKey()],
            ],
        ],
    ])
        ->assertCreated()
        ->assertApiVersion()
        ->assertJsonPath('data.name', 'Handle big acquisitions')
        ->assertJsonPath('data.mode', 'live')
        ->assertJsonPath('data.is_rehearsing', false)
        ->assertJsonPath('data.actions_count', 2)
        ->assertJsonPath('data.conditions.min_heat', 70)
        ->assertJsonPath('data.has_unknown_steps', false)
        ->json('data.id');

    $this->getJson(route('api.v1.signal-playbooks.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.runs_count', 0);

    $this->getJson(route('api.v1.signal-playbooks.show', ['playbook' => $created]))
        ->assertOk()
        ->assertJsonPath('data.id', $created);

    $this->patchJson(route('api.v1.signal-playbooks.update', ['playbook' => $created]), [
        'name' => 'Handle big acquisitions',
        'mode' => PlaybookMode::DryRun->value,
        'actions' => [['kind' => PlaybookActionKind::Notify->value]],
    ])
        ->assertOk()
        ->assertJsonPath('data.is_rehearsing', true)
        ->assertJsonPath('data.actions_count', 1);

    $this->deleteJson(route('api.v1.signal-playbooks.destroy', ['playbook' => $created]))
        ->assertNoContent();

    expect(SignalPlaybook::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('rehearses by default when no mode is posted', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    // `mode` is a required rule, so a caller must name it — but the Data object's own default is
    // the rehearsal, which is what an older client or an internal caller lands on.
    $this->postJson(route('api.v1.signal-playbooks.store'), [
        'name' => 'Watch and tell me',
        'mode' => 'nonsense',
        'actions' => [['kind' => PlaybookActionKind::Notify->value]],
    ])->assertApiValidationErrors(['mode']);

    expect((new SignalPlaybookData(
        name: 'x',
        conditions: new SignalPlaybookConditions,
        steps: [],
    ))->mode)->toBe(PlaybookMode::DryRun);
});

describe('what a rule may be', function (): void {
    it('refuses a step that cannot do anything, against its own field', function (): void {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.signal-playbooks.store'), [
            'name' => 'Broken',
            'mode' => PlaybookMode::Live->value,
            'actions' => [
                // A stage move with no stage, and a task with no title.
                ['kind' => PlaybookActionKind::MoveToPipelineStage->value, 'params' => []],
                ['kind' => PlaybookActionKind::CreateTask->value, 'params' => []],
            ],
        ])->assertApiValidationErrors([
            'actions.0.params.stage_id',
            'actions.1.params.title',
        ]);
    });

    it('refuses converting and attaching in the same rule', function (): void {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.signal-playbooks.store'), [
            'name' => 'Both',
            'mode' => PlaybookMode::Live->value,
            'actions' => [
                ['kind' => PlaybookActionKind::ConvertToLead->value],
                ['kind' => PlaybookActionKind::AttachToMatchedLead->value],
            ],
        ])->assertApiValidationErrors(['actions']);
    });

    it('refuses a step repeated that cannot be', function (): void {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.signal-playbooks.store'), [
            'name' => 'Twice',
            'mode' => PlaybookMode::Live->value,
            'actions' => [
                ['kind' => PlaybookActionKind::ConvertToLead->value],
                ['kind' => PlaybookActionKind::ConvertToLead->value],
            ],
        ])->assertApiValidationErrors(['actions.1.kind']);
    });

    it('allows a tag and a task twice', function (): void {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.signal-playbooks.store'), [
            'name' => 'Two labels',
            'mode' => PlaybookMode::Live->value,
            'actions' => [
                ['kind' => PlaybookActionKind::AddTag->value, 'params' => ['tags' => ['Hot']]],
                ['kind' => PlaybookActionKind::AddTag->value, 'params' => ['tags' => ['Acquisition']]],
            ],
        ])->assertCreated();
    });

    it('refuses a rule past the team ceiling', function (): void {
        config()->set('signals.playbooks.max_per_team', 1);
        Sanctum::actingAs($this->user, ['*']);

        SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);

        $this->postJson(route('api.v1.signal-playbooks.store'), [
            'name' => 'One too many',
            'mode' => PlaybookMode::Live->value,
            'actions' => [['kind' => PlaybookActionKind::Notify->value]],
        ])->assertApiError('signals.too_many_playbooks', 422);
    });

    it('refuses more steps than the configured maximum', function (): void {
        config()->set('signals.playbooks.max_actions', 1);
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.signal-playbooks.store'), [
            'name' => 'Long',
            'mode' => PlaybookMode::Live->value,
            'actions' => [
                ['kind' => PlaybookActionKind::AddTag->value, 'params' => ['tags' => ['a']]],
                ['kind' => PlaybookActionKind::AddTag->value, 'params' => ['tags' => ['b']]],
            ],
        ])->assertApiValidationErrors(['actions']);
    });
});

describe('the preview', function (): void {
    it('reports what a rule would have matched, with a denominator', function (): void {
        Sanctum::actingAs($this->user, ['*']);

        app(TeamContext::class)->run($this->team, function (): void {
            Signal::factory()->count(2)->create(['team_id' => $this->team->getKey(), 'heat' => 90]);
            Signal::factory()->create(['team_id' => $this->team->getKey(), 'heat' => 10]);
        });

        $this->postJson(route('api.v1.signal-playbooks.preview'), [
            'conditions' => ['min_heat' => 50],
            'kinds' => [PlaybookActionKind::ConvertToLead->value],
        ])
            ->assertOk()
            // The ratio is the point: "2 of 3" is a rule somebody can switch on.
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.considered', 3)
            ->assertJsonPath('data.matches_everything', false)
            ->assertJsonCount(2, 'data.samples');
    });

    it('says when a rule narrows nothing', function (): void {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.signal-playbooks.preview'), ['conditions' => []])
            ->assertOk()
            ->assertJsonPath('data.matches_everything', true);
    });

    it('warns when a matching rule would hit the per-job conversion ceiling', function (): void {
        config()->set('signals.playbooks.max_conversions_per_run', 1);
        Sanctum::actingAs($this->user, ['*']);

        app(TeamContext::class)->run($this->team, function (): void {
            Signal::factory()->count(2)->create(['team_id' => $this->team->getKey()]);
        });

        $this->postJson(route('api.v1.signal-playbooks.preview'), [
            'conditions' => [],
            'kinds' => [PlaybookActionKind::ConvertToLead->value],
        ])
            ->assertOk()
            ->assertJsonPath('data.would_exceed_conversions', true);
    });

    it('creates nothing', function (): void {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.signal-playbooks.preview'), ['conditions' => []])->assertOk();

        expect(SignalPlaybook::query()->withoutGlobalScopes()->count())->toBe(0);
        expect(SignalPlaybookRun::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('the run log', function (): void {
    it('lists runs newest first and can be narrowed to the ones needing attention', function (): void {
        Sanctum::actingAs($this->user, ['*']);

        $playbook = SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);

        app(TeamContext::class)->run($this->team, function () use ($playbook): void {
            SignalPlaybookRun::factory()->create([
                'team_id' => $this->team->getKey(),
                'signal_playbook_id' => $playbook->getKey(),
                'signal_id' => Signal::factory()->create(['team_id' => $this->team->getKey()])->getKey(),
            ]);

            SignalPlaybookRun::factory()->refusedDraft()->create([
                'team_id' => $this->team->getKey(),
                'signal_playbook_id' => $playbook->getKey(),
                'signal_id' => Signal::factory()->create(['team_id' => $this->team->getKey()])->getKey(),
            ]);
        });

        $this->getJson(route('api.v1.signal-playbooks.runs'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.needs_attention', true);

        $this->getJson(route('api.v1.signal-playbooks.runs', ['attention' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // The guard's own vocabulary reaches the client, so a screen can say what to do next.
            ->assertJsonPath('data.0.steps.0.status', 'refused')
            ->assertJsonPath('data.0.steps.0.reason', 'outreach_blocked')
            ->assertJsonPath('data.0.steps.0.params.block_reason', 'restricted');
    });
});

describe('tenancy and scopes', function (): void {
    it('never reaches another team playbook', function (): void {
        $other = createUserWithTeam();
        $theirs = SignalPlaybook::factory()->create([
            'team_id' => $other->currentTeam()->firstOrFail()->getKey(),
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->getJson(route('api.v1.signal-playbooks.show', ['playbook' => $theirs->getKey()]))
            ->assertNotFound();
        $this->patchJson(route('api.v1.signal-playbooks.update', ['playbook' => $theirs->getKey()]), [
            'name' => 'Mine now',
            'mode' => PlaybookMode::Live->value,
            'actions' => [['kind' => PlaybookActionKind::Notify->value]],
        ])->assertNotFound();
        $this->deleteJson(route('api.v1.signal-playbooks.destroy', ['playbook' => $theirs->getKey()]))
            ->assertNotFound();

        expect(SignalPlaybook::query()->withoutGlobalScopes()->whereKey($theirs->getKey())->exists())->toBeTrue();
    });

    it('refuses a read-only token the write routes', function (): void {
        Sanctum::actingAs($this->user, [TokenAbility::SignalsRead->value]);

        $this->getJson(route('api.v1.signal-playbooks.index'))->assertOk();
        $this->postJson(route('api.v1.signal-playbooks.store'), [
            'name' => 'Nope',
            'mode' => PlaybookMode::Live->value,
            'actions' => [['kind' => PlaybookActionKind::Notify->value]],
        ])->assertForbidden();
        // Previewing posts a rule, so it is a write scope even though it spends nothing.
        $this->postJson(route('api.v1.signal-playbooks.preview'), ['conditions' => []])->assertForbidden();
    });

    it('lets a read-only member preview but not change', function (): void {
        $readonly = User::factory()->create();
        app(TeamMembership::class)->add($readonly, $this->team, TeamRole::ReadOnly);
        $readonly->forceFill(['current_team_id' => $this->team->getKey()])->save();

        Sanctum::actingAs($readonly->refresh(), ['*']);

        // Reviewing a colleague's rule is exactly the question the safest person on the team wants
        // answered, and previewing executes nothing.
        $this->postJson(route('api.v1.signal-playbooks.preview'), ['conditions' => []])->assertOk();

        $this->postJson(route('api.v1.signal-playbooks.store'), [
            'name' => 'Nope',
            'mode' => PlaybookMode::Live->value,
            'actions' => [['kind' => PlaybookActionKind::Notify->value]],
        ])->assertForbidden();
    });
});

describe('the web screens', function (): void {
    it('renders the builder with everything it has to offer', function (): void {
        $this->actingAs($this->user)
            ->get(route('signal-playbooks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('signals/Playbooks')
                ->has('options.action_kinds')
                ->has('options.modes', 2)
                ->has('options.stages')
                ->has('options.members', 1)
                ->where('can.manage', true));
    });

    it('renders the run log', function (): void {
        $playbook = SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);

        app(TeamContext::class)->run($this->team, function () use ($playbook): void {
            SignalPlaybookRun::factory()->refusedDraft()->create([
                'team_id' => $this->team->getKey(),
                'signal_playbook_id' => $playbook->getKey(),
                'signal_id' => Signal::factory()->create(['team_id' => $this->team->getKey()])->getKey(),
            ]);
        });

        $this->actingAs($this->user)
            ->get(route('signal-playbooks.activity', ['attention' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('signals/PlaybookActivity')
                ->has('runs.data', 1)
                ->where('runs.data.0.needs_attention', true)
                ->where('filters.attention', true));
    });

    it('creates a rule from the builder and says which mode it is in', function (): void {
        $this->actingAs($this->user)
            ->post(route('signal-playbooks.store'), [
                'name' => 'Tell me about my accounts',
                'mode' => PlaybookMode::DryRun->value,
                'conditions' => ['watchlist' => PlaybookWatchlistCondition::Matched->value],
                'actions' => [
                    [
                        'kind' => PlaybookActionKind::Notify->value,
                        'params' => ['audience' => PlaybookNotifyAudience::LeadOwners->value],
                    ],
                ],
            ])
            ->assertRedirect(route('signal-playbooks.index'));

        $playbook = SignalPlaybook::query()->withoutGlobalScopes()->firstOrFail();

        expect($playbook->mode)->toBe(PlaybookMode::DryRun);
        expect($playbook->conditionSet()->watchlist)->toBe(PlaybookWatchlistCondition::Matched);
    });

    it('flashes the preview beside the fields it describes', function (): void {
        $this->actingAs($this->user)
            ->post(route('signal-playbooks.preview'), ['conditions' => []])
            ->assertRedirect();
    });
});
