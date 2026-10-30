<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the notification tables from growing for ever (Phase 12).
 *
 * Two sweeps, with different reasons for existing:
 *
 * - **Read notifications older than the retention window.** A six-month-old "your search is done"
 *   is of no use to anybody and the table is one of the fastest-growing in the product. Unread ones
 *   are kept whatever their age — a member who has not looked has not been told, and deleting it
 *   would mean they never are.
 * - **Push devices nothing has been sent to in months.** A stale token is a send that silently goes
 *   nowhere; the channel deletes the ones the provider rejects outright, and this catches the rest
 *   (an app a member stopped opening, a phone they replaced without signing out).
 *
 * Chunked and idempotent, like `signals:prune`. `--dry-run` reports without deleting, because
 * deletion is irreversible.
 */
final class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete old read notifications and stale push devices.';

    /** Per pass, so a big table is swept without one long-held lock. */
    private const int CHUNK = 500;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $notificationDays = max(1, (int) config('notifications.retention.read_days', 90));
        $deviceDays = max(1, (int) config('notifications.retention.device_days', 180));

        $notificationCutoff = now()->subDays($notificationDays);
        $deviceCutoff = now()->subDays($deviceDays);

        // Read only. An unread notification is one the member has never been shown.
        $notificationQuery = Notification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $notificationCutoff);

        $deviceQuery = PushDevice::query()
            ->where(function ($query) use ($deviceCutoff): void {
                $query->where('last_used_at', '<', $deviceCutoff)
                    // A device that registered and was never sent to, long enough ago that the
                    // app is plainly gone.
                    ->orWhere(function ($inner) use ($deviceCutoff): void {
                        $inner->whereNull('last_used_at')->where('created_at', '<', $deviceCutoff);
                    });
            });

        if ($dryRun) {
            $notifications = $notificationQuery->count();
            $devices = $deviceQuery->count();

            $this->info("Would delete {$notifications} read notifications and {$devices} stale devices.");

            return self::SUCCESS;
        }

        $notifications = 0;
        while (($deleted = $notificationQuery->clone()->limit(self::CHUNK)->delete()) > 0) {
            $notifications += $deleted;
        }

        $devices = 0;
        while (($deleted = $deviceQuery->clone()->limit(self::CHUNK)->delete()) > 0) {
            $devices += $deleted;
        }

        // Counts and windows only — never a token or a notification's contents.
        Log::channel('notifications')->info('Notifications pruned.', [
            'notifications' => $notifications,
            'devices' => $devices,
            'read_days' => $notificationDays,
            'device_days' => $deviceDays,
        ]);

        $this->info("Deleted {$notifications} read notifications and {$devices} stale devices.");

        return self::SUCCESS;
    }
}
