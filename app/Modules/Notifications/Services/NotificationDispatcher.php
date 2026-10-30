<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Notifications\TeamNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * The one entry point other modules call to tell somebody something (Phase 12).
 *
 * Every other module depends on **this** rather than on Laravel's notification facade, for the
 * reason the module doc gives: raising a notification should be one line that cannot get the
 * tenancy, the preferences or the recipient set wrong. Producers say *what happened and to whom*;
 * everything about *whether and how* it is delivered is settled here and in
 * {@see NotificationPreferences}.
 *
 * `toTeam()` and `toAdmins()` exist because the most common mistakes are about the audience:
 * notifying a whole team about one member's reminder, or notifying a plain member about billing.
 * Having the right recipient set be the easy thing to type is most of the fix.
 */
final class NotificationDispatcher
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * Notify specific members. Anyone no longer in the team is dropped silently — a stale assignee
     * is not an error worth failing a queued job over.
     *
     * @param  iterable<User>|User  $recipients
     * @param  array<string, mixed>  $payload  Ids, counts and names — never a formed sentence.
     */
    public function send(
        Team $team,
        iterable|User $recipients,
        NotificationType $type,
        array $payload = [],
    ): int {
        $users = $this->members($team, $recipients);

        if ($users->isEmpty()) {
            return 0;
        }

        // `via()` on the notification asks each member's preferences, so a member who wants nothing
        // is sent nothing rather than sent and discarded. The facade still has to be handed them,
        // because preferences are per member and this is one notification for many.
        NotificationFacade::send($users->all(), new TeamNotification($team, $type, $payload));

        Log::channel('notifications')->info('Notification raised.', [
            'team_id' => $team->getKey(),
            'type' => $type->value,
            'recipients' => $users->count(),
        ]);

        return $users->count();
    }

    /**
     * Notify everybody in the team. For things that are the team's business — a finished search,
     * new strong matches — rather than one person's.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toTeam(Team $team, NotificationType $type, array $payload = []): int
    {
        return $this->send($team, $team->users()->get(), $type, $payload);
    }

    /**
     * Notify the Owners and Admins. For money and configuration, which a plain member can neither
     * act on nor be expected to care about.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toAdmins(Team $team, NotificationType $type, array $payload = []): int
    {
        $admins = $team->users()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->get();

        return $this->send($team, $admins, $type, $payload);
    }

    /**
     * Whether this member would receive this type at all — the question the digest asks before
     * including a line, so a member who switched discovery notifications off is not told about
     * discovery runs in the round-up either.
     */
    public function wants(User $user, Team $team, NotificationType $type): bool
    {
        return $this->preferences->wants($user, $team, $type);
    }

    /**
     * The recipients who are actually still members of the team.
     *
     * Filtered here rather than trusted from the caller: the common producers are queued listeners
     * reading ids off an event, and a member who left between the event and the job must not be
     * notified about a team they are no longer in.
     *
     * @param  iterable<User>|User  $recipients
     * @return Collection<int, User>
     */
    private function members(Team $team, iterable|User $recipients): Collection
    {
        $users = $recipients instanceof User
            ? collect([$recipients])
            : collect($recipients);

        /** @var list<int> $memberIds */
        $memberIds = $team->users()
            ->whereIn('users.id', $users->map(static fn (User $user): int => (int) $user->getKey())->all())
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $users
            ->filter(static fn (User $user): bool => in_array((int) $user->getKey(), $memberIds, strict: true))
            ->unique(static fn (User $user): int => (int) $user->getKey())
            ->values();
    }
}
