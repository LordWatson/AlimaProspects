<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

/**
 * How widely a suppression entry applies (docs/compliance.md §3.2).
 *
 * The asymmetry is deliberate: a **global** entry is honoured by every team on the platform,
 * because an opt-out is a person's wish and not a per-tenant setting, and only the platform may
 * create or remove one. A **team** entry is that team's own housekeeping — a bounced address, a
 * company they have agreed to stop approaching — and theirs to manage.
 */
enum SuppressionScope: string
{
    case Global = 'global';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Everywhere',
            self::Team => 'This team',
        };
    }

    /**
     * Whether a team may remove an entry at this scope. Nobody un-suppresses an opt-out from the
     * product: it would let a tenant overrule a person's stated wish with a button.
     */
    public function isTeamManaged(): bool
    {
        return $this === self::Team;
    }

    /**
     * The `scope_key` column's value — the scope expressed as one comparable string, so uniqueness
     * can be a plain unique index despite `team_id` being nullable (see the migration).
     */
    public function keyFor(?int $teamId): string
    {
        return match ($this) {
            self::Global => 'global',
            self::Team => 'team:'.$teamId,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
