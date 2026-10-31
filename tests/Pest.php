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
 *
 * **Including the free trial**, which is the part that has to be explicit rather than incidental.
 * Billing starts a new team's trial from a `Team::created` model event (see
 * `BillingServiceProvider::startTrialOnNewTeams` for why it is an event and not a line in
 * `CreateTeamAction`) — and a test that calls `Event::fake()` in its `beforeEach`, which several do,
 * suppresses it. Those teams were therefore silently resolving to the **Free** plan rather than the
 * trial one.
 *
 * That cost nothing until Phase 19b made plans grant capabilities, at which point thirty-odd tests
 * across Signals started being refused a feature their team should have had. Setting the column
 * here makes the helper match its own description: a team that has just registered is on trial, and
 * a test should not have to know that the trial arrives by event.
 */
function createUserWithTeam(array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    $team = app(CreatePersonalTeamAction::class)->handle($user);

    if ($team->trial_ends_at === null) {
        $days = max(0, (int) config('billing.trial.days', 14));

        if ($days > 0) {
            $team->forceFill(['trial_ends_at' => now()->addDays($days)])->save();
        }
    }

    return $user->refresh();
}
