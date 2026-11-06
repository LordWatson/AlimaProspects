<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Services\Platform\SignalLibrary;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\AdoptPresetChangesAction;
use App\Modules\Signals\Actions\PromoteSignalDefinitionAction;
use App\Modules\Signals\Actions\SavePlatformPresetAction;
use App\Modules\Signals\Actions\SubmitSignalDefinitionAction;
use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\SignalSubmissionStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSubmission;
use App\Modules\Signals\Services\PlatformPresetReader;
use App\Modules\Signals\Services\PresetUpdateDiffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminFixtures as Admin;
use Tests\Support\BillingFixtures as Billing;

/*
 * The curated library: a team offers, an operator curates, and every clone is **offered** the
 * result (S15 tasks 4 and 5).
 *
 * The four assertions worth more than the rest:
 *
 * **Promotion copies.** The submitting team's own type is untouched — same row, still running,
 * still theirs. Re-pointing it at `team_id = null` would take their type away as a reward for
 * sharing it.
 *
 * **A promotion never touches a clone.** Teams already holding a copy keep running exactly what
 * they chose, and are *offered* the change per field. That is ADR 0018 §3's whole argument, and the
 * thing a reference-based preset could not have given them.
 *
 * **The author is anonymised in the queue and the evidence is not.** An operator choosing between
 * two submissions chooses on how the type performed, not on whose it is.
 *
 * **Taking nothing is a real answer.** An empty adoption records that the member saw the version
 * and kept theirs, which is what stops the panel asking for ever.
 */

uses(RefreshDatabase::class);

/** A type of this team's own — not a clone, with searches, so it may be offered. */
function ownType(Team $team, array $attributes = []): SignalDefinition
{
    return app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'preset_key' => null,
        'preset_version' => null,
        'query_templates' => ['"{company}" raises'],
    ], $attributes)));
}

/**
 * A platform preset: `team_id = null`, which only `PlatformPresetReader` reads.
 *
 * The context is **dropped** around the write, because `BelongsToTeam` auto-assigns `team_id` to
 * whatever team is active — so a preset created inside one would quietly become that team's own
 * type, with the right columns and the wrong owner. The real path has the same requirement:
 * `SavePlatformPresetAction` runs outside any team context, which is why a preset belongs to
 * nobody.
 */
function platformPreset(array $attributes = []): SignalDefinition
{
    $context = app(TeamContext::class);
    $was = $context->team();
    $context->forget();

    /** @var SignalDefinition $preset */
    $preset = SignalDefinition::query()->withoutGlobalScopes()->create(array_merge([
        'team_id' => null,
        'created_by' => null,
        'key' => 'library_type',
        'name' => 'Library type',
        'description' => 'What the library says this finds.',
        'category' => 'growth',
        'icon' => 'trending-up',
        'query_templates' => ['"{company}" funding'],
        'markets' => ['uk'],
        'is_active' => true,
        'version' => 1,
        'preset_key' => null,
        'preset_version' => null,
    ], $attributes));

    if ($was !== null) {
        $context->set($was);
    }

    return $preset;
}

function submit(Team $team, $actor, SignalDefinition $definition, ?string $note = null): SignalDefinitionSubmission
{
    return app(SubmitSignalDefinitionAction::class)->handle($team, $actor, $definition, $note);
}

// --- A team offers (task 4) ----------------------------------------------------------------------

it('records an offer without touching the type', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team, ['name' => 'Series B rounds']);

    $submission = submit($team, $member, $definition, '  This one has found us   three deals.  ');

    expect($submission->status)->toBe(SignalSubmissionStatus::Pending)
        // Prose, deliberately: one person's words to another, squished but not translated.
        ->and($submission->note)->toBe('This one has found us three deals.')
        ->and((int) $submission->team_id)->toBe((int) $team->getKey())
        ->and((int) $submission->submitted_by)->toBe((int) $member->getKey());

    // Nothing about their own type changed. It keeps running.
    expect($definition->fresh()->name)->toBe('Series B rounds')
        ->and($definition->fresh()->is_active)->toBeTrue()
        ->and($definition->fresh()->team_id)->toBe($team->getKey());
});

