<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Core\Enums\TeamRole;

/**
 * A platform operator's role (Phase 19a, ADR 0014). Stored on `users.admin_role`; null for
 * everybody who is a customer, which is almost everybody.
 *
 * **Not a {@see TeamRole}.** Those are scoped to one tenant by definition — they come off the
 * `team_user` pivot and answer "what may this person do inside this team". A platform operator
 * belongs to no tenant at all, so the question has no pivot row to answer it from.
 *
 * Four roles rather than one boolean, because the abilities in {@see AdminAbility} are not one
 * power. The mapping lives in `config/admin.php`; this enum only knows how to ask.
 */
enum AdminRole: string
{
    /** Everything, including impersonation, deletes and the platform switches. */
    case SuperAdmin = 'super_admin';

    /** Answers tickets: reads tenant data, fixes records, impersonates. No settings, no deletes. */
    case Support = 'support';

    /** Plans, subscriptions and the money reports. Reads tenant data; never impersonates. */
    case Finance = 'finance';

    /** Looks, touches nothing. For an analyst, an auditor, or a new starter's first week. */
    case ReadOnly = 'read_only';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::Support => 'Support',
            self::Finance => 'Finance',
            self::ReadOnly => 'Read-only',
        };
    }

    /**
     * The abilities this role carries, from `config/admin.php`.
     *
     * An unknown or misspelled ability in that config is **dropped**, not trusted: a typo there
     * would otherwise become a power nothing grants and nothing refuses, and a role carrying a
     * string no gate is registered for is a role whose permissions nobody can read off the code.
     *
     * @return list<AdminAbility>
     */
    public function abilities(): array
    {
        /** @var array<string, mixed> $map */
        $map = config('admin.abilities', []);
        $declared = $map[$this->value] ?? [];

        if (! is_array($declared)) {
            return [];
        }

        if (in_array('*', $declared, true)) {
            return AdminAbility::cases();
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): ?AdminAbility => is_string($value) ? AdminAbility::tryFrom($value) : null,
            $declared,
        )));
    }

    public function can(AdminAbility $ability): bool
    {
        return in_array($ability, $this->abilities(), strict: true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
