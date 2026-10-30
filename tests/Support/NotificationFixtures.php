<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Notifications\Testing\FakePushSender;

/**
 * Shared set-up for the Notifications tests. A class rather than global test functions, for the
 * same reason the Leads Workspace and Outreach fixtures are: half a dozen files need them and PSR-4
 * makes that explicit instead of depending on Pest's load order.
 */
final class NotificationFixtures
{
    /**
     * A verified member with their own team.
     *
     * @return array{User, Team}
     */
    public static function team(): array
    {
        $user = createUserWithTeam();

        return [$user, $user->currentTeam()->firstOrFail()];
    }

    /**
     * A second member of the team, in the given role, with it as their active team.
     */
    public static function member(Team $team, TeamRole $role = TeamRole::Member): User
    {
        $member = User::factory()->create();

        app(TeamMembership::class)->add($member, $team, $role);
        $member->forceFill(['current_team_id' => $team->getKey()])->save();

        return $member;
    }

    /**
     * Pin a member's channels for one type, so a test about delivery isn't also a test about
     * whatever the type's defaults happen to be.
     *
     * @param  list<NotificationChannel>  $channels
     */
    public static function prefer(
        User $user,
        Team $team,
        NotificationType $type,
        array $channels,
    ): NotificationPreference {
        return NotificationPreference::factory()
            ->forType($type)
            ->only($channels)
            ->create(['user_id' => $user->getKey(), 'team_id' => $team->getKey()]);
    }

    /**
     * A registered device, and the token it registered with.
     *
     * @return array{PushDevice, string}
     */
    public static function device(User $user, ?string $token = null): array
    {
        $token = $token ?? 'fcm-'.str_repeat('a', 140);

        $device = PushDevice::factory()
            ->withToken($token)
            ->create(['user_id' => $user->getKey()]);

        return [$device, $token];
    }

    /**
     * Put a {@see FakePushSender} behind the contract and return it. External providers are always
     * faked (CLAUDE.md test rules).
     */
    public static function fakePush(): FakePushSender
    {
        $fake = new FakePushSender;

        app()->instance(PushSender::class, $fake);

        return $fake;
    }
}