it('refuses to offer the library its own preset back', function (): void {
    [$member, $team] = Billing::team();
    $preset = platformPreset();

    // A platform row *is* the library. Belt to the braces of a route that is team-scoped anyway.
    expect(fn () => submit($team, $member, $preset))->toThrow(SignalsException::class);
});

it('lets a team offer an improved clone', function (): void {
    [$member, $team] = Billing::team();
    platformPreset();
    $clone = ownType($team, [
        'key' => 'library_type_mine',
        'preset_key' => 'library_type',
        'preset_version' => 1,
        'query_templates' => ['"{company}" raises', '"{company}" closes round'],
    ]);

    // Deliberately allowed: a team who spent three months improving a preset's templates is the
    // best source of a better one, and an operator folds it back in by naming the preset's key.
    expect(submit($team, $member, $clone)->status)->toBe(SignalSubmissionStatus::Pending);
});

it('refuses to offer a type with no searches', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team, ['query_templates' => []]);

    expect(fn () => submit($team, $member, $definition))->toThrow(SignalsException::class);
});

it('refuses a second open offer for the same type', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team);

    submit($team, $member, $definition);

    expect(fn () => submit($team, $member, $definition))->toThrow(SignalsException::class);
});

it('lets a team offer the same type again after it was turned down', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();
    $definition = ownType($team);

    $first = submit($team, $member, $definition);
    app(PromoteSignalDefinitionAction::class)->reject($first, $operator, 'Too narrow for the library.');

    $second = submit($team, $member, $definition, 'Broadened the searches.');

    expect($second->status)->toBe(SignalSubmissionStatus::Pending)
        // The settled row is replaced rather than kept beside the new one, so the queue shows one
        // question per type.
        ->and(SignalDefinitionSubmission::query()
            ->withoutGlobalScopes()
            ->where('signal_definition_id', $definition->getKey())
            ->count())->toBe(1);
});

it('lets a team take an offer back, and keeps the record that they asked', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team);

    $submission = submit($team, $member, $definition);

    app(SubmitSignalDefinitionAction::class)->withdraw($team, $member, $submission);

    expect($submission->fresh()->status)->toBe(SignalSubmissionStatus::Withdrawn)
        ->and($submission->fresh()->reviewed_at)->not->toBeNull();
});

// --- An operator curates (task 4) ----------------------------------------------------------------

it('promotes a submission into a new preset, leaving the team their own type', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();
    $definition = ownType($team, ['name' => 'Series B rounds', 'key' => 'series_b_rounds']);

    $submission = submit($team, $member, $definition);

    $preset = app(PromoteSignalDefinitionAction::class)
        ->promote($submission, $operator, 'Lovely. Added.');

    expect($preset->team_id)->toBeNull()
        ->and($preset->created_by)->toBeNull()
        // Not a clone of anything.
        ->and($preset->preset_key)->toBeNull()
        ->and($preset->preset_version)->toBeNull()
        ->and($preset->version)->toBe(1)
        ->and($preset->name)->toBe('Series B rounds')
        ->and($preset->key)->toBe('series_b_rounds');

    // The team's row is the one they had, unchanged. Promotion copies; it does not move.
    expect($definition->fresh()->team_id)->toBe($team->getKey())
        ->and($definition->fresh()->getKey())->not->toBe($preset->getKey());

    expect($submission->fresh()->status)->toBe(SignalSubmissionStatus::Promoted)
        ->and($submission->fresh()->review_note)->toBe('Lovely. Added.')
        ->and((int) $submission->fresh()->preset_id)->toBe((int) $preset->getKey());
});

it('never carries a team configuration of a source into the library', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();

    // A feed URL and a webhook identity are one tenant's integration, not a template anybody else
    // could run.
    $definition = ownType($team, [
        'source_keys' => ['news'],
        'source_config' => ['feed' => ['url' => 'https://example.test/one-team.xml']],
    ]);

    $preset = app(PromoteSignalDefinitionAction::class)
        ->promote(submit($team, $member, $definition), $operator, 'In.');

    expect($preset->source_config)->toBeEmpty()
        ->and($preset->source_keys)->toBe(['news']);
});

