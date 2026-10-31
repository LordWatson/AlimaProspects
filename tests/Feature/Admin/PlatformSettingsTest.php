<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Models\PlatformSetting;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * The settings store (Phase 19b, ADR 0015): config declares the schema, the table holds overrides.
 */

it('answers from config when nothing has been overridden', function (): void {
    $settings = app(PlatformSettings::class);

    // A fresh deployment behaves exactly as `.env` says. No rows, no surprises.
    expect(PlatformSetting::query()->count())->toBe(0)
        ->and($settings->bool('billing.stripe_enabled'))->toBeTrue()
        ->and($settings->list('capabilities.disabled'))->toBe([]);
});

it('stores an override and reads it back through the declared type', function (): void {
    $operator = Fixtures::operator();
    $settings = app(PlatformSettings::class);

    $settings->set('billing.stripe_enabled', false, $operator);

    expect($settings->bool('billing.stripe_enabled'))->toBeFalse()
        ->and(PlatformSetting::query()->where('key', 'billing.stripe_enabled')->exists())->toBeTrue();

    // A fresh instance, so the answer comes from the table rather than the memo.
    app()->forgetInstance(PlatformSettings::class);

    expect(app(PlatformSettings::class)->bool('billing.stripe_enabled'))->toBeFalse();
});

it('removes the row when a value is set back to the declared default', function (): void {
    $operator = Fixtures::operator();
    $settings = app(PlatformSettings::class);

    $settings->set('billing.stripe_enabled', false, $operator);
    $settings->set('billing.stripe_enabled', true, $operator);

    // A row saying "this is set to what config already says" is a row that silently stops
    // tracking config the next time somebody changes `.env`.
    expect(PlatformSetting::query()->where('key', 'billing.stripe_enabled')->exists())->toBeFalse()
        ->and($settings->bool('billing.stripe_enabled'))->toBeTrue();
});

it('audits a change with both sides of it', function (): void {
    $operator = Fixtures::operator();

    app(PlatformSettings::class)->set('billing.stripe_enabled', false, $operator);

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'admin.setting.changed')
        ->latest('id')
        ->first();

    // "Who turned payments off" is only half the question.
    expect($entry)->not->toBeNull()
        ->and($entry->properties['key'])->toBe('billing.stripe_enabled')
        ->and($entry->properties['from'])->toBeTrue()
        ->and($entry->properties['to'])->toBeFalse()
        ->and($entry->properties['operator_id'])->toBe((int) $operator->getKey());
});

it('refuses a key config does not declare', function (): void {
    app(PlatformSettings::class)->set('billing.not_a_setting', true, Fixtures::operator());
})->throws(AdminException::class, 'no platform setting called');

it('does not read a truthy string as a true boolean', function (): void {
    $settings = app(PlatformSettings::class);

    // A form posts `"0"` for an unticked box, and `"0"` is a truthy string. A kill switch that
    // read as on would be the worst possible failure of the cast.
    $settings->set('billing.stripe_enabled', '0', Fixtures::operator());

    expect($settings->bool('billing.stripe_enabled'))->toBeFalse();
});

it('parses a typed list from the text an operator writes', function (): void {
    $settings = app(PlatformSettings::class);

    $settings->set('capabilities.disabled', "signals\n  ai_insights ,, signals", Fixtures::operator());

    // Split on whitespace and commas, trimmed, de-duplicated — because that is how somebody types
    // a list into a textarea.
    expect($settings->list('capabilities.disabled'))->toBe(['signals', 'ai_insights']);
});

it('is reachable only by a role that holds settings.manage', function (): void {
    foreach ([AdminRole::Support, AdminRole::Finance, AdminRole::ReadOnly] as $role) {
        $this->actingAs(Fixtures::operator($role))->get('/admin/settings')->assertForbidden();
    }

    $this->actingAs(Fixtures::operator(AdminRole::SuperAdmin))
        ->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/settings/Index')
            ->has('settings'));
});

it('saves from the screen and says where each value came from', function (): void {
    $this->actingAs(Fixtures::operator())
        ->patch('/admin/settings', [
            'settings' => [
                'billing.stripe_enabled' => '0',
                'capabilities.disabled' => 'signals',
            ],
        ])
        ->assertRedirect('/admin/settings');

    $described = collect(app(PlatformSettings::class)->describeAll())->keyBy('key');

    expect($described['billing.stripe_enabled']['value'])->toBeFalse()
        // The screen has to distinguish a default from a default somebody chose.
        ->and($described['billing.stripe_enabled']['is_overridden'])->toBeTrue()
        ->and($described['announcements.enabled']['is_overridden'])->toBeFalse();
});

it('refuses to read a key nobody declared', function (): void {
    // Symmetric with `set()`, and for a sharper reason. Every key is a literal in our own code, so
    // an undeclared one is always a typo rather than an input — and null was the worst possible
    // answer to a typo here: `bool('billing.stripe_enable')` reads as false, which switches Stripe
    // off, and `bool('announcements.enable')` reads as false, which hides every announcement. Both
    // look exactly like a working screen.
    expect(fn () => app(PlatformSettings::class)->get('billing.stripe_enable'))
        ->toThrow(AdminException::class, 'billing.stripe_enable');

    expect(fn () => app(PlatformSettings::class)->bool('announcements.enable'))
        ->toThrow(AdminException::class);
});

it('refuses a kill switch for a feature that does not exist', function (): void {
    $operator = Fixtures::operator(AdminRole::SuperAdmin);

    $this->actingAs($operator)
        ->patch('/admin/settings', ['settings' => ['capabilities.disabled' => ['signal']]])
        ->assertSessionHasErrors('settings.capabilities.disabled');

    // The failure this prevents happens during an incident: `signal` for `signals` saves cleanly,
    // kills nothing, and leaves an operator believing they have switched a broken feature off.
    expect(app(PlatformSettings::class)->list('capabilities.disabled'))->toBe([]);

    $this->actingAs($operator)
        ->patch('/admin/settings', ['settings' => ['capabilities.disabled' => [Capability::Signals->value]]])
        ->assertRedirect();

    app()->forgetInstance(PlatformSettings::class);

    expect(app(PlatformSettings::class)->list('capabilities.disabled'))
        ->toBe([Capability::Signals->value]);
});

it('applies a screenful of settings as one change', function (): void {
    $operator = Fixtures::operator(AdminRole::SuperAdmin);

    // Two valid keys and one invalid one. Before `UpdatePlatformSettingsAction` each `set()` was
    // its own transaction, so a page like this applied whatever came first in the config file and
    // left the operator looking at a form that half-worked — in a file full of kill switches.
    $this->actingAs($operator)
        ->patch('/admin/settings', ['settings' => [
            'billing.stripe_enabled' => false,
            'capabilities.disabled' => ['not-a-capability'],
        ]])
        ->assertSessionHasErrors();

    app()->forgetInstance(PlatformSettings::class);

    expect(app(PlatformSettings::class)->bool('billing.stripe_enabled'))->toBeTrue()
        ->and(PlatformSetting::query()->count())->toBe(0);
});
