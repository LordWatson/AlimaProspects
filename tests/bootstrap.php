<?php

declare(strict_types=1);

/*
 * The suite's pre-boot guard: **tests run on in-memory SQLite, and nothing can talk them out of
 * it.**
 *
 * Three things can point a test process at the MySQL dev database, and `RefreshDatabase` runs
 * `migrate:fresh` on whatever the default connection turns out to be, so each one of them is every
 * table in `alima_prospects`:
 *
 * 1. A variable exported in the **shell** beats phpunit.xml's `<env>` pins, which is PHPUnit's
 *    documented behaviour without `force` — and `force="true"` only covers `putenv` and `$_ENV`,
 *    not `$_SERVER`, which Laravel's env repository also reads (see the note in phpunit.xml).
 * 2. A **cached configuration** beats both: `bootstrap/cache/config.php` replaces every pin
 *    wholesale, `APP_ENV` included.
 * 3. And because `APP_ENV` is what `runningUnitTests()` reads, a stale cache also switches off
 *    `AppServiceProvider`'s own SQLite check — the guard disabling itself in the one situation it
 *    exists for. That is what happened on 2026-11-09, twice.
 *
 * This file runs **before the framework exists**, which is the only place the first two can be
 * settled rather than detected. It writes all three of `putenv`/`$_ENV`/`$_SERVER`, so Dotenv's
 * immutable load cannot put `.env`'s answer back, and it is the bootstrap for every way the suite
 * is started: `composer test`, `artisan test`, `artisan test --parallel`, `vendor/bin/pest`, CI.
 */

require __DIR__.'/../vendor/autoload.php';

$cachedConfig = __DIR__.'/../bootstrap/cache/config.php';

if (file_exists($cachedConfig)) {
    // **Deleted, not refused.** A compiled config is regenerable by definition, so removing it
    // costs a developer one `config:cache` and costs a mistake nothing; refusing to run would
    // leave the trap armed for whoever starts the suite the next way round. `composer test` clears
    // it too — this covers every other entry point.
    @unlink($cachedConfig);

    fwrite(STDERR, "[tests] Removed bootstrap/cache/config.php: a cached config overrides every "
        ."pin in phpunit.xml, including the database.
");
}

/*
 * The pins that decide whether a mistake costs a test run or the dev database. Kept to those: this
 * is a safety rail, not a second copy of phpunit.xml.
 */
$pins = [
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    // Pinned because two guards key off it: Laravel's `runningUnitTests()` and, through that,
    // `AppServiceProvider`'s driver check.
    'APP_ENV' => 'testing',
];

foreach ($pins as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
