<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Models\OutreachTemplate;
use Database\Factories\OutreachSuppressionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OutreachFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Cross-tenant access over the **API**, endpoint by endpoint (ADR 0006, CLAUDE.md: "every tenant
 * model needs a test proving cross-tenant access fails").
 *
 * The web screens are covered in `OutreachScreenTest` and `OutreachTemplateTest`, and the resolvers
 * are shared — but a shared resolver is not a tested contract. These pin the endpoints, because the
 * next person to add an API route will copy one of them, and the suppression routes in particular
 * resolve a model that deliberately has **no tenant global scope**: the `scope_key` filter in
 * `ResolvesOutreach::suppression()` is the whole of the scoping there, so nothing else would catch
 * it going wrong.
 */
beforeEach(function (): void {
    Mail::fake();
    config()->set('outreach.sending_enabled', true);
    config()->set('outreach.from.address', 'outreach@alima.test');
});

it('cannot read, change or delete another team\'s brief over the API', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');

    $foreign = app(TeamContext::class)->run($otherTeam, fn (): OutreachTemplate => OutreachTemplate::factory()
        ->create(['team_id' => $otherTeam->getKey(), 'body' => 'Their angle.']));

    Sanctum::actingAs($user);

    // A 404 before any policy runs: the global scope hides the row, so the request cannot even
    // learn that it exists.
    $this->getJson(route('api.v1.outreach.templates.show', $foreign->getKey()))->assertNotFound();
    $this->patchJson(route('api.v1.outreach.templates.update', $foreign->getKey()), [
        'name' => 'Renamed',
        'channel' => 'email',
        'tone' => 'direct',
        'body' => 'Changed.',
    ])->assertNotFound();
    $this->deleteJson(route('api.v1.outreach.templates.destroy', $foreign->getKey()))->assertNotFound();

    expect($foreign->fresh()->body)->toBe('Their angle.');
});

it('does not list another team\'s briefs', function (): void {
    [$user, $team] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');

    app(TeamContext::class)->run($team, fn () => OutreachTemplate::factory()->create(['team_id' => $team->getKey()]));
    app(TeamContext::class)->run($otherTeam, fn () => OutreachTemplate::factory()->create(['team_id' => $otherTeam->getKey()]));

    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.outreach.templates.index'))->assertOk()->assertJsonCount(1, 'data');
});

it('does not leak another team\'s do-not-contact list, and shows every global entry', function (): void {
    [$user, $team] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');

    OutreachSuppressionFactory::new()->forValue(SuppressionType::Email, 'stop@acme.test')->create();
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Domain, 'mine.test')
        ->forTeam((int) $team->getKey())
        ->create();
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Domain, 'theirs.test')
        ->forTeam((int) $otherTeam->getKey())
        ->create();

    Sanctum::actingAs($user);

    $previews = collect($this->getJson(route('api.v1.outreach.suppressions.index'))
        ->assertOk()
        ->json('data'))
        ->pluck('value_preview');

    // Their own entry plus the global one — and not the other team's housekeeping.
    expect($previews)->toHaveCount(2)
        ->and($previews)->toContain('mine.test')
        ->and($previews)->toContain('s***@acme.test')
        ->and($previews)->not->toContain('theirs.test');
});

it('cannot lift another team\'s do-not-contact entry over the API', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');

    $theirs = OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Email, 'theirs@elsewhere.test')
        ->forTeam((int) $otherTeam->getKey())
        ->create();

    Sanctum::actingAs($user);

    // The `scope_key` filter is the only thing standing here — this model has no tenant global
    // scope, by design, because a global opt-out must be visible to every team.
    $this->deleteJson(route('api.v1.outreach.suppressions.destroy', $theirs->getKey()))->assertNotFound();

    expect(OutreachSuppression::query()->whereKey($theirs->getKey())->exists())->toBeTrue();
});

it('cannot draft, log or read messages against another team\'s lead over the API', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');
    [$otherLead, $otherContact] = Fixtures::lead($otherTeam);

    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.outreach.draft', $otherLead->getKey()), [
        'channel' => 'linkedin',
        'tone' => 'direct',
    ])->assertNotFound();

    $this->postJson(route('api.v1.outreach.attempts.store', $otherLead->getKey()), [
        'channel' => 'call',
    ])->assertNotFound();

    // With the foreign **contact** named as well, the FormRequest's team-scoped `exists` rule
    // catches it first and the answer is a 422 rather than a 404. Both are refusals, and the two
    // layers firing in that order is the point — the validation rule is defence in depth behind
    // the team-scoped resolve, not instead of it.
    $this->postJson(route('api.v1.outreach.attempts.store', $otherLead->getKey()), [
        'channel' => 'call',
        'lead_contact_id' => $otherContact->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('lead_contact_id');

    expect(OutreachMessage::withoutGlobalScopes()->count())->toBe(0)
        ->and(ContactAttempt::withoutGlobalScopes()->count())->toBe(0);
});

it('cannot edit or delete another team\'s draft over the API', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');
    [$otherLead] = Fixtures::lead($otherTeam);

    $foreign = app(TeamContext::class)->run($otherTeam, fn (): OutreachMessage => OutreachMessage::factory()
        ->create([
            'team_id' => $otherTeam->getKey(),
            'lead_id' => $otherLead->getKey(),
            'body' => 'Their words.',
        ]));

    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.outreach.messages.update', $foreign->getKey()), [
        'body' => 'Mine now.',
    ])->assertNotFound();
    $this->deleteJson(route('api.v1.outreach.messages.destroy', $foreign->getKey()))->assertNotFound();

    expect($foreign->fresh()->body)->toBe('Their words.')
        ->and($foreign->fresh()->status)->toBe(OutreachMessageStatus::Draft);
});
