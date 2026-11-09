<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Console;

use App\Modules\Integrations\Enums\DeliveryState;
use App\Modules\Integrations\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes old webhook deliveries (PL7).
 *
 * This table is written **once per event per subscribed endpoint**, and `lead.created` fires once
 * per company — so it is the fastest-growing table the product has, and every row holds a copy of a
 * lead's payload. It is also the only one of PL7's five tables that may be pruned at all:
 * `integration_links` is a memory losing which creates duplicates in somebody else's CRM, and the
 * other three are small.
 *
 * **A pending delivery is never swept, whatever its age.** It is live state a worker may be about
 * to claim — the same rule `signal_runs` follows, swept on `finished_at` and never on age — and a
 * twelve-hour backoff means a row can legitimately sit pending most of a day.
 *
 * The eligibility query is re-run at each pass rather than reading ids once and deleting by id,
 * which is the bug `compliance:purge-expired` has already had.
 */
final class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'integrations:prune-deliveries
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete webhook delivery records past their retention window.';

    /** Per pass, so a long backlog is swept without one long-held lock. */
    private const int CHUNK = 500;

    public function handle(): int
    {
        $days = max(1, (int) config('integrations.webhooks.retain_days', 30));
        $cutoff = now()->subDays($days);

        $query = WebhookDelivery::query()
            ->withoutGlobalScopes()
            ->whereIn('state', [DeliveryState::Delivered->value, DeliveryState::Failed->value])
            ->where('created_at', '<', $cutoff);

        if ((bool) $this->option('dry-run')) {
            $this->info('Would delete '.$query->count().' webhook deliveries.');

            return self::SUCCESS;
        }

        $deleted = 0;

        while (true) {
            $removed = $query->clone()->limit(self::CHUNK)->delete();

            if ($removed === 0) {
                break;
            }

            $deleted += $removed;
        }

        Log::info('Webhook deliveries pruned.', ['deliveries' => $deleted, 'retain_days' => $days]);

        $this->info("Deleted {$deleted} webhook deliveries.");

        return self::SUCCESS;
    }
}
