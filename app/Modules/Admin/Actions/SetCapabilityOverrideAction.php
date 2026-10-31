<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Models\CapabilityOverride;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\GovernedCapabilityGate;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Give a team a feature their plan excludes, or take away one it includes (Phase 19b, ADR 0015).
 *
 * Both directions through one Action, because they are the same act with a different boolean: the
 * pilot customer who gets Signals on a Starter plan, and the trial account that must not be able to
 * send. What they have in common is that **somebody decided**, and the reason and the expiry are
 * what make that decision legible a month later — which is why both are columns rather than
 * comments.
 *
 * One standing override per capability per team, replaced rather than stacked: two overrides for
 * the same capability would make the answer depend on which row the query happened to return.
 */
final class SetCapabilityOverrideAction
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly CapabilityGate $capabilities,
    ) {}

    public function handle(
        Team $team,
        Capability $capability,
        bool $enabled,
        string $reason,
        ?DateTimeInterface $expiresAt,
        User $operator,
    ): CapabilityOverride {
        return DB::transaction(function () use ($team, $capability, $enabled, $reason, $expiresAt, $operator): CapabilityOverride {
            /** @var CapabilityOverride $override */
            $override = CapabilityOverride::query()->withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->getKey(), 'capability' => $capability->value],
                [
                    'enabled' => $enabled,
                    'reason' => $reason,
                    'expires_at' => $expiresAt === null ? null : CarbonImmutable::instance($expiresAt)->utc(),
                    'set_by' => $operator->getKey(),
                ],
            );

            $this->audit->record(
                'admin.team.capability_override_set',
                $override,
                [
                    'capability' => $capability->value,
                    'enabled' => $enabled,
                    'reason' => $reason,
                    'expires_at' => $override->expires_at?->toIso8601String(),
                    'operator_id' => $operator->getKey(),
                ],
                (int) $team->getKey(),
            );

            $this->forgetGate();

            return $override;
        });
    }

    /**
     * Remove an override, so the team goes back to what its plan says.
     */
    public function clear(Team $team, Capability $capability, User $operator): void
    {
        DB::transaction(function () use ($team, $capability, $operator): void {
            $deleted = CapabilityOverride::query()
                ->withoutGlobalScopes()
                ->where('team_id', $team->getKey())
                ->where('capability', $capability->value)
                ->delete();

            if ($deleted === 0) {
                return;
            }

            $this->audit->record(
                'admin.team.capability_override_cleared',
                null,
                ['capability' => $capability->value, 'operator_id' => $operator->getKey()],
                (int) $team->getKey(),
            );

            $this->forgetGate();
        });
    }

    /**
     * Drop the gate's memo so the same request sees the change.
     *
     * Type-checked rather than assumed: the bound gate is only the governed one when Admin is
     * registered, and a `forget()` called on Core's or Billing's gate would be a method that does
     * not exist. The guards ask on every discovery run, so an answer from before this write would
     * be wrong for the rest of the request.
     */
    private function forgetGate(): void
    {
        if ($this->capabilities instanceof GovernedCapabilityGate) {
            $this->capabilities->forget();
        }
    }
}
