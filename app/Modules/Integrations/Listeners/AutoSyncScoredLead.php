<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Listeners;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Integrations\Actions\SyncLeadToCrmAction;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Jobs\PushLeadToCrmJob;
use App\Modules\Integrations\Models\Integration;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Events\LeadScored;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The auto-sync rule: push a lead to the CRM when it gets good enough (PL7).
 *
 * **Off unless a team switched it on**, and the default matters more than the mechanism: switching
 * it on means Alima starts writing into somebody's CRM with no person present, and nobody should
 * discover that as a default ({@see Integration::autoSync()}).
 *
 * It hangs off `LeadScored` rather than a sweep, because that event is exactly the moment a lead's
 * worth changed — a nightly job asking "which leads are good now" would re-ask about every lead a
 * team owns, and the `synced_hash` skip would then be the only thing between them and a few
 * thousand pointless API calls a night.
 *
 * **Both thresholds, and an absent one means "do not care".** A team that wants everything pushed
 * should not have to type a zero, and an unjudged lead — `confidence` is null until PL1's evidence
 * has been weighed, and null is not zero (ADR 0028) — fails a confidence floor rather than passing
 * it. Failing *toward* not pushing is the safe direction: the thing that cannot be undone is a
 * record arriving in somebody else's CRM.
 *
 * Everything that makes a push legitimate — the suppression check, the allowance, the duplicate
 * skip — is in {@see SyncLeadToCrmAction}. This listener only
 * decides *whether to ask*, which is why it may be as simple as it is.
 *
 * It catches `Throwable`: it runs on an after-commit event, so inside the caller's job, and an
 * exception here would fail whatever was scoring the lead (ADR 0024).
 */
final class AutoSyncScoredLead implements ShouldQueue
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(LeadScored $event): void
    {
        try {
            $this->consider($event);
        } catch (Throwable $e) {
            Log::error('Auto-sync check failed.', [
                'team_id' => $event->teamId,
                'lead_id' => $event->leadId,
                'exception' => $e::class,
            ]);
        }
    }

    private function consider(LeadScored $event): void
    {
        $team = Team::query()->find($event->teamId);

        if ($team === null) {
            return;
        }

        $integrations = Integration::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->where('status', IntegrationStatus::Connected->value)
            ->get();

        if ($integrations->isEmpty()) {
            return;
        }

        // Read once, not per integration: two connected CRMs would otherwise be two identical
        // queries for the same lead.
        $confidence = $this->teamContext->run(
            $team,
            fn (): ?int => Lead::query()->whereKey($event->leadId)->value('confidence'),
        );

        foreach ($integrations as $integration) {
            $rule = $integration->autoSync();

            if (! $rule['enabled']) {
                continue;
            }

            if ($rule['min_fit'] !== null && $event->score < $rule['min_fit']) {
                continue;
            }

            // Null confidence fails a floor. See the class note: not judged is not good enough.
            if ($rule['min_confidence'] !== null
                && ($confidence === null || $confidence < $rule['min_confidence'])) {
                continue;
            }

            PushLeadToCrmJob::dispatch((int) $integration->getKey(), $event->leadId);
        }
    }
}
