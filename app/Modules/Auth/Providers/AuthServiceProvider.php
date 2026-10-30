<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Contracts\SeatLimitSource;
use App\Modules\Auth\Policies\TeamPolicy;
use App\Modules\Auth\Services\UnlimitedSeatLimit;
use App\Modules\Core\Models\Team;
use App\Modules\ModuleServiceProvider;

/**
 * Auth & Users module provider. Registers team-management policies and (via the base provider)
 * the module's web + API routes.
 */
final class AuthServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        Team::class => TeamPolicy::class,
    ];

    public function register(): void
    {
        // The seat seam, defaulting to no limit. Billing binds a plan-backed source over this one
        // (Phase 16) — mirrors AI Platform's `CreditSource` and Lead Sourcing's `LeadQuotaSource`,
        // and keeps this module free of any idea of what a plan is.
        $this->app->bind(SeatLimitSource::class, UnlimitedSeatLimit::class);
    }
}