it('folds a submission into an existing preset by bumping its version, and touches no clone', function (): void {
    [$member, $team] = Billing::team();
    [$otherMember, $otherTeam] = Billing::team();
    $operator = Admin::operator();

    $preset = platformPreset(['query_templates' => ['"{company}" funding']]);

    // Another team is already running a copy at version 1.
    $clone = app(TeamContext::class)->run($otherTeam, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
        'preset_version' => 1,
        'query_templates' => ['"{company}" funding'],
        'name' => 'What we call it',
    ]));

    $definition = ownType($team, ['query_templates' => ['"{company}" raises', '"{company}" closes round']]);

    $saved = app(PromoteSignalDefinitionAction::class)->promote(
        submit($team, $member, $definition),
        $operator,
        'Better searches than ours.',
        'library_type',
    );

    expect((int) $saved->getKey())->toBe((int) $preset->getKey())
        ->and($saved->version)->toBe(2)
        ->and($saved->query_templates)->toBe(['"{company}" raises', '"{company}" closes round']);

    // **The assertion this whole design exists for.** The other team keeps running exactly what
    // they chose, under the name they gave it. They are offered the change; nothing was applied.
    expect($clone->fresh()->query_templates)->toBe(['"{company}" funding'])
        ->and($clone->fresh()->name)->toBe('What we call it')
        ->and($clone->fresh()->preset_version)->toBe(1);

    expect($otherMember)->not->toBeNull();
});

it('never silently overwrites a preset when a submission key happens to collide', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();

    // A key the seeded library does not use, so the only collision under test is the one the
    // submission creates.
    $existing = platformPreset(['key' => 'bespoke_event', 'name' => 'Bespoke events']);
    $definition = ownType($team, ['key' => 'bespoke_event', 'name' => 'Takeovers']);

    // No key named by the operator: two teams choosing the same obvious name must not fold the
    // library together.
    $preset = app(PromoteSignalDefinitionAction::class)
        ->promote(submit($team, $member, $definition), $operator, 'In.');

    expect($preset->key)->toBe('bespoke_event_2')
        ->and($existing->fresh()->name)->toBe('Bespoke events')
        ->and($existing->fresh()->version)->toBe(1);
});

it('refuses to answer a submission twice', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();
    $submission = submit($team, $member, ownType($team));

    app(PromoteSignalDefinitionAction::class)->reject($submission, $operator, 'Not this time.');

    expect(fn () => app(PromoteSignalDefinitionAction::class)->promote($submission->fresh(), $operator, 'Actually yes.'))
        ->toThrow(SignalsException::class);
});

it('refuses to promote a type the team has since deleted', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();
    $definition = ownType($team);
    $submission = submit($team, $member, $definition);

    app(TeamContext::class)->run($team, fn () => $definition->delete());

    expect(fn () => app(PromoteSignalDefinitionAction::class)->promote($submission->fresh(), $operator, 'In.'))
        ->toThrow(SignalsException::class);
});

// --- The preset editor (task 4) -------------------------------------------------------------------

it('bumps the version on an edit but not on withdrawing the offer', function (): void {
    $operator = Admin::operator();
    $preset = platformPreset();
    $action = app(SavePlatformPresetAction::class);

    $edited = $action->update($operator, $preset, new SignalDefinitionData(
        name: 'Renamed',
        description: $preset->description,
        queryTemplates: $preset->query_templates,
    ));

    expect($edited->version)->toBe(2)
        ->and($edited->name)->toBe('Renamed');

    // Whether the library advertises a type is not a change to the type, so every clone's
    // "you are behind" state is left alone.
    $hidden = $action->setActive($operator, $edited->fresh(), false);

    expect($hidden->is_active)->toBeFalse()
        ->and($hidden->version)->toBe(2);
});

it('refuses to edit a team own type through the library', function (): void {
    [, $team] = Billing::team();
    $operator = Admin::operator();
    $theirs = ownType($team);

    expect(fn () => app(SavePlatformPresetAction::class)->setActive($operator, $theirs, false))
        ->toThrow(SignalsException::class);
});

