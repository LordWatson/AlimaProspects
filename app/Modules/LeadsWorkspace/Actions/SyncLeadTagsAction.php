<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sets a lead's tags from a list of names (Phase 10b). Tags are created on first use, so tagging
 * never requires setting tags up first, and names are matched on their normalised form — "Warm",
 * "warm" and " warm " are one tag, not three.
 *
 * Only writes a timeline entry when the tags actually changed: re-saving the same set from a screen
 * that didn't change anything shouldn't look like the team did something.
 *
 * Must be called inside the team's context.
 */
final class SyncLeadTagsAction
{
    /** Most tags one lead may carry. A label that is on everything labels nothing. */
    public const int MAX_PER_LEAD = 20;

    public function __construct(
        private readonly RecordLeadActivityAction $record,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $names
     * @return Collection<int, LeadTag>
     */
    public function handle(Team $team, Lead $lead, array $names, User $actor): Collection
    {
        return DB::transaction(function () use ($team, $lead, $names, $actor): Collection {
            $before = $lead->tags()->pluck('name')->sort()->values()->all();

            $tags = $this->resolve($team, $names);

            $lead->tags()->sync($tags->modelKeys());

            $after = $tags->pluck('name')->sort()->values()->all();

            if ($before !== $after) {
                $this->record->handle(
                    lead: $lead,
                    type: LeadActivityType::TagsChanged,
                    properties: [
                        'added' => array_values(array_diff($after, $before)),
                        'removed' => array_values(array_diff($before, $after)),
                    ],
                    actor: $actor,
                );

                $this->audit->log('leads.tags_changed', $lead, ['count' => count($after)], $actor);
            }

            return $tags->load('team');
        });
    }

    /**
     * Turn names into the team's tags, creating the ones that don't exist yet.
     *
     * @param  list<string>  $names
     * @return Collection<int, LeadTag>
     */
    private function resolve(Team $team, array $names): Collection
    {
        $wanted = [];

        foreach ($names as $name) {
            $trimmed = Str::limit(Str::squish($name), 40, '');
            $slug = LeadTag::normalise($trimmed);

            if ($slug === '' || isset($wanted[$slug])) {
                continue;
            }

            $wanted[$slug] = $trimmed;

            if (count($wanted) >= self::MAX_PER_LEAD) {
                break;
            }
        }

        if ($wanted === []) {
            /** @var Collection<int, LeadTag> $empty */
            $empty = LeadTag::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        foreach ($wanted as $slug => $name) {
            try {
                LeadTag::query()->firstOrCreate(
                    ['team_id' => $team->getKey(), 'slug' => $slug],
                    ['name' => $name],
                );
            } catch (UniqueConstraintViolationException) {
                // Two members tagged the same lead at once; whichever row won is the tag.
            }
        }

        return LeadTag::query()
            ->where('team_id', $team->getKey())
            ->whereIn('slug', array_keys($wanted))
            ->orderBy('name')
            ->get();
    }
}
