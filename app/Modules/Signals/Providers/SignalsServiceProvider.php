<?php

declare(strict_types=1);

namespace App\Modules\Signals\Providers;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\ModuleServiceProvider;
use App\Modules\Signals\Console\DispatchDueSignalWatchesCommand;
use App\Modules\Signals\Console\PruneSignalsCommand;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Policies\SignalPolicy;
use App\Modules\Signals\Policies\SignalWatchPolicy;
use App\Modules\Signals\Services\SerperNewsClient;
use App\Modules\Signals\Services\SignalItemNormaliser;
use App\Modules\Signals\Services\SignalLeadOriginProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class SignalsServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        SignalWatch::class => SignalWatchPolicy::class,
        Signal::class => SignalPolicy::class,
    ];

    public function boot(): void
    {
        parent::boot(); // routes + policies

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchDueSignalWatchesCommand::class, PruneSignalsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Hourly: queue a run for each active watch whose cadence is due (the command decides
            // which).
            $schedule->command('signals:dispatch-due')->hourly()->withoutOverlapping();

            // Daily, off-peak: the retention sweep (S6). Dismissed signals go after 90 days and
            // unactioned ones after 180 — a six-month-old funding round isn't a trigger event any
            // more, and keeping a named person's details because nobody clicked is exactly the
            // retention data minimisation exists to prevent. Converted signals are never swept.
            $schedule->command('signals:prune')->dailyAt('03:20')->withoutOverlapping();
        });

        // "Run now" spends real money on Serper and the AI. `TriggerSignalWatchAction` throttles
        // each watch to once per 15 minutes; this caps how fast one member can trigger runs across
        // *all* their watches. Per member rather than per IP, so a shared office doesn't throttle
        // itself.
        RateLimiter::for('signals-run', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('signals.run_rate_limit', 10)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    public function register(): void
    {
        // The news client talks to Serper /news behind its own circuit breaker (separate from Lead
        // Sourcing's), configured from config/signals.php. Reuses the generic LeadSourcing breaker.
        $this->app->bind(NewsSearchClient::class, fn ($app): SerperNewsClient => new SerperNewsClient(
            $app->make(HttpFactory::class),
            new CircuitBreaker(
                $app->make(Cache::class),
                (int) config('signals.circuit_breaker.threshold', 5),
                (int) config('signals.circuit_breaker.cooldown', 60),
                prefix: 'signals:cb',
            ),
            $app->make(SignalItemNormaliser::class),
        ));

        // Tell the Leads Workspace how to show a converted signal on the lead detail screen (S4).
        // The dependency points this way on purpose: Signals already knows about leads because it
        // creates them, and the workspace must not learn about Signals to render a panel.
        $this->app->bind(LeadOriginProvider::class, SignalLeadOriginProvider::class);
    }
}