it('refuses a preset key that is already taken', function (): void {
    $operator = Admin::operator();
    $preset = platformPreset(['key' => 'taken']);

    expect(fn () => app(SavePlatformPresetAction::class)->create(
        $operator,
        new SignalDefinitionData(name: 'Another', description: 'Also this.', queryTemplates: ['"{company}" news']),
        'taken',
    ))->toThrow(SignalsException::class);

    expect($preset->fresh()->name)->toBe('Library type');
});

// --- The operator's two questions (task 4, ADR 0016's rule) --------------------------------------

it('counts the copies of a preset and how many are behind, without naming a team', function (): void {
    [, $behindTeam] = Billing::team();
    [, $levelTeam] = Billing::team();

    $preset = platformPreset(['version' => 3]);

    foreach ([[$behindTeam, 1], [$levelTeam, 3]] as [$team, $version]) {
        app(TeamContext::class)->run($team, fn () => SignalDefinition::factory()->create([
            'team_id' => $team->getKey(),
            'key' => 'library_type',
            'preset_key' => 'library_type',
            'preset_version' => $version,
        ]));
    }

    $row = collect(app(SignalLibrary::class)->presets())->firstWhere('id', (int) $preset->getKey());

    expect($row['clones'])->toBe(2)
        ->and($row['clones_behind'])->toBe(1);
});

it('shows the queue with the type performance and not its author', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team, ['name' => 'Series B rounds']);

    submit($team, $member, $definition, 'Three deals from this.');

    $queue = app(SignalLibrary::class)->queue();

    expect($queue)->toHaveCount(1)
        ->and($queue[0]['name'])->toBe('Series B rounds')
        ->and($queue[0]['note'])->toBe('Three deals from this.')
        ->and($queue[0]['performance'])->toHaveKeys(['found', 'kept', 'converted'])
        ->and($queue[0]['is_available'])->toBeTrue()
        // The decision, not an omission: an operator chooses on the evidence.
        ->and($queue[0])->not->toHaveKey('team_id');
});

it('tells the operator when the type behind a waiting offer has gone', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team);

    submit($team, $member, $definition);
    app(TeamContext::class)->run($team, fn () => $definition->delete());

    expect(app(SignalLibrary::class)->queue()[0]['is_available'])->toBeFalse();
});

// --- The panel's door (task 4) --------------------------------------------------------------------

it('keeps the library screen behind the operations ability', function (): void {
    $operator = Admin::operator(AdminRole::ReadOnly);

    $this->actingAs($operator)->get('/admin/signal-library')->assertForbidden();
});

it('shows the library to an operator who may manage operations', function (): void {
    $operator = Admin::operator();
    platformPreset();

    $this->actingAs($operator)
        ->get('/admin/signal-library')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/signals/Library')
            // Not a count: the library ships with the seeded presets and this is one more.
            ->has('presets')
            ->has('queue')
            ->has('options.sources')
        );
});

it('keeps an ordinary member out of the library screen entirely', function (): void {
    [$member] = Billing::team();

    // 404, not 403: `EnsurePlatformAdmin` does not confirm that the panel is there.
    $this->actingAs($member)->get('/admin/signal-library')->assertNotFound();
});

// --- "Your preset has moved on" (task 5) ----------------------------------------------------------

it('offers only the fields that actually differ, and never a team own use of the type', function (): void {
    [, $team] = Billing::team();

    platformPreset([
        'version' => 2,
        'name' => 'Library name',
        'query_templates' => ['"{company}" funding', '"{company}" raises'],
    ]);

    $clone = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
        'preset_version' => 1,
        'name' => 'Library name',
        'query_templates' => ['"{company}" funding'],
        // A statement about how this team runs it, not about what the event is. Never offered.
        'is_active' => false,
        'source_config' => ['feed' => ['url' => 'https://example.test/mine.xml']],
    ]));

    $offers = app(PresetUpdateDiffer::class)->forTeam($team);
    $fields = array_column($offers[(int) $clone->getKey()]['changes'], 'field');

    expect($offers)->toHaveKey((int) $clone->getKey())
        ->and($offers[(int) $clone->getKey()]['preset_version'])->toBe(2)
        ->and($offers[(int) $clone->getKey()]['from_version'])->toBe(1)
        // The templates differ; the name does not; `is_active` and `source_config` are not offerable.
        ->and($fields)->toContain('query_templates')
        ->and($fields)->not->toContain('name')
        ->and($fields)->not->toContain('is_active')
        ->and($fields)->not->toContain('source_config');
});

