<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Policies\TeamPolicy;
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
}
