<?php

declare(strict_types=1);

namespace App\Modules\Signals\Providers;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Core\Support\RobotsPolicy;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\ModuleServiceProvider;
use App\Modules\Signals\Console\AdviseSignalTuningCommand;
use App\Modules\Signals\Console\ClusterSignalsBackfillCommand;
use App\Modules\Signals\Console\DispatchDueSignalWatchesCommand;
use App\Modules\Signals\Console\PruneSignalsCommand;
use App\Modules\Signals\Console\RescoreSignalsCommand;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Events\SignalConverted;
use App\Modules\Signals\Events\SignalDismissed;
use App\Modules\Signals\Events\SignalRestored;
use App\Modules\Signals\Events\SignalsDetected;
use App\Modules\Signals\Jobs\SyncPipelineWatchlistsJob;
use App\Modules\Signals\Listeners\RecordSignalOutcome;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalTuningSuggestion;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Policies\SignalDefinitionPolicy;
use App\Modules\Signals\Policies\SignalPolicy;
use App\Modules\Signals\Policies\SignalTuningSuggestionPolicy;
use App\Modules\Signals\Policies\SignalWatchlistPolicy;
use App\Modules\Signals\Policies\SignalWatchPolicy;
use App\Modules\Signals\Services\SerperNewsClient;
use App\Modules\Signals\Services\SignalArticleEnricher;
use App\Modules\Signals\Services\SignalItemNormaliser;
use App\Modules\Signals\Services\SignalLeadOriginProvider;
use App\Modules\Signals\Services\SignalScorer;
use App\Modules\Signals\Support\IgnoredDomains;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class SignalsServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        SignalWatch::class => SignalWatchPolicy::class,
        Signal::class => SignalPolicy::class,
        SignalDefinition::class => SignalDefinitionPolicy::class,
        SignalWatchlist::class => SignalWatchlistPolicy::class,
        SignalTuningSuggestion::class => SignalTuningSuggestionPolicy::class,
    ];

    public function boot(): void
    {
        parent::boot(); // routes + policies

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchDueSignalWatchesCommand::class,
                PruneSignalsCommand::class,
                // S11, and deliberately **not** scheduled: a backfill is a one-off an operator
                // runs when the phase ships, and a nightly re-clustering of a stable archive
                // would be work nobody asked for.
                ClusterSignalsBackfillCommand::class,
                // S12: the weekly advisor, and the one-off rescore. Only the first is scheduled —
                // see `RescoreSignalsCommand` for why re-ranking a team's feed is an operator's
                // deliberate act rather than something that happens to them overnight.
                AdviseSignalTuningCommand::class,
                RescoreSignalsCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Hourly: queue a run for each active watch whose cadence is due (the command decides
            // which).
            $schedule->command('signals:dispatch-due')->hourly()->withoutOverlapping();

            // Nightly, **before** the small hours when most daily watches come due (S10): bring
            // every pipeline watchlist in step with its team's leads, so a company added to the
            // pipeline today is being watched tomorrow morning rather than the morning after.
            // Idempotent and `ShouldBeUnique`, so this and a manual sync cannot race.
            $schedule->job(new SyncPipelineWatchlistsJob)->dailyAt('02:40')->withoutOverlapping();

            // Daily, off-peak: the retention sweep (S6). Dismissed signals go after 90 days and
            // unactioned ones after 180 — a six-month-old funding round isn't a trigger event any
            // more, and keeping a named person's details because nobody clicked is exactly the
            // retention data minimisation exists to prevent. Converted signals are never swept.
            $schedule->command('signals:prune')->dailyAt('03:20')->withoutOverlapping();

            // Weekly, not nightly (S12). The evidence a tuning proposal rests on accumulates over
            // weeks — eight dismissals of one outlet is a fortnight's feed for most teams — and a
            // panel that re-proposed every morning would be indistinguishable from nagging. Monday
            // early, so a suggestion is waiting when somebody opens the week.
            $schedule->command('signals:advise')->weeklyOn(1, '04:10')->withoutOverlapping();
        });

        // "Run now" spends real money on Serper and the AI. `TriggerSignalWatchAction` throttles
        // each watch to once per 15 minutes; this caps how fast one member can trigger runs across
        // *all* their watches. Per member rather than per IP, so a shared office doesn't throttle
        // itself.
        RateLimiter::for('signals-run', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('signals.run_rate_limit', 10)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // The builder's two spending buttons (S9). **Per hour, not per minute**, which is the
        // difference between these and `signals-run`: a run is an occasional deliberate act, while
        // drafting and previewing are things somebody does repeatedly in one sitting — so the
        // window has to be long enough to make iterating affordable and short enough to make a
        // loop pointless. Per member rather than per IP, so a shared office does not throttle
        // itself.
        RateLimiter::for('signals-compose', fn (Request $request): Limit => Limit::perHour(
            max(1, (int) config('signals.composer.rate_limit', 20)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('signals-preview', fn (Request $request): Limit => Limit::perHour(
            max(1, (int) config('signals.preview.rate_limit', 10)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        $this->recordOutcomes();
    }

    /**
     * What became of a signal, recorded so the product can learn which outlets are worth reading
     * (S12).
     *
     * Four events because there are four outcomes, all handled by one listener — and all
     * registered here rather than in Notifications, because this is the module whose own
     * behaviour the statistics describe. Notifications listens to two of the same events for its
     * own reasons; neither registration knows about the other.
     */
    private function recordOutcomes(): void
    {
        Event::listen(SignalsDetected::class, [RecordSignalOutcome::class, 'keep']);
        Event::listen(SignalDismissed::class, [RecordSignalOutcome::class, 'dismiss']);
        Event::listen(SignalRestored::class, [RecordSignalOutcome::class, 'restore']);
        Event::listen(SignalConverted::class, [RecordSignalOutcome::class, 'convert']);
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
            $app->make(IgnoredDomains::class),
        ));

        // The full-article reader (S12). Its own breaker key under the module's own prefix, so a
        // publisher timing out repeatedly stops being fetched without touching the news
        // provider's breaker — the two failures have nothing to do with each other and sharing
        // state would make one look like the other.
        $this->app->bind(SignalArticleEnricher::class, fn ($app): SignalArticleEnricher => new SignalArticleEnricher(
            $app->make(WebContentFetcher::class),
            $app->make(RobotsPolicy::class),
            $app->make(AiClient::class),
            new CircuitBreaker(
                $app->make(Cache::class),
                (int) config('signals.circuit_breaker.threshold', 5),
                (int) config('signals.circuit_breaker.cooldown', 60),
                prefix: 'signals:cb',
            ),
            // The scorer, because a signal whose amount and role the full article just corrected
            // has to be re-scored: those fields are inputs to four of the eight components, and
            // the card would otherwise show a $40m deal beside a score that described the one
            // with no amount in it.
            $app->make(SignalScorer::class),
        ));

        // `robots.txt`, read through the same SSRF-safe seam everything else fetches with. Bound
        // explicitly because it needs the cache repository, and left in Core because it is nobody's
        // domain rule — see the class for why it is **not** wired into the fetcher itself.
        $this->app->bind(RobotsPolicy::class, fn ($app): RobotsPolicy => new RobotsPolicy(
            $app->make(WebContentFetcher::class),
            $app->make(Cache::class),
        ));

        // Tell the Leads Workspace how to show a converted signal on the lead detail screen (S4).
        // The dependency points this way on purpose: Signals already knows about leads because it
        // creates them, and the workspace must not learn about Signals to render a panel.
        $this->app->bind(LeadOriginProvider::class, SignalLeadOriginProvider::class);
    }
}
