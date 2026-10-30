<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Models\NotificationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationSetting>
 */
class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => Team::factory(),
            'digest' => DigestFrequency::Off,
            'digest_hour' => 8,
            'digest_sent_at' => null,
            'muted_until' => null,
        ];
    }

    public function digest(DigestFrequency $frequency, int $hour = 8): self
    {
        return $this->state(fn (): array => ['digest' => $frequency, 'digest_hour' => $hour]);
    }

    public function muted(): self
    {
        return $this->state(fn (): array => ['muted_until' => now()->addDay()]);
    }
}
