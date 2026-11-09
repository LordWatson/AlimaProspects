<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Models\WebhookEndpoint;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Counts consecutive failures per endpoint and switches off the ones that are gone (PL7).
 *
 * Its own class rather than two methods on the job, because the auto-disable is the one decision in
 * the outbound half with a consequence a customer notices and it should be readable in one place.
 *
 * **Consecutive, which is the whole design.** A success resets the count to zero, so the threshold
 * means "this endpoint has not worked for twenty tries running" rather than "this endpoint has had
 * twenty bad days since March". Counting cumulatively would eventually disable every endpoint that
 * has ever been deployed behind a flaky proxy.
 *
 * **Counted per delivery, not per attempt.** One delivery is already six attempts over thirteen
 * hours, so twenty of them is days of a dead URL — which is the point: disabling an endpoint stops
 * a customer's automation, and doing it over an afternoon's outage would be worse than the problem.
 *
 * Disabling **notifies the team's admins**, because a switched-off endpoint is silent by definition
 * and the alternative is a team discovering in March that nothing has reached their spreadsheet
 * since January.
 */
final class WebhookFailureCounter
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function succeeded(WebhookEndpoint $endpoint): void
    {
        // Both, unconditionally. An earlier version returned early when the count was already
        // zero, which is the common case — so the first successful delivery to a healthy endpoint
        // never stamped `last_delivered_at`, and the screen said "never delivered" about an
        // endpoint that was working perfectly.
        $endpoint->forceFill([
            'failure_count' => 0,
            'last_delivered_at' => now(),
        ])->save();
    }

    public function failed(WebhookEndpoint $endpoint): void
    {
        // `increment()` rather than read-modify-write: five events can be failing against one
        // endpoint at once, and a read of 7 in each of five workers writes 8 five times. The
        // increment is atomic and `refresh()` then reads what it actually became, so twenty really
        // means twenty.
        $endpoint->increment('failure_count');
        $endpoint->refresh();

        $count = $endpoint->failure_count;
        $threshold = $this->threshold();

        if ($count < $threshold) {
            return;
        }

        // `is_active` is left alone and `disabled_at` is what stops it. The two are different
        // facts: `is_active` is the member's own switch, and overwriting it would mean a member who
        // re-enables an endpoint cannot tell whether they or we turned it off.
        $endpoint->forceFill(['disabled_at' => now()])->save();

        Log::warning('Webhook endpoint auto-disabled.', [
            'endpoint_id' => $endpoint->getKey(),
            'team_id' => $endpoint->team_id,
            'failures' => $count,
        ]);

        $team = $endpoint->team;

        if ($team === null) {
            return;
        }

        $this->notifications->toAdmins($team, NotificationType::WebhookDisabled, [
            'endpoint_id' => (int) $endpoint->getKey(),
            'failures' => $count,
        ]);
    }

    private function threshold(): int
    {
        return max(1, (int) config('integrations.webhooks.max_consecutive_failures', 20));
    }
}
