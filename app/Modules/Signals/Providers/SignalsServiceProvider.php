<?php

declare(strict_types=1);

namespace App\Modules\Signals\Providers;

use App\Modules\ModuleServiceProvider;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Policies\SignalPolicy;
use App\Modules\Signals\Policies\SignalWatchPolicy;

final class SignalsServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        SignalWatch::class => SignalWatchPolicy::class,
        Signal::class => SignalPolicy::class,
    ];
}
