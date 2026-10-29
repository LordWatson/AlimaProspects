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
        // Global spatie roles first, then a full, explorable demo tenancy (Alex Watson + teams).
        $this->call([
            TeamRolesSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
