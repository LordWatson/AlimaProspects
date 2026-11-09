#!/usr/bin/env node
/**
 * PreToolUse (Bash) guard: refuse anything that can drop the dev database.
 *
 * Why this exists: on 2026-11-08 a `migrate:fresh` wiped the dev MySQL database
 * (alima_prospects). These commands drop every table and lose all data. The USER
 * may still run them in their own terminal; this only stops Claude from running
 * them. See CLAUDE.md section 0.
 *
 * Blocks: migrate:fresh, migrate:refresh, migrate:reset, db:wipe — however php is
 * invoked (bare `php`, Herd `php.bat`, full path) and anywhere in a compound command.
 * Deny permission rules in settings.json cover the common invocations; this is the
 * catch-all that does not depend on the exact command prefix.
 *
 * It also refuses **the test runners**, which is the same hazard by a different road: on
 * 2026-11-09 a bare `artisan test` dropped every table in alima_prospects twice, because a
 * stale bootstrap/cache/config.php overrode phpunit.xml's sqlite pins and the APP_ENV that the
 * application's own SQLite check reads, so RefreshDatabase rebuilt MySQL instead of a scratch
 * file. `composer test` is the one entry point Claude may use: it clears the config first, and
 * tests/bootstrap.php now pins the connection before the framework boots.
 */
'use strict';

// Anchored to an actual `artisan <subcommand>` invocation, so merely MENTIONING
// one of these strings (grep, a log path, an echo, a comment) is not blocked —
// only running it is. `php`/`php.bat`/full-path invocations all put `artisan`
// immediately before the subcommand.
const DESTRUCTIVE = /\bartisan\s+(?:migrate:(?:fresh|refresh|reset)|db:wipe)\b/;

// A bare test runner, any path, anywhere in a compound command. `composer test` is
// deliberately not matched: it is the entry point that clears the config cache first.
const BARE_TEST_RUNNER = /\bartisan\s+test\b|\bvendor[\\/]bin[\\/](?:pest|phpunit)\b/;

let input = '';
process.stdin.on('data', (d) => (input += d));
process.stdin.on('end', () => {
    let command = '';
    try {
        command = (JSON.parse(input).tool_input || {}).command || '';
    } catch {
        // Not parseable: let the normal permission flow handle it.
        process.exit(0);
    }

    if (BARE_TEST_RUNNER.test(command)) {
        process.stdout.write(
            JSON.stringify({
                hookSpecificOutput: {
                    hookEventName: 'PreToolUse',
                    permissionDecision: 'deny',
                    permissionDecisionReason:
                        'Blocked by project policy: run the suite with `composer test`, which ' +
                        'clears the config cache first. A bare runner on a cached config has ' +
                        'twice dropped every table in the MySQL dev database, because the cache ' +
                        'overrides phpunit.xml’s sqlite pins and the APP_ENV the in-app guard ' +
                        'reads. See CLAUDE.md section 0.',
                },
            }),
        );
        process.exit(0);
    }

    if (DESTRUCTIVE.test(command)) {
        process.stdout.write(
            JSON.stringify({
                hookSpecificOutput: {
                    hookEventName: 'PreToolUse',
                    permissionDecision: 'deny',
                    permissionDecisionReason:
                        'Blocked by project policy: destructive migration commands ' +
                        '(migrate:fresh / migrate:refresh / migrate:reset / db:wipe) must not be ' +
                        'run by Claude against the dev database. They drop every table. The user ' +
                        'runs these in their own terminal. See CLAUDE.md section 0.',
                },
            }),
        );
    }
    process.exit(0);
});
