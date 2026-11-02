<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Global spatie roles, the AI prompt registry and the signal-definition presets first,
        // then the platform operator, then a full, explorable demo tenancy.
        //
        // All three of the first group are **platform reference data**: they belong to the product
        // rather than to any tenant, and every install needs them. The signal presets in particular
        // are what a team clones to get their own signal types (ADR 0018), so a box without them
        // has a Signals feature nobody can set up.
        $this->call([
            TeamRolesSeeder::class,
            AiPromptTemplateSeeder::class,
            SignalPresetSeeder::class,
        ]);

        // **Skipped in production rather than attempted and refused.** `SuperAdminSeeder` throws
        // there, by design — it plants a known password — but calling it from here unconditionally
        // would abort the whole run, so a `db:seed --force` on a production box to register the
        // roles or the prompt registry would take the rest of this list down with it.
        //
        // The super admin comes **before** the demo data: it belongs to no team, so it must not be
        // able to inherit a `current_team_id` from whatever the demo seeder leaves behind.
        if (! app()->isProduction()) {
            $this->call(SuperAdminSeeder::class);
        }

        $this->call(DemoSeeder::class);
    }
}
