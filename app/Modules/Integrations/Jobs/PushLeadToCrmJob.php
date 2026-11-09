<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Jobs;

use App\Modules\Integrations\Actions\SyncLeadToCrmAction;
use App\Modules\Integrations\Models\Integration;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One lead, one CRM, in the background (PL7).
 *
 * Ids rather than models, so a lead deleted between the dispatch and the run is a quiet return
 * instead of a failed job — and so a retry reads the row as it is now.
 *
 * **Unique per (integration, lead).** Duplicates here are not merely wasteful: an auto-sync rule
 * and a member's own button can fire within a second of each other, and two concurrent pushes of
 * the same lead race on `integration_links` — the loser would create a second organisation in
 * somebody's CRM, which is the one failure in this module we cannot clean up afterwards. The window
 * is an hour, which is far longer than a push and far shorter than a re-score cycle.
 *
 * On the **`default` queue** rather than the plan's named `integrations` one: ADR 0026 §4 holds one
 * queue until PL12 has real depth numbers to split on.
 *
 * `failed()` records and nothing more. The Action already wrote a log row and set the integration's
 * status; the hold is released inside it, in a `catch`, because a worker killed outright has no
 * hook to run at all and a `finally` there would not have helped either.
 */
final class PushLeadToCrmJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 180];

    public function __construct(
        private readonly int $integrationId,
        private readonly int $leadId,
    ) {}

    public function uniqueId(): string
    {
        return 'crm-push:'.$this->integrationId.':'.$this->leadId;
    }

    public function uniqueFor(): int
    {
        return 3_600;
    }

    public function handle(SyncLeadToCrmAction $sync): void
    {
        $integration = Integration::query()->withoutGlobalScopes()->find($this->integrationId);
        $lead = Lead::query()->withoutGlobalScopes()->with(['contacts'])->find($this->leadId);

        if ($integration === null || $lead === null) {
            return;
        }

        // Belt and braces on top of the Action's own check: a lead and an integration are looked up
        // without the tenancy scope here (a worker has no team in context), so the one thing this
        // job must prove for itself is that they belong to the same team.
        if ((int) $integration->team_id !== (int) $lead->team_id) {
            Log::error('CRM push asked to cross tenants; refused.', [
                'integration_id' => $this->integrationId,
                'lead_id' => $this->leadId,
            ]);

            return;
        }

        $sync->handle($integration, $lead);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('CRM push job failed.', [
            'integration_id' => $this->integrationId,
            'lead_id' => $this->leadId,
            'exception' => $e === null ? null : $e::class,
        ]);
    }
}