it('says nothing when the clone is level with its preset', function (): void {
    [, $team] = Billing::team();
    platformPreset(['version' => 2]);

    app(TeamContext::class)->run($team, fn () => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
        'preset_version' => 2,
        'query_templates' => ['something entirely of my own'],
    ]));

    // A team that has edited their copy has not fallen behind; they have made it theirs.
    expect(app(PresetUpdateDiffer::class)->forTeam($team))->toBe([]);
});

it('does not report a change just because a json column key order differs', function (): void {
    [, $team] = Billing::team();

    platformPreset([
        'version' => 2,
        'thresholds' => ['min_confidence' => 60, 'min_relevance' => 40],
        'query_templates' => ['a', 'b'],
    ]);

    app(TeamContext::class)->run($team, fn () => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
        'preset_version' => 1,
        'thresholds' => ['min_relevance' => 40, 'min_confidence' => 60],
        'query_templates' => ['b', 'a'],
        'name' => 'Library type',
        'description' => 'What the library says this finds.',
        'markets' => ['uk'],
        // Level with the preset on every other diffable field, so the only thing this test is
        // asking about is key order.
        'match_keywords' => [],
        'classification_rules' => [],
        'suggested_approach_hint' => null,
        'category' => 'growth',
        'icon' => 'trending-up',
        'query_templates_i18n' => null,
    ]));

    // A panel that cries wolf is a panel nobody reads.
    expect(app(PresetUpdateDiffer::class)->forTeam($team))->toBe([]);
});

it('takes the ticked fields and leaves the rest alone', function (): void {
    [$member, $team] = Billing::team();

    platformPreset([
        'version' => 2,
        'name' => 'Library name',
        'description' => 'The library explanation.',
        'query_templates' => ['"{company}" raises'],
    ]);

    $clone = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
        'preset_version' => 1,
        'name' => 'What we call it',
        'description' => 'Our explanation.',
        'query_templates' => ['"{company}" funding'],
        'version' => 4,
    ]));

    app(TeamContext::class)->run($team, fn () => app(AdoptPresetChangesAction::class)
        ->handle($team, $member, $clone, ['query_templates']));

    $fresh = $clone->fresh();

    expect($fresh->query_templates)->toBe(['"{company}" raises'])
        // Their name and their words are theirs. Taking the templates is a different decision.
        ->and($fresh->name)->toBe('What we call it')
        ->and($fresh->description)->toBe('Our explanation.')
        ->and($fresh->preset_version)->toBe(2)
        // An ordinary definition edit: a signal records which version classified it.
        ->and($fresh->version)->toBe(5);
});

it('records an answer of no, and stops asking', function (): void {
    [$member, $team] = Billing::team();

    platformPreset(['version' => 2, 'query_templates' => ['"{company}" raises']]);

    $clone = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
        'preset_version' => 1,
        'query_templates' => ['"{company}" funding'],
        'version' => 4,
    ]));

    app(TeamContext::class)->run($team, fn () => app(AdoptPresetChangesAction::class)
        ->handle($team, $member, $clone, []));

    $fresh = $clone->fresh();

    expect($fresh->query_templates)->toBe(['"{company}" funding'])
        ->and($fresh->preset_version)->toBe(2)
        // Acknowledging an offer is not an edit to the type.
        ->and($fresh->version)->toBe(4)
        ->and(app(PresetUpdateDiffer::class)->forTeam($team))->toBe([]);
});

