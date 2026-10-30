<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Models\User;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadsWorkspace\Events\LeadAssigned;
use App\Modules\ModuleServiceProvider;
use App\Modules\Notifications\Channels\TeamDatabaseChannel;
use App\Modules\Notifications\Console\PruneNotificationsCommand;
use App\Modules\Notifications\Console\SendDigestsCommand;
use App\Modules\Notifications\Console\SendTaskRemindersCommand;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Listeners\AnnounceDiscoveryRun;
use App\Modules\Notifications\Listeners\AnnounceLeadAssignment;
use App\Modules\Notifications\Listeners\AnnounceSignals;
use App\Modules\Notifications\Listeners\AnnounceTopLeads;
use App\Modules\Notifications\Services\FcmPushSender;
use App\Modules\Notifications\Services\NotificationReader;
use App\Modules\Scoring\Events\LeadScored;
use App\Modules\Signals\Events\SignalsDetected;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot(); // routes

        $this->shareBell();
        $this->registerProducers();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SendTaskRemindersCommand::class,
                SendDigestsCommand::class,
                PruneNotificationsCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        // Laravel's `database` channel, plus `team_id` on the row — the one thing a multi-tenant
        // bell cannot do without (ADR 0006). Bound over the default so a notification raised
        // elsewhere in the framework still writes a row, just without a team.
        $this->app->bind(DatabaseChannel::class, TeamDatabaseChannel::class);

        // Push over FCM HTTP v1, behind its own circuit breaker so an outage at Google doesn't
        // slow every notification down to a timeout each.
        $this->app->bind(PushSender::class, fn ($app): FcmPushSender => new FcmPushSender(
            $app->make(HttpFactory::class),
            $app->make(Cache::class),
            new CircuitBreaker(
                $app->make(Cache::class),
                (int) config('notifications.circuit_breaker.threshold', 5),
                (int) config('notifications.circuit_breaker.cooldown', 120),
                prefix: 'notifications:cb',
            ),
        ));
    }

    /**
     * The bell, on every page.
     *
     * Shared rather than fetched per screen because the bell is part of the app shell: every
     * controller in the product would otherwise have to remember to load it, and the one that
     * forgot would show a member zero unread while they had five.
     *
     * Lazy, so it costs nothing on a partial reload that didn't ask for it.
     */
    private function shareBell(): void
    {
        Inertia::share('notifications', function (): ?array {
            $user = Auth::user();

            if (! $user instanceof User || $user->currentTeamId() === null) {
                return null;
            }

            $team = $user->currentTeam()->first();

            if ($team === null) {
                return null;
            }

            $reader = $this->app->make(NotificationReader::class);
            $request = Request::instance();

            return [
                'unread' => $reader->unreadCount($user, $team),
                'items' => NotificationResource::collection($reader->recent($user, $team))->resolve($request),
            ];
        });
    }

    /**
     * Who raises what. Every producer lives here rather than in the module that emits the event, so
     * "what does Alima tell people about?" is one list in one file.
     */
    private function registerProducers(): void
    {
        Event::listen(DiscoveryRunCompleted::class, AnnounceDiscoveryRun::class);
        // Not queued, and almost free: all it does is dispatch the aggregating job, which is
        // `ShouldBeUnique` per team so fifty A-grade leads collapse into one notification.
        Event::listen(LeadScored::class, AnnounceTopLeads::class);
        Event::listen(LeadAssigned::class, AnnounceLeadAssignment::class);
        Event::listen(SignalsDetected::class, AnnounceSignals::class);
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Hourly, so a reminder set for "Friday at 3" arrives within the hour rather than at
            // whatever single time a nightly run happened to pick.
            $schedule->command('notifications:task-reminders')->hourly()->withoutOverlapping();

            // Hourly for the same reason, and a stronger one: the member picks an hour and the
            // team picks a timezone, so "8am" is a different moment for every team. The command
            // converts per team (see its note on timezone correctness).
            $schedule->command('notifications:digest')->hourly()->withoutOverlapping();

            // Daily, off-peak, and after the Signals sweep at 03:20 so the two aren't deleting at
            // once on a small database.
            $schedule->command('notifications:prune')->dailyAt('03:40')->withoutOverlapping();
        });
    }
}
