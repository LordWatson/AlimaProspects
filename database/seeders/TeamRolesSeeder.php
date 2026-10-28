<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Core\Enums\TeamRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the four team roles as global spatie roles (team_id = null) so they can be assigned
 * within any team context. Idempotent — safe to run repeatedly.
 */
class TeamRolesSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            foreach (TeamRole::cases() as $role) {
                Role::findOrCreate($role->value, 'web');
            }
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