it('drops a ticked field that has not actually changed', function (): void {
    [$member, $team] = Billing::team();

    platformPreset(['version' => 2, 'name' => 'Same name', 'query_templates' => ['"{company}" raises']]);

    $clone = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $team->getKey(),
        'key' => 'library_type',
        'preset_key' => 'library_type',
        'preset_version' => 1,
        'name' => 'Same name',
        'query_templates' => ['"{company}" funding'],
    ]));

    app(TeamContext::class)->run($team, fn () => app(AdoptPresetChangesAction::class)
        ->handle($team, $member, $clone, ['name', 'query_templates', 'is_active', 'team_id']));

    // A stale form cannot copy a value over the top of one the team edited in the meantime, and a
    // request cannot name a column outside `PresetUpdateDiffer::FIELDS`.
    expect($clone->fresh()->name)->toBe('Same name')
        ->and($clone->fresh()->query_templates)->toBe(['"{company}" raises']);
});

it('refuses to adopt into a type that is not a clone', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team);

    expect(fn () => app(TeamContext::class)->run($team, fn () => app(AdoptPresetChangesAction::class)
        ->handle($team, $member, $definition, [])))
        ->toThrow(SignalsException::class);
});

// --- The team's own screen (tasks 2, 4, 5, 7) ----------------------------------------------------

it('sends the ROI figures, the offers and the submissions to the library screen', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team);

    submit($team, $member, $definition, 'Worth a look.');

    $this->actingAs($member)
        ->get('/signals/types')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('signals/Types')
            ->has('roi')
            ->has('roi_minimum')
            ->has('preset_offers')
            ->has('preset_fields')
            ->has('submissions', 1)
            // Translation keys, not finished strings.
            ->where('submissions.0.status_label', 'Waiting for review')
            ->where('submissions.0.is_open', true)
        );
});

it('offers this team market pack before the market-agnostic shelf', function (): void {
    [$member, $team] = Billing::team();

    Team::query()->whereKey($team->getKey())->update(['market' => 'uk']);

    // Catalogue order would put the market-agnostic preset first; a library is read top-down.
    platformPreset(['key' => 'everyones', 'name' => 'For everyone', 'markets' => []]);
    platformPreset(['key' => 'uk_only', 'name' => 'For the UK', 'markets' => ['uk']]);

    $response = $this->actingAs($member)->get('/signals/types')->assertOk();

    /** @var list<array<string, mixed>> $presets */
    $presets = $response->viewData('page')['props']['presets'];
    $markets = array_map(static fn (array $preset): array => $preset['markets'] ?? [], $presets);

    // Everything written for this market comes before everything written for everybody, and the
    // library's own order survives inside each half.
    $firstAgnostic = null;
    $lastForUk = null;

    foreach ($markets as $index => $preset) {
        if (in_array('uk', $preset, true)) {
            $lastForUk = $index;
        } elseif ($firstAgnostic === null) {
            $firstAgnostic = $index;
        }
    }

    expect($firstAgnostic)->not->toBeNull()
        ->and($lastForUk)->not->toBeNull()
        ->and($lastForUk)->toBeLessThan($firstAgnostic);
});

it('refuses an offer from a member who cannot manage the team types', function (): void {
    [$owner, $team] = Billing::team();
    $definition = ownType($team);
    $viewer = createUserWithTeam();
    app(TeamMembership::class)->add($viewer, $team, TeamRole::ReadOnly);
    $viewer->switchTeam($team);

    $this->actingAs($viewer)
        ->post('/signals/types/'.$definition->getKey().'/submit')
        ->assertForbidden();

    expect($owner)->not->toBeNull();
});

it('keeps one team out of another team submissions', function (): void {
    [$member, $team] = Billing::team();
    [$other, $otherTeam] = Billing::team();

    $submission = submit($team, $member, ownType($team));

    $this->actingAs($other)
        ->delete('/signals/types/submissions/'.$submission->getKey())
        ->assertNotFound();

    expect($submission->fresh()->status)->toBe(SignalSubmissionStatus::Pending)
        ->and($otherTeam)->not->toBeNull();
});

// --- What the review found (S15) ------------------------------------------------------------------

