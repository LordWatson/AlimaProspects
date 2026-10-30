<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Policies;

use App\Models\User;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Core\Enums\TeamRole;

/**
 * Ownership double-check for legitimate-interest assessments (ADR 0006, docs/compliance.md §2).
 *
 * **Any member may read it, Owners and Admins may write it.** The read is deliberately wide: the
 * assessment is the answer to "why are we allowed to have this person's details", and a member
 * working a list is the one who will be asked. Hiding it from them would make the record a
 * compliance artefact rather than a working one.
 *
 * Signing it off is an Owner/Admin act because it is a statement on the business's behalf — the
 * same bar as changing the team's market, which is what decides the regime it is assessed under.
 *
 * A non-member always fails. Role comes from the `team_user` pivot via {@see User::roleOn()}.
 */
final class LiaRecordPolicy
{
    public function view(User $user, LiaRecord $record): bool
    {
        return $user->belongsToTeam($record->team);
    }

    /**
     * Authorised against a transient, team-stamped record when there is no row yet.
     */
    public function update(User $user, LiaRecord $record): bool
    {
        $role = $user->roleOn($record->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }

    public function withdraw(User $user, LiaRecord $record): bool
    {
        return $this->update($user, $record);
    }
}
