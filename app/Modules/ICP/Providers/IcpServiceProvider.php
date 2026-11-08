<?php

declare(strict_types=1);

namespace App\Modules\ICP\Providers;

use App\Modules\ICP\Contracts\IcpCalibrationSource;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Policies\IcpPolicy;
use App\Modules\ICP\Support\NullIcpCalibrationSource;
use App\Modules\ModuleServiceProvider;

final class IcpServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        Icp::class => IcpPolicy::class,
    ];

    public function register(): void
    {
        // "How is this profile grading our leads?" is answered by Scoring, which reads `lead_scores`.
        // Bound permissively here (an empty calibration) and replaced by `ScoringServiceProvider`,
        // which registers after this one — so ICP never imports Scoring, and the dependency keeps
        // pointing one way (Scoring already depends on ICP).
        $this->app->bind(IcpCalibrationSource::class, NullIcpCalibrationSource::class);
    }
}