/*
 * Five findings, each with the test that was missing when it was found. They are grouped because
 * they share a theme worth naming: **every one of them was invisible in the happy path.** A preset
 * written to the wrong owner, a withdrawn key that 500s, one tenant's totals under another's
 * submission — none of them throws, and none is visible on a screen built by somebody who only ever
 * had one team and never deleted anything.
 */

it('writes a preset that belongs to nobody even with a team active', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();

    // The context a test, a tinker session or a queued job leaves behind. `BelongsToTeam` fills a
    // null `team_id` from it on create, so `'team_id' => null` is not enough on its own: the preset
    // would silently become **this team's** own type, with the right columns and the wrong owner,
    // unique against the wrong scope, and invisible to every reader of the library.
    app(TeamContext::class)->set($team);

    $preset = app(SavePlatformPresetAction::class)->create(
        $operator,
        new SignalDefinitionData(
            name: 'Built in a context',
            description: 'And still belongs to nobody.',
            queryTemplates: ['"{company}" news'],
        ),
        'built_in_a_context',
    );

    expect($preset->team_id)->toBeNull()
        ->and((int) $preset->scope_id)->toBe(0)
        // And it is reachable through the one door that reads presets, which is the proof that
        // matters: a row with the right columns that nothing can find is not a preset.
        ->and(app(PlatformPresetReader::class)->byKey('built_in_a_context'))->not->toBeNull();

    expect($member)->not->toBeNull();
});

it('promotes a submission even with another team active', function (): void {
    [$member, $team] = Billing::team();
    [, $other] = Billing::team();
    $operator = Admin::operator();
    $definition = ownType($team, ['key' => 'promoted_in_context']);
    $submission = submit($team, $member, $definition);

    // The submitted type is read back through a team-scoped relation, so a promote running with
    // *any* team in context found nothing and reported the offer as out of date.
    app(TeamContext::class)->set($other);

    $preset = app(PromoteSignalDefinitionAction::class)->promote($submission, $operator, 'In.');

    expect($preset->team_id)->toBeNull()
        ->and($preset->key)->toBe('promoted_in_context');
});

it('frees a withdrawn preset key rather than colliding with it', function (): void {
    $operator = Admin::operator();
    $preset = platformPreset(['key' => 'withdrawn_key']);
    $action = app(SavePlatformPresetAction::class);

    $action->delete($operator, $preset);

    // The unique index does **not** soft-delete, so the withdrawn row still holds its key. The
    // ordinary read cannot see it, which is right for a read and wrong for this question: asking
    // `byKey` whether the key was free got "yes" and then a 500 from the database.
    expect(fn () => $action->create(
        $operator,
        new SignalDefinitionData(name: 'Same key', description: 'Again.', queryTemplates: ['"{company}" news']),
        'withdrawn_key',
    ))->toThrow(SignalsException::class);

    // And it is gone from the operator's list rather than sitting there to be toggled again.
    expect(collect(app(SignalLibrary::class)->presets())->firstWhere('key', 'withdrawn_key'))->toBeNull();
});

it('brings a withdrawn preset back when an operator names its key on a promotion', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();
    $preset = platformPreset(['key' => 'revived', 'name' => 'Old name']);

    app(SavePlatformPresetAction::class)->delete($operator, $preset);

    $definition = ownType($team, ['key' => 'mine_revived', 'name' => 'New name']);

    // Naming a key means "this *is* that preset", so a withdrawn one is restored carrying the new
    // content rather than refused or quietly duplicated.
    $promoted = app(PromoteSignalDefinitionAction::class)
        ->promote(submit($team, $member, $definition), $operator, 'Back in.', 'revived');

    expect((int) $promoted->getKey())->toBe((int) $preset->getKey())
        ->and($promoted->name)->toBe('New name')
        ->and($promoted->version)->toBe(2)
        ->and($promoted->trashed())->toBeFalse()
        ->and($promoted->is_active)->toBeTrue();
});

