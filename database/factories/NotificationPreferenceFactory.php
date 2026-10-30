<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => Team::factory(),
            'type' => NotificationType::DiscoveryComplete,
            'in_app' => true,
            'mail' => true,
            'push' => true,
        ];
    }

    public function forType(NotificationType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /**
     * Only the given channels on — the usual shape of a test about preferences.
     *
     * @param  list<NotificationChannel>  $channels
     */
    public function only(array $channels): self
    {
        return $this->state(fn (): array => [
            'in_app' => in_array(NotificationChannel::InApp, $channels, strict: true),
            'mail' => in_array(NotificationChannel::Mail, $channels, strict: true),
            'push' => in_array(NotificationChannel::Push, $channels, strict: true),
        ]);
    }

    public function off(): self
    {
        return $this->only([]);
    }
}
