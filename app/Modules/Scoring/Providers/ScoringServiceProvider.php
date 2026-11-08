<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Providers;

use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\ICP\Contracts\IcpCalibrationSource;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\ModuleServiceProvider;
use App\Modules\Scoring\Contracts\LeadSignalSource;
use App\Modules\Scoring\Listeners\RescoreTeamOnIcpChange;
use App\Modules\Scoring\Listeners\ScoreEnrichedLead;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Policies\LeadScorePolicy;
use App\Modules\Scoring\Services\IcpCalibrator;
use App\Modules\Scoring\Services\NoLeadSignals;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Scoring & Matching module provider (Phase 9). Registers the score policy, the `scoring` rate
 * limiter guarding manual re-scores, and the two re-score triggers: a lead whose enriched data
 * changed, and a team whose active ICP changed. Both triggers only ever dispatch queued work — no
 * scoring happens in the request that fired the event.
 */
final class ScoringServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        LeadScore::class => LeadScorePolicy::class,
    ];

    public function register(): void
    {
        // What a lead has been seen *doing* (Q3). Bound permissively here and replaced by
        // `SignalsServiceProvider`, which registers after this one — so Scoring never imports
        // Signals, and a build without it grades buying signals exactly as it did before: Unknown.
        $this->app->bind(LeadSignalSource::class, NoLeadSignals::class);

        // The real answer to the ICP module's calibration seam: reads `lead_scores`. ICP binds a
        // null default and this registers after it, so the ICP screen shows how its active profile
        // is actually grading leads without ICP ever depending on Scoring.
        $this->app->bind(IcpCalibrationSource::class, IcpCalibrator::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(LeadEnriched::class, ScoreEnrichedLead::class);
        Event::listen(IcpUpdated::class, RescoreTeamOnIcpChange::class);

        // A manual re-score spends AI credit, so cap how fast one member can ask for them. The AI
        // Platform's credit guard still bounds the team's total spend; this stops one impatient
        // member burning through it a click at a time.
        RateLimiter::for('scoring', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('scoring.rescore_rate_limit', 10)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
