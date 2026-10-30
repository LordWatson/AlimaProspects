<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutreachFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * A team's message briefs (Phase 11): the screen, the uniqueness, the one-default-per-channel rule,
 * and who may manage them.
 */
function templatePayload(array $overrides = []): array
{
    return [
        'name' => 'First touch',
        'channel' => 'email',
        'tone' => 'direct',
        'locale' => 'en',
        'subject' => 'A quick question',
        'body' => 'Lead with their onboarding time. Ask for 15 minutes.',
        ...$overrides,
    ];
}

it('shows the briefs screen with the channel, tone and language options', function (): void {
    [$user, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, fn () => OutreachTemplate::factory()->create([
        'team_id' => $team->getKey(),
        'name' => 'First touch',
    ]));

    $this->actingAs($user)
        ->get(route('outreach.templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('outreach/Templates')
            ->has('templates', 1)
            ->where('templates.0.name', 'First touch')
            ->has('options.channels', count(OutreachChannel::cases()))
            ->where('can.manage', true));
});

it('creates a brief', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->post(route('outreach.templates.store'), templatePayload())
        ->assertRedirect();

    $template = OutreachTemplate::withoutGlobalScopes()->sole();

    expect($template->name)->toBe('First touch')
        ->and($template->team_id)->toBe($team->getKey())
        ->and($template->created_by)->toBe($user->getKey())
        ->and($template->is_default)->toBeFalse();
});

it('drops a subject line on a channel that has none', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->post(route('outreach.templates.store'), templatePayload([
            'channel' => 'call',
            'subject' => 'Typed before switching channel',
        ]))
        ->assertRedirect();

    // Keeping it would leave a field on the row nothing reads, which a later channel change would
    // silently resurrect.
    expect(OutreachTemplate::withoutGlobalScopes()->sole()->subject)->toBeNull();
});

it('keeps at most one default per channel, and leaves other channels alone', function (): void {
    [$user, $team] = Fixtures::team();

    $existingEmail = app(TeamContext::class)->run($team, fn (): OutreachTemplate => OutreachTemplate::factory()
        ->isDefault()
        ->create(['team_id' => $team->getKey(), 'name' => 'Old email default']));

    $existingLinkedIn = app(TeamContext::class)->run($team, fn (): OutreachTemplate => OutreachTemplate::factory()
        ->isDefault()
        ->channel(OutreachChannel::LinkedIn)
        ->create(['team_id' => $team->getKey(), 'name' => 'LinkedIn default']));

    $this->actingAs($user)
        ->post(route('outreach.templates.store'), templatePayload(['is_default' => true]))
        ->assertRedirect();

    expect($existingEmail->fresh()->is_default)->toBeFalse()
        // A different channel's default is a different question.
        ->and($existingLinkedIn->fresh()->is_default)->toBeTrue();
});

it('refuses a duplicate name within the team but allows it across teams', function (): void {
    [$user, $team] = Fixtures::team();
    [$otherUser] = Fixtures::team('Other Co');

    app(TeamContext::class)->run($team, fn () => OutreachTemplate::factory()->create([
        'team_id' => $team->getKey(),
        'name' => 'First touch',
    ]));

    $this->actingAs($user)
        ->from(route('outreach.templates.index'))
        ->post(route('outreach.templates.store'), templatePayload())
        ->assertSessionHasErrors('name');

    // One team naming a brief "First touch" must not stop every other team doing the same.
    $this->actingAs($otherUser)
        ->post(route('outreach.templates.store'), templatePayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('lets a brief keep its own name on an update', function (): void {
    [$user, $team] = Fixtures::team();

    $template = app(TeamContext::class)->run($team, fn (): OutreachTemplate => OutreachTemplate::factory()
        ->create(['team_id' => $team->getKey(), 'name' => 'First touch']));

    $this->actingAs($user)
        ->patch(route('outreach.templates.update', $template->getKey()), templatePayload([
            'body' => 'Changed the angle.',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($template->fresh()->body)->toBe('Changed the angle.');
});

it('keeps a message drafted from a deleted brief', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team);

    $template = app(TeamContext::class)->run($team, fn (): OutreachTemplate => OutreachTemplate::factory()
        ->create(['team_id' => $team->getKey()]));

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'outreach_template_id' => $template->getKey(),
    ]));

    $this->actingAs($user)
        ->delete(route('outreach.templates.destroy', $template->getKey()))
        ->assertRedirect();

    // A sent message is a record of what was sent; retiring the brief must not take it with it.
    expect($message->fresh())->not->toBeNull()
        ->and($message->fresh()->outreach_template_id)->toBeNull();
});

it('refuses a plain member and a read-only member — a brief shapes every message the team sends', function (): void {
    [, $team] = Fixtures::team();

    foreach ([TeamRole::Member, TeamRole::ReadOnly] as $role) {
        $member = Fixtures::member($team, $role);

        $this->actingAs($member)
            ->post(route('outreach.templates.store'), templatePayload(['name' => 'Brief '.$role->value]))
            ->assertForbidden();
    }

    expect(OutreachTemplate::withoutGlobalScopes()->count())->toBe(0);
});

it('cannot reach another team\'s brief', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team('Other Co');

    $foreign = app(TeamContext::class)->run($otherTeam, fn (): OutreachTemplate => OutreachTemplate::factory()
        ->create(['team_id' => $otherTeam->getKey()]));

    // A 404 before any policy runs: the global scope hides the row, so the request can't even
    // learn that it exists.
    $this->actingAs($user)
        ->patch(route('outreach.templates.update', $foreign->getKey()), templatePayload())
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('outreach.templates.destroy', $foreign->getKey()))
        ->assertNotFound();
});
