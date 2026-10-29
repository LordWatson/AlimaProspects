<?php

declare(strict_types=1);

namespace App\Modules\ICP\Providers;

use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Policies\IcpPolicy;
use App\Modules\ModuleServiceProvider;

final class IcpServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        Icp::class => IcpPolicy::class,
    ];
}
