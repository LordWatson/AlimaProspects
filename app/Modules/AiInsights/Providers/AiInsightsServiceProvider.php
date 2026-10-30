<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Providers;

use App\Modules\AiInsights\Console\GenerateInsightsCommand;
use App\Modules\AiInsights\Console\PruneInsightsCommand;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\AiInsights\Policies\InsightPolicy;
use App\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

/**
 * AI Insights (Phase 14). Registers the feed's routes and policy, the two commands, and the
 * schedule.
 *
 * Nothing is bound in `register()`: the module has no provider abstraction of its own — it reads
 * Reporting and calls the AI Platform's `AiClient`, both of which own their own seams.
 */
final class AiInsightsServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        Insight::class => InsightPolicy::class,
    ];

    public function boot(): void
    {
        parent::boot(); // routes + policies

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateInsightsCommand::class, PruneInsightsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Hourly, because "7am" means 7am wherever the team is: the command converts `now()`
            // into each team's timezone and queues a run for the ones whose morning it is. A
            // single nightly run would reach Dubai at lunchtime (see the command, and the same
            // note in `notifications:digest`).
            $schedule->command('insights:generate')->hourly()->withoutOverlapping();

            // Daily, off-peak: the retention sweep. Insights are derived and go stale fast, so
            // they are swept harder than anything else — a week-old "contact these five today" is
            // misleading rather than merely old (see `InsightRetention`).
            $schedule->command('insights:prune')->dailyAt('03:50')->withoutOverlapping();
        });
    }
}
