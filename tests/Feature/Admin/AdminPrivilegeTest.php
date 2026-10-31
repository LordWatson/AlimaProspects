<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * A prompt-registry row. The seeder that normally supplies these does not run under
 * `RefreshDatabase`, and the point of these tests is the ability check rather than the registry's
 * contents.
 */
function promptTemplate(): AiPromptTemplate
{
    return AiPromptTemplate::factory()->create(['max_tokens' => 2048]);
}

/*
 * The two privilege paths a code review found in Phase 19a, and the rules that closed them.
 *
 * Both were the same shape: an ability named for one job turning out to authorise a bigger one.
 * `tenant.write` means "fix a customer's record", and the AI prompt registry is not a customer's
 * record; `users.manage` means "help somebody back into their account", and another operator is not
 * somebody to be helped by a colleague with fewer powers.
 */

// --- Platform-wide records need a platform-wide ability ------------------------------------------

it('refuses support a write to the platform-wide prompt registry', function (): void {
    $template = promptTemplate();

    // Support has `tenant.write`, which is what the registry's write routes ask for. Without the
    // descriptor's own ability being checked on writes, a support engineer could rewrite the system
    // prompt for every tenant's next AI call from a screen meant for correcting a company name.
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->patch('/admin/resources/ai-prompt-templates/'.$template->getKey(), [
            'is_active' => '0',
            'max_tokens' => 10,
            'description' => 'Tampered.',
        ])
        ->assertForbidden();

    expect($template->refresh()->max_tokens)->not->toBe(10);
});

it('refuses support even the edit screen for it', function (): void {
    $template = promptTemplate();

    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->get('/admin/resources/ai-prompt-templates/'.$template->getKey().'/edit')
        ->assertForbidden();
});

it('lets a super admin change it', function (): void {
    $template = promptTemplate();

    $this->actingAs(Fixtures::operator(AdminRole::SuperAdmin))
        ->patch('/admin/resources/ai-prompt-templates/'.$template->getKey(), [
            'is_active' => '1',
            'max_tokens' => 4096,
            'description' => 'Adjusted deliberately.',
        ])
        ->assertRedirect();

    expect($template->refresh()->max_tokens)->toBe(4096);
});

it('keeps support out of the registry index too, but not the rest of the panel', function (): void {
    $support = Fixtures::operator(AdminRole::Support);

    $this->actingAs($support)->get('/admin/resources/ai-prompt-templates')->assertForbidden();

    // The point of the ability being per-resource rather than per-role: everything else still works.
    Fixtures::tenant();
    $this->actingAs($support)->get('/admin/resources/leads')->assertOk();
});

// --- Account operations on another operator -----------------------------------------------------

it('refuses support every account operation on a platform admin', function (): void {
    $target = Fixtures::operator(AdminRole::SuperAdmin);
    $support = Fixtures::operator(AdminRole::Support);

    // Clearing a super admin's second factor would leave `EnsureAdminTwoFactor` sending them to
    // re-enrol — a lower role taking the second lock off a higher one. Revoking their sessions and
    // tokens is a denial of service against the one person who would notice.
    foreach (
        [
            'password-reset',
            'two-factor-reset',
            'revoke-sessions',
            'revoke-tokens',
            'verify-email',
        ] as $operation
    ) {
        $response = $this->actingAs($support)
            ->post('/admin/users/'.$target->getKey().'/'.$operation);

        expect($response->getStatusCode())->toBe(302, $operation.' was not refused');
    }

    $target->refresh();

    expect($target->two_factor_confirmed_at)->not->toBeNull()
        ->and($target->isPlatformAdmin())->toBeTrue();
});

it('names the refusal rather than failing quietly', function (): void {
    $target = Fixtures::operator(AdminRole::SuperAdmin);

    $this->withoutExceptionHandling()
        ->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/users/'.$target->getKey().'/two-factor-reset');
})->throws(AdminException::class, 'only a super admin can run account operations');

it('lets a super admin help another operator back in', function (): void {
    $target = Fixtures::operator(AdminRole::Support);

    // Somebody has to be able to help an operator who has lost their phone, and it is the role that
    // already holds every other platform power.
    $this->actingAs(Fixtures::operator(AdminRole::SuperAdmin))
        ->post('/admin/users/'.$target->getKey().'/two-factor-reset')
        ->assertRedirect();

    expect($target->refresh()->two_factor_confirmed_at)->toBeNull();
});

it('lets support run the same operations on a customer', function (): void {
    [$customer] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/users/'.$customer->getKey().'/revoke-tokens')
        ->assertRedirect();

    expect(AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'admin.user.tokens_revoked')
        ->exists())->toBeTrue();
});
