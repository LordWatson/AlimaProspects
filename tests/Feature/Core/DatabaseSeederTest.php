<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Onboarding\Models\BusinessProfile;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * `migrate --seed` has to work. It is how a new developer gets a product they can look at, how the
 * demo tenancy in `docs/runbooks/local-setup.md` comes into being, and the first thing anybody runs.
 *
 * It was broken, and the way it stayed broken is the lesson in this file. `DemoSeeder` mass-assigned
 * `business_profiles.version` and `completed_at` — real columns that are deliberately **not**
 * fillable, because they are ours to set rather than the onboarding form's — which throws under
 * `Model::shouldBeStrict()`, i.e. everywhere but production. Twenty tests called
 * `$this->seed(SomeSeeder::class)` and none of them noticed, because **`$this->seed()` returns a
 * `PendingCommand` that only executes when it is destructed**, and an exception thrown there goes
 * nowhere near the assertion count.
 *
 * So the seeders here are invoked **directly**. That is the whole point of the file: `$this->seed()`
 * is fine for a test that then asserts on what the seeder produced (a missing row fails the
 * assertion), and useless for asserting that the seeder *ran*.
 */

it('runs the whole seeder stack without throwing', function (): void {
    app(DatabaseSeeder::class)->run();

    // Each seeder's own contribution, so a failure says which link broke rather than just "threw".
    expect(Role::query()->count())->toBeGreaterThan(0)
        ->and(User::query()->where('email', SuperAdminSeeder::EMAIL)->exists())->toBeTrue()
        ->and(Team::query()->count())->toBeGreaterThan(0);
});

it('leaves a demo tenancy somebody can actually look at', function (): void {
    app(DatabaseSeeder::class)->run();

    // The reason the demo seeder exists: a signed-in developer should land on a dashboard with
    // figures on it, not an empty state.
    $profiles = BusinessProfile::query()->withoutGlobalScopes()->get();

    expect($profiles)->not->toBeEmpty()
        ->and(Lead::query()->withoutGlobalScopes()->count())->toBeGreaterThan(0);

    foreach ($profiles as $profile) {
        // The two columns that were being discarded. A profile seeded without them is one the app
        // reads as half-finished onboarding, which is not the state the demo is meant to show.
        expect($profile->completed_at)->not->toBeNull()
            ->and($profile->version)->toBe(1)
            ->and($profile->isComplete())->toBeTrue();
    }
});

it('is re-runnable, the way migrate:fresh --seed is', function (): void {
    app(DatabaseSeeder::class)->run();

    $teams = Team::query()->count();

    app(DatabaseSeeder::class)->run();

    // Not idempotent in the sense of creating nothing the second time — the demo seeder deliberately
    // makes fresh tenancies — but it must not *fail*, because the roles, the prompt registry and the
    // operator account are all `firstOrCreate` paths that a second run has to pass through.
    expect(Team::query()->count())->toBeGreaterThanOrEqual($teams)
        ->and(User::query()->where('email', SuperAdminSeeder::EMAIL)->count())->toBe(1);
});
