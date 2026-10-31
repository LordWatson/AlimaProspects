<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OutreachFixtures as Fixtures;

/*
 * The journey the mobile app (Phase 18) makes, in order, over one token — sign in, read the
 * dashboard, open a lead, move it, write a note, set a task, draft a message, log a call, register
 * for push, clear the bell.
 *
 * A contract test rather than a feature test: every step here is covered in its own module's suite
 * already. What this pins down is that they compose — that one token with the scopes the app asks
 * for can do all of it, that each step hands back what the next one needs, and that nothing in the
 * chain demands a session cookie. That is the thing a mobile build discovers the hard way, and the
 * thing an endpoint-by-endpoint suite never checks.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
    Mail::fake();
    config()->set('outreach.sending_enabled', true);
    config()->set('outreach.from.address', 'outreach@alima.test');
});

it('carries the mobile app from sign-in to a logged call on one token', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    // 1. Sign in. The app asks for the scopes its screens need and nothing more.
    $scopes = [
        TokenAbility::TeamRead->value,
        TokenAbility::ReportsRead->value,
        TokenAbility::LeadsRead->value,
        TokenAbility::LeadsWrite->value,
        TokenAbility::OutreachRead->value,
        TokenAbility::OutreachWrite->value,
        TokenAbility::NotificationsRead->value,
        TokenAbility::NotificationsWrite->value,
    ];

    $signIn = $this->postJson(route('api.v1.auth.token.issue'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Nadia’s iPhone',
        'abilities' => $scopes,
    ])->assertCreated()->assertApiVersion();

    $token = (string) $signIn->json('token');

    expect($token)->not->toBe('');
    expect($signIn->json('user.id'))->toBe($user->getKey());

    // 2. Whose team am I in, and has the team finished setting up?
    $this->withToken($token)
        ->getJson(route('api.v1.teams.current'))
        ->assertOk()
        ->assertJsonPath('data.id', $team->getKey());

    $this->withToken($token)->getJson(route('api.v1.onboarding.show'))->assertOk();

    // 3. The home screen: the same figures the web dashboard shows, for the same window.
    $this->withToken($token)
        ->getJson(route('api.v1.reports.dashboard', ['range' => 'last_30_days']))
        ->assertOk()
        ->assertJsonStructure(['data' => ['kpis', 'funnel', 'trend', 'has_data', 'range_from', 'range_to']]);

    // 4. The list, then the lead.
    $list = $this->withToken($token)
        ->getJson(route('api.v1.leads.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 10);

    expect(collect($list->json('data'))->pluck('id'))->toContain($lead->getKey());

    // The detail endpoint answers with the lead, its score and its timeline in one payload, so
    // the phone's detail screen is one request rather than three.
    $this->withToken($token)
        ->getJson(route('api.v1.leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.lead.id', $lead->getKey())
        ->assertJsonStructure(['data' => ['lead', 'score', 'activities']]);

    // 5. The stage picker reads the board, then moves the lead into a column off it. The ids have
    //    to come from the API: a phone cannot know a team's stages.
    $stages = $this->withToken($token)
        ->getJson(route('api.v1.leads.stages'))
        ->assertOk()
        ->json('data');

    // The default column is stored as *no* column (see MoveLeadStageAction), so move the lead
    // somewhere that is visibly a move.
    $target = collect($stages)->firstWhere('is_default', false);

    expect($target)->not->toBeNull();

    $this->withToken($token)
        ->putJson(route('api.v1.leads.stage.move', ['lead' => $lead->getKey()]), [
            'pipeline_stage_id' => $target['id'],
        ])
        ->assertOk()
        ->assertJsonPath('data.stage.id', $target['id']);

    // 6. A note and a task, which is most of what a member does from a phone.
    $this->withToken($token)
        ->postJson(route('api.v1.leads.notes.store', ['lead' => $lead->getKey()]), [
            'body' => 'Spoke to Dana at the trade stand — wants a call next week.',
        ])
        ->assertCreated();

    $task = $this->withToken($token)
        ->postJson(route('api.v1.leads.tasks.store', ['lead' => $lead->getKey()]), [
            'title' => 'Call Dana back',
            'due_at' => now()->addDays(3)->toDateString(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($token)
        ->putJson(route('api.v1.leads.tasks.complete', ['task' => $task]), ['done' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'done');

    // 7. Which channels are open, before offering a compose screen at all.
    $decisions = $this->withToken($token)
        ->getJson(route('api.v1.outreach.decisions', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->json('data');

    expect(collect($decisions)->firstWhere('channel', 'email')['allowed'])->toBeTrue();

    // 8. An AI draft, then a logged call.
    Fixtures::fakeAi()->queueStructured(Fixtures::aiDraft());

    $this->withToken($token)
        ->postJson(route('api.v1.outreach.draft', ['lead' => $lead->getKey()]), [
            'channel' => 'email',
            'tone' => 'warm',
            'locale' => 'en',
            'lead_contact_id' => $contact->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $this->withToken($token)
        ->postJson(route('api.v1.outreach.attempts.store', ['lead' => $lead->getKey()]), [
            'channel' => 'call',
            'outcome' => 'interested',
            'lead_contact_id' => $contact->getKey(),
            'notes' => 'Ten minutes; sending the deck.',
        ])
        ->assertCreated();

    // 9. Push, and the bell.
    $this->withToken($token)
        ->postJson(route('api.v1.push-devices.store'), [
            'token' => str_repeat('fcm-token-for-nadias-iphone-', 2),
            'platform' => 'ios',
        ])
        ->assertCreated();

    $this->withToken($token)
        ->getJson(route('api.v1.notifications.unread'))
        ->assertOk()
        ->assertJsonStructure(['data' => ['unread']]);

    $this->withToken($token)->postJson(route('api.v1.notifications.read-all'))->assertOk();

    // 10. Signing out on the handset takes the credential with it.
    $this->withToken($token)->deleteJson(route('api.v1.auth.token.current'))->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('refuses the journey to a token that was not scoped for it', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team);

    // A read-only companion app: it can show the pipeline and must not be able to change it.
    $signIn = $this->postJson(route('api.v1.auth.token.issue'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Read-only dashboard',
        'abilities' => [TokenAbility::LeadsRead->value, TokenAbility::ReportsRead->value],
    ])->assertCreated();

    $token = (string) $signIn->json('token');

    $this->withToken($token)->getJson(route('api.v1.leads.index'))->assertOk();

    $this->withToken($token)
        ->postJson(route('api.v1.leads.notes.store', ['lead' => $lead->getKey()]), ['body' => 'No.'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_token_ability');
});
