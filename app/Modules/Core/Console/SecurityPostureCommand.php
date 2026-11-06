<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use Illuminate\Console\Command;

/**
 * Asserts the settings that have to be right in production (Phase 20, ADR 0017).
 *
 * ```
 * php artisan security:posture            # check this environment
 * php artisan security:posture --strict   # apply the production rules whatever APP_ENV says
 * ```
 *
 * ## Why a command and not a test
 *
 * Every one of these is **correct in `.env.example` and wrong on a box somebody configured in a
 * hurry**. A test asserts what the repository says; this asserts what the machine says, which is a
 * different question and the only one that matters at 9am on a launch day. Several of them are
 * also the kind of thing that gets switched off for an afternoon and left off — `APP_DEBUG=true`
 * to chase a bug, `ADMIN_REQUIRE_TWO_FACTOR=false` to get into the panel without a phone — and the
 * only control against that is something that looks, after the deploy, every time.
 *
 * Phase 21 runs it as a deploy step, which is why the exit code is the point and the table is a
 * courtesy. Failures are listed with what to set; warnings are things that are *probably* wrong
 * and do not fail a deploy, because a check that cries wolf gets `|| true` appended to it.
 *
 * ## What it deliberately does not do
 *
 * It does not make a request, so it cannot tell you a header arrived — `SecurityHeadersTest` does
 * that against the real middleware stack. It reads configuration, which is the half a test cannot
 * see. The two together are the claim; neither alone is.
 */
final class SecurityPostureCommand extends Command
{
    protected $signature = 'security:posture
        {--strict : Apply the production rules regardless of APP_ENV.}';

    protected $description = 'Check the security-critical settings of this environment (Phase 20).';

    /**
     * @var list<array{status: string, check: string, detail: string}>
     */
    private array $results = [];

