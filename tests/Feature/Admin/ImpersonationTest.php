<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\Impersonator;
use App\Modules\Core\Models\AuditLog;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Models\OutreachSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * Impersonation (Phase 19a) — the most useful thing in the panel and the most dangerous.
 *
 * The tests are written around what it must **not** be able to do, because that is where the harm
 * lives: an operator in a customer's seat emailing a prospect is a message the customer did not
 * write, from their domain, to a real person, recorded in their consent history as though they had.
 */

function auditedActions(): array
{
    return AuditLog::query()->withoutGlobalScopes()->orderBy('id')->pluck('action')->all();
}

it('signs the operator in as the customer and says so on every screen', function (): void {
    [$customer, $team] = Fixtures::tenant();
    $operator = Fixtures::operator();

    $this->actingAs($operator)
        ->post('/admin/users/'.$customer->getKey().'/impersonate')
        ->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($customer->getKey());

    // The banner is a shared prop written by middleware on every web request, not something a
    // controller remembered — so it cannot be missing from the screen nobody thought about.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('impersonation.active', true)
            ->where('impersonation.operator', $operator->name)
            ->has('impersonation.expires_at'));

    expect($team->getKey())->not->toBeNull();
});

it('audits the start against the tenant whose data is about to be read', function (): void {
    [$customer, $team] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'admin.impersonation.started')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->properties['target_user_id'])->toBe((int) $customer->getKey())
        ->and($entry->properties)->toHaveKey('operator_id');
});

it('refuses to impersonate another operator', function (): void {
    $target = Fixtures::operator(AdminRole::Support);

    $this->withoutExceptionHandling()
        ->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$target->getKey().'/impersonate');
})->throws(AdminException::class, 'platform operator');

it('refuses to impersonate yourself', function (): void {
    $operator = Fixtures::operator();

    $this->withoutExceptionHandling()
        ->actingAs($operator)
        ->post('/admin/users/'.$operator->getKey().'/impersonate');
})->throws(AdminException::class, 'already signed in');

it('blocks every action on the forbidden list', function (): void {
    [$customer] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    // One from each reason the list exists: money, credentials, the account itself, and data
    // leaving the building on an operator's laptop.
    $blocked = [
        ['post', 'billing.checkout'],
        ['put', 'user-password.update'],
        ['patch', 'profile.update'],
        ['get', 'leads.export'],
    ];

    foreach ($blocked as [$method, $name]) {
        expect(fn () => $this->withoutExceptionHandling()->{$method}(route($name)))
            ->toThrow(AdminException::class, 'blocked while impersonating');
    }
});

it('lets the customer’s ordinary screens through', function (): void {
    [$customer] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    // The point of impersonating is to see what they see. Blocking everything would be useless;
    // the list is what leaves the building or cannot be undone, and reading is neither.
    $this->get(route('leads.index'))->assertOk();
    $this->get(route('billing.index'))->assertOk();
});

it('closes the admin panel while impersonating', function (): void {
    [$customer] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    // You are the customer now. The way back is the banner's stop link, which is not an admin route
    // precisely so that it still works from here.
    $this->get('/admin')->assertNotFound();
});

it('records the operator behind every write the session makes', function (): void {
    [$customer, $team] = Fixtures::tenant();
    $operator = Fixtures::operator();
    $lead = Fixtures::lead($team, 'Acme Ltd');

    $this->actingAs($operator)->post('/admin/users/'.$customer->getKey().'/impersonate');

    $this->post(route('leads.notes.store', $lead), ['body' => 'Spoke to them about the renewal.'])
        ->assertRedirect();

    expect(LeadNote::query()->withoutGlobalScopes()->count())->toBe(1);

    // The note was written by Leads Workspace's own Action, which knows nothing about
    // impersonation — the operator id rides in on the ambient audit context instead.
    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'like', 'lead%')
        ->latest('id')
        ->first();

    if ($entry !== null) {
        expect($entry->properties['impersonated_by'] ?? null)->toBe((int) $operator->getKey());
    }

    // And the start entry carries it too, which is the one that is always written.
    expect(auditedActions())->toContain('admin.impersonation.started');
});

