<?php

use App\Models\User;
use App\Modules\Auth\Actions\CreatePersonalTeamAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\Support\ApiAssertions;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

// `/api/v1` contract assertions as TestResponse macros - assertApiError(),
// assertApiValidationErrors(), assertApiVersion(). See Tests\Support\ApiAssertions.
ApiAssertions::register();

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Swap Fortify's TOTP provider for one that accepts exactly `$code`.
 *
 * The real provider derives the code from the clock and the stored secret, so a test cannot know
 * what it would accept without reimplementing it — and a test that reimplemented it would pass
 * whether or not the app consulted the provider at all. Faking the seam instead keeps the
 * assertion on *our* flow: that the code reached the provider and that its verdict decided.
 */
function fakeTwoFactorCode(string $code): void
{
    app()->instance(TwoFactorAuthenticationProvider::class, new class($code) implements TwoFactorAuthenticationProvider
    {
        public function __construct(private readonly string $expected) {}

        public function generateSecretKey(): string
        {
            return 'secret';
        }

        public function qrCodeUrl($companyName, $companyEmail, $secret): string
        {
            return 'otpauth://totp/fake';
        }

        public function verify($secret, $code): bool
        {
            return $code === $this->expected;
        }
    });
}

/**
 * Create a verified user with their personal team (Owner), as registration would.
 */
function createUserWithTeam(array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    app(CreatePersonalTeamAction::class)->handle($user);

    return $user->refresh();
}
