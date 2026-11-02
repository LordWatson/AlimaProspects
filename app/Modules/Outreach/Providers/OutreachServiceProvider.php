<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Providers;

use App\Modules\ModuleServiceProvider;
use App\Modules\Outreach\Contracts\LeadRestrictionResolver;
use App\Modules\Outreach\Contracts\TeamSendingGate;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Models\OutreachTemplate;
use App\Modules\Outreach\Policies\OutreachMessagePolicy;
use App\Modules\Outreach\Policies\OutreachSuppressionPolicy;
use App\Modules\Outreach\Policies\OutreachTemplatePolicy;
use App\Modules\Outreach\Services\AlwaysAllowTeamSending;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Outreach\Services\RecordedLeadRestriction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class OutreachServiceProvider extends ModuleServiceProvider
{
    /**
     * {@see ContactAttempt} has no policy of its own on purpose:
     * every ability over one is "may this member work this lead", which `LeadPolicy` already
     * answers, and a second policy saying the same thing is a second place for the two to drift
     * apart.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        OutreachTemplate::class => OutreachTemplatePolicy::class,
        OutreachMessage::class => OutreachMessagePolicy::class,
        OutreachSuppression::class => OutreachSuppressionPolicy::class,
    ];

    public function boot(): void
    {
        parent::boot(); // routes + policies

        // Drafting spends AI credit; sending spends the platform's sending reputation, which is
        // shared by every team on it. Both per member rather than per IP, so a shared office
        // doesn't throttle itself.
        RateLimiter::for('outreach-draft', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('outreach.draft_rate_limit', 30)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('outreach-send', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('outreach.send_rate_limit', 60)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    public function register(): void
    {
        // What limits how a lead may be approached. Outreach's own answer is the flag recorded on
        // the lead (Phase 11); Compliance (Phase 15) binds a market-aware resolver over it. The
        // seam exists so {@see OutreachGuard} asks a question rather than reading a column — and so
        // this module stands up on its own, which is what makes the default binding the right place
        // for it.
        $this->app->bind(LeadRestrictionResolver::class, RecordedLeadRestriction::class);

        // Whether a *team* may send at all, as opposed to whether this prospect may be approached.
        // Permissive by default, like Auth's seat stub: a default that refused would block outreach
        // for a suspension nobody applied. Admin (Phase 19a) binds a suspension-aware gate over it.
        $this->app->bind(TeamSendingGate::class, AlwaysAllowTeamSending::class);
    }
}
