<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Models\OutreachMessage;

/**
 * What one tenant's operations page shows (Phase 19a): its members, and what the account is
 * actually using.
 *
 * The usage figures are read **inside the tenant's own context**, which is the part worth being
 * deliberate about. `TeamContext::run()` means they come back through the same `BelongsToTeam`
 * global scope the tenant's own dashboard uses, so the panel cannot disagree with what the customer
 * sees. A hand-written `where('team_id', …)` here would be a second implementation of tenancy —
 * and the day the two differed, the panel would be the one that was wrong.
 */
final class TeamOverview
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function members(Team $team): array
    {
        return array_values($team->users()
            ->orderBy('name')
            ->get()
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'role' => $user->getAttribute('pivot')?->getAttribute('role'),
                'is_operator' => $user->isPlatformAdmin(),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'verified' => $user->email_verified_at !== null,
            ])
            ->values()
            ->all());
    }

    /**
     * @return array<string, int>
     */
    public function usage(Team $team): array
    {
        return $this->teamContext->run($team, static fn (): array => [
            'leads' => Lead::query()->count(),
            'discovery_runs' => DiscoveryRun::query()->count(),
            'messages_sent' => OutreachMessage::query()->whereNotNull('sent_at')->count(),
        ]);
    }
}
