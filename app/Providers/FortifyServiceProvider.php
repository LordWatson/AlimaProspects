<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

        // Global /api/v1 limiter — **two** limits, both of which have to hold.
        //
        // Per *token*, because a member's phone and a long-running integration are different
        // callers (CLAUDE.md §2): letting a script's retry loop exhaust the allowance the phone in
        // their pocket is using would take the app down for the person, not for the script.
        //
        // And per *user* over the top, because a person can mint as many tokens as they like from
        // the settings screen — a per-token limit alone is a per-token limit anyone can multiply by
        // asking for another token. The ceiling is three tokens' worth: generous for a phone plus a
        // couple of integrations, and still a bound on what one account can do to the database.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            if ($user === null) {
                return Limit::perMinute(60)->by((string) $request->ip());
            }

            // Keyed off the presented credential rather than the resolved token model: a hash of
            // the bearer value is stable per token, needs no second lookup, and keeps the
            // credential itself out of the cache key. No bearer value means a first-party session.
            $bearer = (string) $request->bearerToken();

            $callerKey = $bearer === ''
                ? 'session:'.$user->getAuthIdentifier()
                : 'token:'.hash('xxh128', $bearer);

            return [
                Limit::perMinute(60)->by($callerKey),
                Limit::perMinute(180)->by('user:'.$user->getAuthIdentifier()),
            ];
        });

        // Hard limit on credential → token exchange to resist brute force.
        RateLimiter::for('auth-token', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });
    }
}
