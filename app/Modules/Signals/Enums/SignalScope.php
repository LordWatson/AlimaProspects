<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * Which slice of the feed a member is looking at (S10).
 *
 * **A scope, not a status**, and keeping the two apart is the decision worth recording. A status is
 * what has happened to a signal — new, dismissed, converted. A scope is who it is about: the market
 * at large, or the accounts this team already works. They are orthogonal: "new events about my
 * accounts" and "converted events about my accounts" are both questions somebody asks, and folding
 * this into {@see SignalStatus} would have produced six cases that were really two questions
 * crossed, with the tabs to match.
 */
enum SignalScope: string
{
    /** Everything the team's watches found. */
    case All = 'all';

    /**
     * Only events matched to a lead the team already has.
     *
     * The warm half of the feed, and the reason S10 exists: a cold signal is a stranger who came
     * into money, and this is something that happened to a company somebody is working today.
     */
    case Accounts = 'accounts';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Everything',
            self::Accounts => 'My accounts',
        };
    }

    /**
     * What the tab is for, in one line — shown as its empty state.
     */
    public function emptyMessage(): string
    {
        return match ($this) {
            self::All => 'Nothing yet. A watch will fill this in on its next check.',
            self::Accounts => 'Nothing about your own accounts yet. Point a watch at a list of accounts, and events about companies you are already working will show up here.',
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
