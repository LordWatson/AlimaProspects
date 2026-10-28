<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Modules\Core\Enums\TeamRole;
use Spatie\LaravelData\Data;

final class InviteMemberData extends Data
{
    public function __construct(
        public string $email,
        public TeamRole $role,
    ) {}
}
