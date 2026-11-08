<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Catch N+1s, silent attribute discards and missing attributes outside production.
        Model::shouldBeStrict(! app()->isProduction());

        // Block migrate:fresh / migrate:refresh / migrate:reset / db:wipe EVERYWHERE except the
        // testing environment. Testing must stay allowed because RefreshDatabase runs migrate:fresh
        // on the in-memory SQLite (phpunit.xml). On local dev this guards the real MySQL data: a
        // destructive migrate command is never the right way to apply a schema change here — write a
        // new migration and run `php artisan migrate`. Only lift this (temporarily, deliberately)
        // when a full local rebuild is actually intended.
        DB::prohibitDestructiveCommands(
            ! app()->environment('testing'),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
