<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * Team-scoped roles (ADR 0004). Full permission wiring lands in the Auth & Users phase;
 * this enum is the single source of truth for role identifiers — no magic strings.
 */
enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case ReadOnly = 'read-only';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::ReadOnly => 'Read-only',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
