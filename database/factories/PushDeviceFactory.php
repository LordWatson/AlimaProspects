<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Notifications\Enums\PushPlatform;
use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushDevice>
 */
class PushDeviceFactory extends Factory
{
    protected $model = PushDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Long and random, like a real FCM token — and hashed the way the action does it, so a
        // test never passes against a row the production lookup would miss.
        $token = 'fcm-'.Str::random(140);

        return [
            'user_id' => User::factory(),
            'token' => $token,
            'token_hash' => PushDevice::hash($token),
            'platform' => PushPlatform::Ios,
            'last_used_at' => now(),
        ];
    }

    public function withToken(string $token): self
    {
        return $this->state(fn (): array => [
            'token' => $token,
            'token_hash' => PushDevice::hash($token),
        ]);
    }

    public function platform(PushPlatform $platform): self
    {
        return $this->state(fn (): array => ['platform' => $platform]);
    }

    public function stale(int $days = 200): self
    {
        return $this->state(fn (): array => ['last_used_at' => now()->subDays($days)]);
    }
}
