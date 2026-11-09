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
        if ($this->runningTests()) {
            // **A cached configuration is itself the danger**, not merely a cause of one. It
            // replaces every pin in phpunit.xml wholesale — the connection, the session driver,
            // the queue, and APP_ENV, which is what `runningUnitTests()` reads. That is how this
            // very guard came to be skipped while RefreshDatabase dropped the dev database.
            if (app()->configurationIsCached()) {
                throw new RuntimeException(
                    'Tests must not run on a cached configuration. bootstrap/cache/config.php '
                    .'overrides every pin in phpunit.xml, including APP_ENV, so the SQLite check '
                    .'below cannot be trusted and RefreshDatabase would drop the dev database. '
                    .'Run `php artisan config:clear`, or `composer test`, which clears it first.'
                );
            }

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

    /**
     * Whether this process is a test run — asked of PHPUnit, never of `APP_ENV`.
     *
     * `runningUnitTests()` reads the container's env, which comes from config, so a stale
     * `bootstrap/cache/config.php` reporting `local` switched the database guard off in the one
     * situation it exists for. PHPUnit's own bootstrap constant and its base class cannot be
     * cached away, so they are asked too and the framework's answer is kept as one of three.
     */
    private function runningTests(): bool
    {
        return app()->runningUnitTests()
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || class_exists('PHPUnit\Framework\TestCase', false);
    }
}