it('shows the submitting team own figures, not every tenant totals for the same key', function (): void {
    [$member, $team] = Billing::team();
    [, $other] = Billing::team();

    // Both teams run a type under the same `event_key`, which is the ordinary case: a clone carries
    // the preset's own key. Aggregating by key alone put the other team's nine signals under this
    // team's submission and labelled them "how this type has done for the team that wrote it".
    $mine = ownType($team, ['key' => 'shared_key']);

    app(TeamContext::class)->run($team, fn () => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'event_key' => 'shared_key',
        'status' => SignalStatus::New->value,
    ]));

    app(TeamContext::class)->run($other, function () use ($other): void {
        SignalDefinition::factory()->create(['team_id' => $other->getKey(), 'key' => 'shared_key']);

        for ($i = 0; $i < 9; $i++) {
            Signal::factory()->create([
                'team_id' => $other->getKey(),
                'event_key' => 'shared_key',
                'status' => SignalStatus::New->value,
            ]);
        }
    });

    submit($team, $member, $mine);

    // Asserted with **another team in context**, deliberately: the queue's eager load of the
    // definition is a second query and re-applies the tenancy scope unless it is told not to, which
    // showed an operator a queue of nameless rows with no evidence at all.
    $queue = app(SignalLibrary::class)->queue();

    expect($queue)->toHaveCount(1)
        ->and($queue[0]['performance']['found'])->toBe(1);
});

it('carries a team improved rules and keywords into the library, not only its searches', function (): void {
    [$member, $team] = Billing::team();
    $operator = Admin::operator();

    // `PresetUpdateDiffer::FIELDS` offers all three to a clone, so a promotion that dropped them
    // threw away the half of the work that does the most — a funding tracker is kept out by an
    // exclusion, not by a template — and then showed every clone a diff against a preset the
    // submission had not actually changed. The two lists have to agree.
    $definition = ownType($team, [
        'key' => 'rich_type',
        'match_keywords' => ['raises', 'series b'],
        'classification_rules' => ['includes' => ['a named company'], 'excludes' => ['weekly round-ups']],
        'suggested_approach_hint' => 'Ask the introducer first.',
    ]);

    $preset = app(PromoteSignalDefinitionAction::class)
        ->promote(submit($team, $member, $definition), $operator, 'In.');

    expect($preset->match_keywords)->toBe(['raises', 'series b'])
        ->and($preset->classification_rules)->toBe([
            'includes' => ['a named company'],
            'excludes' => ['weekly round-ups'],
        ])
        ->and($preset->suggested_approach_hint)->toBe('Ask the introducer first.');
});

it('lets a team take back an offer for a type they have since deleted', function (): void {
    [$member, $team] = Billing::team();
    $definition = ownType($team);
    $submission = submit($team, $member, $definition);

    app(TeamContext::class)->run($team, fn () => $definition->delete());

    // Authorising through the definition 404'd here, which left the offer Pending in the
    // operator's queue for ever with nobody able to withdraw it. The team is what the policy
    // question is actually about.
    $this->actingAs($member)
        ->delete('/signals/types/submissions/'.$submission->getKey())
        ->assertRedirect();

    expect($submission->fresh()->status)->toBe(SignalSubmissionStatus::Withdrawn);
});

it('refuses to write a submission naming a team the type does not belong to', function (): void {
    [$member, $team] = Billing::team();
    [, $other] = Billing::team();
    $theirs = ownType($other, ['key' => 'not_mine']);

    // Cannot happen through either controller, which is exactly when a one-line invariant is
    // cheap: an Action that took a team and somebody else's row would be a tenancy bug with
    // nothing in its own file to stop it.
    expect(fn () => submit($team, $member, $theirs))->toThrow(SignalsException::class);
});

it('refuses an API offer against another team type', function (): void {
    [, $team] = Billing::team();
    [$other] = Billing::team();
    $mine = ownType($team, ['key' => 'api_not_yours']);

    Sanctum::actingAs($other, ['*']);

    $this->postJson('/api/v1/signal-definitions/'.$mine->getKey().'/submit')->assertNotFound();
    $this->patchJson('/api/v1/signal-definitions/'.$mine->getKey().'/adopt-preset', ['fields' => []])
        ->assertNotFound();
});
