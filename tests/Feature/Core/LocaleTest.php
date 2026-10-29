<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('defaults to English when nothing is set', function (): void {
    $this->get('/')->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('resolves the locale from the cookie and flips direction to RTL for Arabic', function (): void {
    // The `locale` cookie is excepted from encryption, so send it unencrypted.
    $this->withUnencryptedCookie('locale', 'ar')->get('/')->assertOk();

    expect(app()->getLocale())->toBe('ar')
        ->and(Locale::direction('ar'))->toBe('rtl');
});

it('ignores an unsupported locale cookie and falls back to the default', function (): void {
    $this->withUnencryptedCookie('locale', 'fr')->get('/')->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('falls back to the active team locale when no cookie is present', function (): void {
    $user = createUserWithTeam();

    Team::query()->whereKey($user->currentTeamId())->update(['locale' => 'ar']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect(app()->getLocale())->toBe('ar');
});

it('lets the cookie override the team locale', function (): void {
    $user = createUserWithTeam();

    Team::query()->whereKey($user->currentTeamId())->update(['locale' => 'ar']);

    $this->actingAs($user)->withUnencryptedCookie('locale', 'en')->get(route('dashboard'))->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('switches the locale and persists the choice in a cookie', function (): void {
    $response = $this->from('/')->post(route('locale.update'), ['locale' => 'ar']);

    // The cookie is stored unencrypted (it is in the encryption-except list).
    $response->assertRedirect('/')->assertPlainCookie('locale', 'ar');
});

it('rejects an unsupported locale on switch', function (): void {
    $this->from('/')
        ->post(route('locale.update'), ['locale' => 'de'])
        ->assertSessionHasErrors('locale');
});

it('rejects a missing locale on switch', function (): void {
    $this->from('/')
        ->post(route('locale.update'), [])
        ->assertSessionHasErrors('locale');
});

it('shares the locale, direction, options and translations to Inertia', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->withUnencryptedCookie('locale', 'ar')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale.current', 'ar')
            ->where('locale.dir', 'rtl')
            ->has('locale.available', 2)
            ->where('locale.translations.Dashboard', 'لوحة التحكم')
            ->where('auth.currentTeam.timezone', 'UTC')
            ->has('auth.currentTeam.locale')
            ->has('auth.currentTeam.market')
        );
});

it('exposes supported locale options with direction metadata', function (): void {
    $options = Locale::options();

    expect($options)->toHaveCount(2)
        ->and(collect($options)->firstWhere('code', 'ar')['dir'])->toBe('rtl')
        ->and(collect($options)->firstWhere('code', 'en')['dir'])->toBe('ltr');
});

it('keeps the en and ar translation bundles in key parity', function (): void {
    $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true);

    expect(array_keys($ar))->toEqual(array_keys($en))
        ->and(array_filter($ar, fn (string $v): bool => $v === ''))->toBeEmpty();
});
