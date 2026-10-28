<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use Spatie\LaravelData\Data;

final class CreateTeamData extends Data
{
    public function __construct(
        public string $name,
        public string $timezone = 'UTC',
        public string $locale = 'en',
        public string $market = 'uk',
    ) {}
}
