<?php

use App\Modules\Auth\Providers\AuthServiceProvider as AuthModuleServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,

    // Domain modules (ADR 0002) — register each module's provider here.
    CoreServiceProvider::class,
    AuthModuleServiceProvider::class,
];
