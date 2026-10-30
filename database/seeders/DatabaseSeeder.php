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
        // Global spatie roles + the AI prompt registry first, then a full, explorable demo tenancy.
        $this->call([
            TeamRolesSeeder::class,
            AiPromptTemplateSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
