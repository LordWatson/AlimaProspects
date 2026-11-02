<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use Carbon\CarbonImmutable;

/**
 * The counts behind the panel's front page (Phase 19a).
 *
 * A service rather than eight `count()` calls in a controller, because CLAUDE.md §2 keeps queries
 * out of controllers — and because the one thing these figures will certainly need is replacing.
 * **Platform reporting is Phase 19c**, where they come off a nightly `platform_daily_metrics`
 * rollup rather than live aggregates: a cross-tenant sum over `ai_requests` is the one query in this
 * product with no `team_id` to narrow it, and it will not stay fast. When that lands, this class is
 * what changes, and the controller and the page do not.
 *
 * Every figure here is an **aggregate and names nobody**, which is why — unlike a resource index —
 * the dashboard is not audited as a privacy event. There is no personal data in a number.
 */
final class PlatformOverview
{
    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $dayAgo = CarbonImmutable::now('UTC')->subDay();

        return [
            'teams' => Team::query()->count(),
            'teams_suspended' => Team::query()->whereNotNull('suspended_at')->count(),
            'users' => User::query()->count(),
            'operators' => User::query()->whereNotNull('admin_role')->count(),
            'leads' => Lead::query()->count(),
            'discovery_runs_24h' => DiscoveryRun::query()->where('created_at', '>=', $dayAgo)->count(),
            'ai_requests_24h' => AiRequest::query()->where('created_at', '>=', $dayAgo)->count(),
            // Cost **micro-units**, not minor units. Converting would mean asserting a currency,
            // and a cross-tenant sum is what we paid providers rather than what any one tenant is
            // billed in — see the dashboard page, and Phase 19c.
            'ai_cost_micros_24h' => (int) AiRequest::query()->where('created_at', '>=', $dayAgo)->sum('cost_micros'),
        ];
    }

    /**
     * The suspended tenants, newest first — the only platform state that is actively costing
     * somebody something, and the reason it is the one table on the front page.
     *
     * @return list<array<string, mixed>>
     */
    public function suspendedTeams(int $limit = 10): array
    {
        return array_values(Team::query()
            ->whereNotNull('suspended_at')
            ->orderByDesc('suspended_at')
            ->limit($limit)
            ->get(['id', 'name', 'suspended_at', 'suspension_reason'])
            ->map(static fn (Team $team): array => [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'suspended_at' => $team->suspended_at?->toIso8601String(),
                'reason' => $team->suspension_reason,
            ])
            ->values()
            ->all());
    }
}
