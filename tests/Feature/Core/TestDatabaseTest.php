<?php

declare(strict_types=1);

/**
 * The suite's own database, asserted rather than assumed.
 *
 * RefreshDatabase runs `migrate:fresh`, so a test process pointed at the dev MySQL database drops
 * it. `AppServiceProvider::configureDefaults()` refuses to boot a test run on any other driver;
 * this is the statement of what that guard is protecting. See CLAUDE.md section 0.
 */
it('runs on an in-memory SQLite database', function (): void {
    $default = (string) config('database.default');

    expect(config("database.connections.{$default}.driver"))->toBe('sqlite')
        ->and(config("database.connections.{$default}.database"))->toBe(':memory:');
});
