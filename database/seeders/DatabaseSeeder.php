<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Actions\CreatePersonalTeamAction;
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
        $this->call(TeamRolesSeeder::class);

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Give the seeded user a personal team with the Owner role, as registration would.
        app(CreatePersonalTeamAction::class)->handle($user);
    }
}
