<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

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

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // The suite's RefreshDatabase runs `migrate:fresh`, so a test process whose default
        // connection is MySQL drops the dev database (alima_prospects) rather than a scratch file.
        // phpunit.xml pins sqlite/:memory:, but a variable already exported in the shell beats it
        // (see the note there on why `force="true"` is not the answer), so refuse to run on
        // anything else instead of trusting the environment. See CLAUDE.md section 0.
        if (app()->runningUnitTests()) {
            $default = (string) config('database.default');
            $driver = config("database.connections.{$default}.driver");

            if ($driver !== 'sqlite') {
                throw new RuntimeException(
                    "Tests must run on SQLite. The default connection [{$default}] uses driver "
                    ."[{$driver}], which would be wiped by RefreshDatabase. Unset DB_CONNECTION/"
                    .'DB_DATABASE in your shell: phpunit.xml pins sqlite/:memory:, but an exported '
                    .'variable overrides it.'
                );
            }
        }

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
