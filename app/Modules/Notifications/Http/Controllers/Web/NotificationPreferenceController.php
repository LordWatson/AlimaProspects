<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Actions\SaveNotificationPreferencesAction;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationGroup;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Http\Requests\SaveNotificationPreferencesRequest;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Notifications\Services\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification settings screen (Phase 12), under `settings/`.
 *
 * It sends the **effective** channels for every type — what *will* happen — rather than only the
 * rows that exist. A screen that showed an unset type as "off" would be lying, and a member who had
 * never visited it would see everything switched off while notifications arrived.
 */
final class NotificationPreferenceController extends Controller
{
    public function edit(Request $request, NotificationPreferences $preferences): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $settings = $preferences->settingsFor($user, $team);

        return Inertia::render('settings/Notifications', [
            // Effective channels per type, defaults included.
            'preferences' => $preferences->allFor($user, $team),
            'digest' => [
                'frequency' => $settings->digest->value,
                'hour' => $settings->digest_hour,
                'sent_at' => $settings->digest_sent_at?->toIso8601String(),
            ],
            'mutedUntil' => $settings->muted_until?->toIso8601String(),
            'options' => [
                'types' => $this->typeOptions($user, $team),
                'channels' => array_map(static fn (NotificationChannel $channel): array => [
                    'value' => $channel->value,
                    'label' => $channel->label(),
                    'description' => $channel->description(),
                ], NotificationChannel::cases()),
                'digests' => array_map(static fn (DigestFrequency $frequency): array => [
                    'value' => $frequency->value,
                    'label' => $frequency->label(),
                ], DigestFrequency::cases()),
                'groups' => array_map(static fn (NotificationGroup $group): array => [
                    'value' => $group->value,
                    'label' => $group->label(),
                ], NotificationGroup::cases()),
            ],
            // The hour is in the team's timezone, and the screen has to say which (CLAUDE.md §2).
            'timezone' => $team->timezone,
            // So the screen can explain a push switch that will not do anything yet, rather than
            // leaving a member to wonder why nothing arrives.
            'pushDevices' => PushDevice::query()->where('user_id', $user->getKey())->count(),
        ]);
    }

    public function update(
        SaveNotificationPreferencesRequest $request,
        SaveNotificationPreferencesAction $save,
    ): RedirectResponse {
        $save->handle($this->user($request), $this->team($request), $request->preferencesData());

        return back()->with('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);
    }

    /**
     * The configurable types, each with whether this member can actually receive it — so the screen
     * can say "owners and admins only" rather than offering a switch that does nothing.
     *
     * @return list<array{value: string, label: string, description: string, group: string, available: bool}>
     */
    private function typeOptions(User $user, Team $team): array
    {
        $role = $user->roleOn($team);

        return array_map(static fn (NotificationType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'group' => $type->group()->value,
            'available' => ! $type->isAdminOnly()
                || ($role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true)),
        ], NotificationType::configurable());
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }
}