it('hands the session back on stop, and audits that too', function (): void {
    [$customer] = Fixtures::tenant();
    $operator = Fixtures::operator();

    $this->actingAs($operator)->post('/admin/users/'.$customer->getKey().'/impersonate');

    $this->post(route('impersonation.stop'))->assertRedirect(route('admin.dashboard'));

    expect(Auth::id())->toBe($operator->getKey())
        ->and(auditedActions())->toContain('admin.impersonation.stopped');

    $this->get('/admin')->assertOk();
});

it('refuses to stop a session that is not impersonating', function (): void {
    $this->withoutExceptionHandling()
        ->actingAs(Fixtures::operator())
        ->post(route('impersonation.stop'));
})->throws(AdminException::class, 'not impersonating');

it('hands the operator back to themselves when the window closes', function (): void {
    [$customer] = Fixtures::tenant();
    $operator = Fixtures::operator();
    $customerToken = $customer->getAttribute('remember_token');

    $this->actingAs($operator)->post('/admin/users/'.$customer->getKey().'/impersonate');

    $this->travel((int) config('admin.impersonation.ttl_minutes') + 1)->minutes();

    // A forgotten impersonation is a customer's account left open in somebody's browser, so the
    // clock ends it even if nobody clicks stop — and it ends it by restoring the operator.
    $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));

    expect(Auth::id())->toBe($operator->getKey())
        ->and(auditedActions())->toContain('admin.impersonation.expired');
});

it('does not rotate the customer’s remember token when a session expires', function (): void {
    [$customer] = Fixtures::tenant();
    $customer->forceFill(['remember_token' => 'the-customers-own-token'])->save();

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    $this->travel((int) config('admin.impersonation.ttl_minutes') + 1)->minutes();
    $this->get(route('dashboard'));

    // `Auth::logout()` cycles the **authenticated** user's remember token, and at the moment of
    // expiry that user is the customer — so the obvious implementation signs a real customer out of
    // every device they had ticked "remember me" on, because an operator left a tab open.
    expect($customer->refresh()->getAttribute('remember_token'))->toBe('the-customers-own-token');
});

it('does not carry the operator’s password confirmation into the session', function (): void {
    [$customer] = Fixtures::tenant();

    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    // The session's data survives the login, so without clearing this the impersonated session
    // would count as password-confirmed for anything behind `RequirePassword`.
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('records the stop against the tenant, so their own audit view shows it', function (): void {
    [$customer, $team] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    $this->post(route('impersonation.stop'));

    $stopped = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'admin.impersonation.stopped')
        ->latest('id')
        ->first();

    // A `stopped` with no team would leave the customer's audit view showing somebody arriving and
    // never leaving.
    expect($stopped)->not->toBeNull()
        ->and((int) $stopped->team_id)->toBe((int) $team->getKey());
});

it('blocks signing out, because logging out would be the customer logging out', function (): void {
    [$customer] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    // `Auth::logout()` cycles the authenticated user's remember token, and that user is the
    // customer. Stop impersonating first — it is one click, and it is on every screen.
    expect(fn () => $this->withoutExceptionHandling()->post(route('logout')))
        ->toThrow(AdminException::class, 'blocked while impersonating');
});

it('blocks removing a do-not-contact entry as the customer', function (): void {
    [$customer, $team] = Fixtures::tenant();
    $suppression = OutreachSuppression::factory()->create([
        'team_id' => $team->getKey(),
        'reason' => SuppressionReason::Manual,
    ]);

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    // The same class of act as a send, and arguably worse: it removes an entry with the customer's
    // name on it from the record that is their evidence of having honoured an opt-out.
    expect(fn () => $this->withoutExceptionHandling()
        ->delete(route('outreach.suppressions.destroy', $suppression)))
        ->toThrow(AdminException::class, 'blocked while impersonating');
});

it('blocks spending the customer’s allowance', function (): void {
    [$customer] = Fixtures::tenant();

    $this->actingAs(Fixtures::operator())
        ->post('/admin/users/'.$customer->getKey().'/impersonate');

    expect(fn () => $this->withoutExceptionHandling()->post(route('lead-sourcing.store')))
        ->toThrow(AdminException::class, 'blocked while impersonating');
});

it('is not offered to roles that do not need it', function (): void {
    [$customer] = Fixtures::tenant();

    foreach ([AdminRole::Finance, AdminRole::ReadOnly] as $role) {
        $this->actingAs(Fixtures::operator($role))
            ->post('/admin/users/'.$customer->getKey().'/impersonate')
            ->assertForbidden();

        expect(app(Impersonator::class)->isActive())->toBeFalse();
    }
});
