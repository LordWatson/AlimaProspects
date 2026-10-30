<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Exceptions\OutreachException;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;

/**
 * Adds an identity to a team's own do-not-contact list by hand (Phase 11) — a client they already
 * serve, a competitor, a company they handle offline.
 *
 * **Team scope only, and a team-managed reason only.** A member cannot write a global entry from
 * the product: a global entry binds every other tenant, and an opt-out is a thing the platform
 * records on a person's instruction, not a thing one team declares about another's prospect. The
 * routes that *do* write global entries are the ones acting on the person's own words — logging an
 * opt-out outcome, and (post-MVP) processing an unsubscribe reply.
 *
 * Must be called inside the team's context.
 */
final class SuppressIdentityAction
{
    public function __construct(private readonly SuppressionList $suppressions) {}

    /**
     * @throws OutreachException when the value isn't a usable identity of that type
     */
    public function handle(
        Team $team,
        User $member,
        SuppressionType $type,
        string $value,
        ?string $note = null,
    ): OutreachSuppression {
        $entry = $this->suppressions->suppress(
            type: $type,
            value: $value,
            scope: SuppressionScope::Team,
            reason: SuppressionReason::Manual,
            teamId: (int) $team->getKey(),
            actor: $member,
            note: $note,
        );

        if (! $entry instanceof OutreachSuppression) {
            // A half-remembered address hashed and stored would be an entry that never matches
            // anything, and the team would believe they were covered.
            throw OutreachException::unusableSuppressionValue();
        }

        return $entry;
    }

    /**
     * Lift a team's own entry. A global opt-out is refused — the model, the policy and the list
     * service all say so, and this is where the member is told why.
     *
     * @throws OutreachException
     */
    public function lift(OutreachSuppression $entry, User $member): void
    {
        if (! $this->suppressions->lift($entry, $member)) {
            throw OutreachException::suppressionNotRemovable();
        }
    }
}
