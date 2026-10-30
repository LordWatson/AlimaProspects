<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes one entry onto a lead's timeline (Phase 10). The only way a {@see LeadActivity} is
 * created, so every entry is team-stamped, attributed and de-duplicated the same way.
 *
 * Two deliberate choices:
 *
 * - **Idempotent per (lead, type, subject).** The listeners that feed the timeline hang off queued
 *   work, and a retried job must not leave the lead with the same entry twice. An entry whose
 *   subject already has one of that type is *updated* (new properties, new timestamp) rather than
 *   appended — "graded" happens once per score, however many times the job runs. Entries with no
 *   subject are always appended, since they are distinct events by definition.
 * - **No stored prose.** `properties` carries values, never a sentence: the workspace is EN/AR
 *   (ADR 0007) and the wording is composed at render time.
 *
 * Must be called inside the lead's team context (the jobs, listeners and controllers guarantee it).
 */
final class RecordLeadActivityAction
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  User|int|null  $actor  The member responsible, or null for work the platform did on
     *                                its own. Defaults to the authenticated user when there is one,
     *                                so a queued job records no actor and a click records its
     *                                clicker without either having to say so.
     */
    public function handle(
        Lead $lead,
        LeadActivityType $type,
        ?Model $subject = null,
        array $properties = [],
        User|int|null $actor = null,
        // DateTimeInterface rather than Carbon: the app runs on CarbonImmutable (AppServiceProvider),
        // which is a sibling of Illuminate\Support\Carbon, not a subclass of it.
        ?DateTimeInterface $occurredAt = null,
    ): LeadActivity {
        $attributes = [
            'user_id' => $this->actorId($actor),
            'properties' => $properties === [] ? null : $properties,
            'occurred_at' => $occurredAt ?? now(),
        ];

        $identity = [
            'team_id' => $lead->team_id,
            'lead_id' => $lead->getKey(),
            'type' => $type,
        ];

        if ($subject === null) {
            return LeadActivity::query()->create([...$identity, ...$attributes]);
        }

        return LeadActivity::query()->updateOrCreate(
            [...$identity, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()],
            $attributes,
        );
    }

    private function actorId(User|int|null $actor): ?int
    {
        if ($actor instanceof User) {
            return (int) $actor->getKey();
        }

        if (is_int($actor)) {
            return $actor;
        }

        $user = Auth::user();

        return $user instanceof User ? (int) $user->getKey() : null;
    }
}