    public function handle(): int
    {
        // Artisan reuses one command instance per process, so a second run in the same process —
        // two `$this->artisan()` calls in one test, or a `schedule:run` that fires this twice —
        // would otherwise accumulate the first run's rows and report its failures again.
        $this->results = [];

        $production = (bool) $this->option('strict') || app()->isProduction();

        $this->appChecks($production);
        $this->sessionChecks($production);
        $this->headerChecks($production);
        $this->adminChecks($production);
        $this->observabilityChecks($production);
        $this->spendChecks($production);

        $this->table(['', 'Check', 'Detail'], array_map(
            static fn (array $row): array => [
                match ($row['status']) {
                    'fail' => '<fg=red>FAIL</>',
                    'warn' => '<fg=yellow>WARN</>',
                    default => '<fg=green>ok</>',
                },
                $row['check'],
                $row['detail'],
            ],
            $this->results,
        ));

        $failures = count(array_filter($this->results, static fn (array $row): bool => $row['status'] === 'fail'));

        if ($failures > 0) {
            $this->newLine();
            $this->error("{$failures} security check(s) failed.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($production
            ? 'Production security posture looks correct.'
            : 'No production checks ran (APP_ENV is not production — use --strict to apply them anyway).');

        return self::SUCCESS;
    }

    private function appChecks(bool $production): void
    {
        // The single worst one. `APP_DEBUG=true` in production puts the stack trace, the
        // environment and the database credentials on the error page of anybody who can provoke a
        // 500 — and nothing else in this list is a one-request total disclosure.
        $this->assert(
            'app.debug off',
            ! $production || config('app.debug') !== true,
            'APP_DEBUG=false',
        );

        $this->assert(
            'APP_KEY set',
            is_string(config('app.key')) && config('app.key') !== '',
            'php artisan key:generate',
        );

        $this->assert(
            'APP_URL is https',
            ! $production || str_starts_with((string) config('app.url'), 'https://'),
            'APP_URL=https://…',
        );
    }

    private function sessionChecks(bool $production): void
    {
        // `secure` null means "follow the request", which over HTTPS is the right answer and over a
        // misconfigured proxy is a session cookie on the wire. In production it should be explicit.
        $this->assert(
            'secure session cookie',
            ! $production || config('session.secure') === true,
            'SESSION_SECURE_COOKIE=true',
        );

        $this->assert(
            'http-only session cookie',
            config('session.http_only') !== false,
            'SESSION_HTTP_ONLY=true',
        );

        // `lax` is enough: the app has no cross-site POST flow, and `strict` would drop the cookie
        // on the way back from a Stripe checkout redirect. `none` would be a CSRF surface.
        $this->assert(
            'same-site session cookie',
            in_array(config('session.same_site'), ['lax', 'strict'], true),
            'SESSION_SAME_SITE=lax',
        );
    }

    private function headerChecks(bool $production): void
    {
        $this->assert(
            'CSP enabled',
            config('security.csp.enabled') !== false,
            'SECURITY_CSP_ENABLED=true',
        );

        // Report-only in production is a policy that reports and permits. Legitimate while a
        // tightened policy is being rolled out, which is why it is a warning rather than a
        // failure — but it should never be the steady state, and nobody notices on their own.
        $this->warnIf(
            'CSP enforcing',
            $production && config('security.csp.report_only') === true,
            'SECURITY_CSP_REPORT_ONLY=false once the violations are clean',
        );

        $this->assert(
            'HSTS enabled',
            ! $production || config('security.headers.hsts.enabled') !== false,
            'SECURITY_HSTS_ENABLED=true',
        );
    }

    private function adminChecks(bool $production): void
    {
        // `EnsureAdminTwoFactor` can be switched off so a test suite and a first run-through do not
        // have to solve a TOTP challenge. In production, one super-admin credential reads every
        // tenant's data, and a password is not the right lock on that.
        $this->assert(
            'admin two-factor required',
            ! $production || config('admin.require_two_factor') !== false,
            'ADMIN_REQUIRE_TWO_FACTOR=true',
        );

        // Empty is the only honest *default* (an allowlist configured wrong locks the ops team out
        // of the tool they would fix it with), but in production the office or VPN range is known.
        $this->warnIf(
            'admin IP allowlist set',
            $production && (array) config('admin.ip_allowlist', []) === [],
            'ADMIN_IP_ALLOWLIST=… once the egress range is known',
        );
    }

    private function observabilityChecks(bool $production): void
    {
        $required = $production && config('security.posture.require_sentry_dsn') !== false;

        $this->assert(
            'backend Sentry DSN',
            ! $required || $this->filled('sentry.dsn'),
            'SENTRY_LARAVEL_DSN=…',
        );

        $this->warnIf(
            'frontend Sentry DSN',
            $required && ! $this->filled('observability.sentry.frontend.dsn'),
            'SENTRY_DSN_FRONTEND=… (a browser error is invisible without it)',
        );

        // `send_default_pii` governs the cookies, the headers and the visitor's IP address.
        $this->assert(
            'Sentry PII off',
            config('sentry.send_default_pii') !== true,
            'SENTRY_SEND_DEFAULT_PII=false',
        );

        // And **not** the request body, which is this option — a separate check because an earlier
        // version of this command said the first one covered both, which was wrong (Phase 20
        // security review). The SDK's own default is `medium`: ten kilobytes of body on every error
        // event. In this product that body is the prospect record somebody was saving, whose field
        // names are not credential-shaped and so are not on any deny-list.
        $this->assert(
            'Sentry request body off',
            in_array(config('sentry.max_request_body_size'), ['none', 'never'], true),
            'SENTRY_MAX_REQUEST_BODY_SIZE=none',
        );
    }

    /**
     * The settings that decide whether a tenant can spend unbounded money (S16).
     *
     * **In this command rather than in a test, for the reason the class docblock gives**, and the
     * fit is as good here as any of the others: each one of these is correct in `.env.example` and
     * is exactly the kind of thing that gets switched on for a demo and left on. "Security" in the
     * sense that matters commercially — an uncapped third-party API behind a customer-triggered
     * button is an availability and a cost incident waiting for somebody to notice, and the one
     * below has a comment in `config/signals.php` nominating this command as its home.
     */
    private function spendChecks(bool $production): void
    {
        // **The full-article read with no ceiling anywhere.** It is off by default, and a zero cap
        // is only safe *because* it is off — `config/signals.php` says so and points here. With
        // plans enforced the ceiling comes from `SignalQuotaSource` instead, so the failure is
        // specifically: the feature is on, the module's own cap is zero, and no plan is going to
        // supply one.
        $articleFetchOn = (bool) config('signals.article_fetch.enabled', false);
        $articleCap = (int) config('signals.article_fetch.monthly_cap_micros', 0);
        $plansEnforced = (bool) config('billing.enforce_plans', true);

        $this->assert(
            'article fetch has a ceiling',
            ! $articleFetchOn || $articleCap > 0 || $plansEnforced,
            'SIGNALS_ARTICLE_FETCH_MONTHLY_CAP_MICROS=… (or BILLING_ENFORCE_PLANS=true)',
        );

        // **The preview budget**, which is the one spend loop a member drives directly: a button
        // that pays for Serper searches and an AI pass on every press. Zero reads as "no budget
        // configured" in the Action, deliberately — a missing configuration is not a product
        // decision — which is precisely why it has to be checked on the machine.
        $this->assert(
            'signal preview budget set',
            ! $production || (int) config('signals.preview.daily_budget_micros', 0) > 0,
            'SIGNALS_PREVIEW_DAILY_BUDGET_MICROS=…',
        );

        // Plan enforcement off in production is a legitimate state — it is the invoiced-customer
        // case, and `config/billing.php` spells out what keeps working — but it means no tenant has
        // a lead, seat, capability or signal limit, and nobody notices on their own.
        $this->warnIf(
            'plan limits enforced',
            $production && ! $plansEnforced,
            'BILLING_ENFORCE_PLANS=true once the plans are priced',
        );
    }

    private function filled(string $key): bool
    {
        /** @var mixed $value */
        $value = config($key);

        return is_string($value) && trim($value) !== '';
    }

    private function assert(string $check, bool $passed, string $remedy): void
    {
        $this->results[] = [
            'status' => $passed ? 'ok' : 'fail',
            'check' => $check,
            'detail' => $passed ? '' : $remedy,
        ];
    }

    private function warnIf(string $check, bool $warning, string $remedy): void
    {
        $this->results[] = [
            'status' => $warning ? 'warn' : 'ok',
            'check' => $check,
            'detail' => $warning ? $remedy : '',
        ];
    }
}
