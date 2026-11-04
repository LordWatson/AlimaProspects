<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Services\SignalSpendGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates or updates one {@see SignalWatch} (S5), transactionally and audited.
 *
 * Two things worth knowing:
 *
 * - **An ICP from another team is dropped, not refused.** It can only arrive from a tampered form,
 *   and dropping it saves a watch the member had every right to save (just without the ICP
 *   shaping) rather than failing on something they didn't do. The attempt is logged, since that is
 *   the only trace left.
 * - **A signal type from another team is dropped the same way** (S7, ADR 0018) - with one
 *   difference: a watch with *no* type left is a watch that can never find anything, so that is
 *   refused rather than saved (`SignalsException::noDefinitionsOnWatch()`). Resolving the ids
 *   through a team-scoped query is also what `docs/modules/signals.md` requires of every parent
 *   reference, since the FKs are not team-qualified at the database level.
 * - **A team can't create watches without limit.** Every active watch runs on its cadence and
 *   every run pays for Serper and an AI pass, so the ceiling is on creating them
 *   (`signals.max_watches_per_team`) as well as on running them — the per-watch run throttle does
 *   nothing about a team with two hundred watches.
 * - **Reactivating a paused watch doesn't make it due.** `last_run_at` is untouched, so the
 *   hourly scheduler decides on the normal cadence rule. A watch paused for a month would
 *   otherwise fire the moment it was switched back on, which is a surprise bill, not a feature —
 *   "Run now" exists for when that is what the member actually wants.
 *
 * Must be called inside the team's context (the controllers guarantee it).
 */
final class SaveSignalWatchAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly SignalSpendGuard $spend,
    ) {}

    public function handle(Team $team, User $actor, SignalWatchData $data, ?SignalWatch $watch = null): SignalWatch
    {
        return $this->teamContext->run($team, fn (): SignalWatch => DB::transaction(function () use ($team, $actor, $data, $watch): SignalWatch {
            $attributes = $data->toAttributes();
            $attributes['icp_id'] = $this->icpId($team, $data->icpId, $watch);
            $attributes['signal_watchlist_id'] = $this->watchlistId($team, $data->watchlistId, $watch);

            $definitionIds = $this->definitionIds($team, $data->definitionIds, $watch);

            if ($watch instanceof SignalWatch) {
                // **`market` is set once, on create, and never rewritten by an edit.** It is the
                // compliance regime this watch's leads fall back to (ADR 0019 §2 step 2), and it
                // arrives here from the *team* row rather than the form. Re-applying it on update
                // would mean renaming a watch, or unticking a type, silently moved its outreach
                // restriction — and `teams.market` defaults to `uk`, so a UAE team that never
                // visited its settings would have had its watches flip to `uk` on the first edit
                // and lose the backstop entirely. Changing a watch's regime is a deliberate act,
                // not a side effect of touching the form.
                unset($attributes['market']);

                $attributes['regions'] = $this->withWithdrawnRegions($watch, $attributes['regions']);

                $watch->forceFill($attributes)->save();
                // `sync`, not `attach`: the form posts the whole set, so a type the member
                // unticked has to come off as well as a new one going on.
                $watch->definitions()->sync($this->pivotRows($team, $definitionIds));

                $this->audit->log('signals.watch_updated', $watch, $this->properties($data, $definitionIds), $actor);

                return $watch->load('definitions');
            }

            $this->spend->assertRoomForWatch($team);

            /** @var SignalWatch $created */
            $created = SignalWatch::query()->create([
                ...$attributes,
                'team_id' => $team->getKey(),
                // Never run. The scheduler treats a null `last_run_at` as due, so a new active
                // watch picks up within the hour without the member having to press anything.
                'last_run_at' => null,
            ]);

            $created->definitions()->sync($this->pivotRows($team, $definitionIds));

            $this->audit->log('signals.watch_created', $created, $this->properties($data, $definitionIds), $actor);

            return $created->load('definitions');
        }));
    }

    /**
     * Put back any region the editor could not have offered the member.
     *
     * A region whose compliance position has been withdrawn is filtered out of the payload the
     * screen receives (`SignalWatchResource` sends `searchRegions()`), so a member re-saving the
     * watch cannot have meant to remove it — and dropping it silently would take the restriction
     * backstop for the signals that region has already produced along with it (ADR 0019 §2a). The
     * query builder filters by `isOffered()` on its own, so keeping the row costs nothing and
     * searches nothing: the watch stops looking there and still remembers that it did.
     *
     * @param  list<string>  $posted
     * @return list<string>
     */
    private function withWithdrawnRegions(SignalWatch $watch, array $posted): array
    {
        foreach ($watch->storedRegions() as $region) {
            if (! $region->isOffered() && ! in_array($region->value, $posted, strict: true)) {
                $posted[] = $region->value;
            }
        }

        return $posted;
    }

    /**
     * The signal types to attach: the ones the member chose that are actually this team's.
     *
     * Resolved through a team-scoped query rather than trusted from the request - the pivot's FK
     * points at `signal_definitions` without qualifying the team, so this is the layer that stops a
     * watch ever naming another tenant's type (or a platform preset, which the global scope already
     * hides and which a team must clone rather than use directly).
     *
     * A paused type is still attachable: paused is a state a member toggles, and
     * `SignalWatch::activeDefinitions()` is what decides whether it runs.
     *
     * @param  list<int>  $requested
     * @return list<int>
     *
     * @throws SignalsException when none of them are this team's.
     */
    private function definitionIds(Team $team, array $requested, ?SignalWatch $watch): array
    {
        /** @var list<int> $owned */
        $owned = SignalDefinition::query()
            ->where('team_id', $team->getKey())
            ->whereIn('id', $requested)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $dropped = count($requested) - count($owned);

        if ($dropped > 0) {
            Log::channel('signals')->warning('Ignored signal types that do not belong to this team on a watch.', [
                'team_id' => (int) $team->getKey(),
                'signal_watch_id' => $watch?->getKey(),
                'dropped' => $dropped,
            ]);
        }

        if ($owned === []) {
            throw SignalsException::noDefinitionsOnWatch();
        }

        // Back into the order the member chose, so the pivot - and therefore the query order - is
        // theirs rather than whatever the database handed back.
        return array_values(array_filter(
            $requested,
            static fn (int $id): bool => in_array($id, $owned, strict: true),
        ));
    }

    /**
     * The pivot rows for `sync()`: the definition id mapped to the extra column the table carries.
     *
     * `team_id` on a pivot between two already-tenant-scoped tables is redundant, and it is the
     * column that makes a cross-tenant pairing impossible at the database level rather than only
     * here (ADR 0018 section 5).
     *
     * @param  list<int>  $definitionIds
     * @return array<int, array{team_id: int}>
     */
    private function pivotRows(Team $team, array $definitionIds): array
    {
        $rows = [];

        foreach ($definitionIds as $id) {
            $rows[$id] = ['team_id' => (int) $team->getKey()];
        }

        return $rows;
    }

    /**
     * The ICP to shape this watch with: the one the member chose if it is theirs, else none.
     */
    private function icpId(Team $team, ?int $icpId, ?SignalWatch $watch): ?int
    {
        if ($icpId === null) {
            return null;
        }

        $exists = Icp::query()
            ->where('team_id', $team->getKey())
            ->whereKey($icpId)
            ->exists();

        if ($exists) {
            return $icpId;
        }

        Log::channel('signals')->warning('Ignored an out-of-team ICP on a signal watch.', [
            'team_id' => (int) $team->getKey(),
            'signal_watch_id' => $watch?->getKey(),
            'icp_id' => $icpId,
        ]);

        return null;
    }

    /**
     * The watchlist this watch follows: the one the member chose if it is theirs, else none (S10).
     *
     * Checked here as well as in the FormRequest, and team-scoped both times. The FK is not
     * team-qualified and `planFor()` reads `$watch->watchlist` through the ambient tenancy scope,
     * so a watch pointed at another team's list would be a watch whose owner cannot see what it
     * searches — and the request is not the only caller of this Action.
     */
    private function watchlistId(Team $team, ?int $watchlistId, ?SignalWatch $watch): ?int
    {
        if ($watchlistId === null) {
            return null;
        }

        $exists = SignalWatchlist::query()
            ->where('team_id', $team->getKey())
            ->whereKey($watchlistId)
            ->exists();

        if ($exists) {
            return $watchlistId;
        }

        Log::channel('signals')->warning('Ignored an out-of-team watchlist on a signal watch.', [
            'team_id' => (int) $team->getKey(),
            'signal_watch_id' => $watch?->getKey(),
            'signal_watchlist_id' => $watchlistId,
        ]);

        return null;
    }

    /**
     * What the audit row records: the shape of the watch, never a second copy of the team's own
     * wording beyond its name (CLAUDE.md logging rules).
     *
     * @param  list<int>  $definitionIds
     * @return array<string, mixed>
     */
    private function properties(SignalWatchData $data, array $definitionIds): array
    {
        return [
            'locations' => $data->locations,
            'regions' => array_map(static fn (SearchRegion $region): string => $region->value, $data->regions),
            // The ids rather than the keys: a key can be read off the definition row, and the ids
            // are what the pivot actually holds - so an audit row and the table agree.
            'signal_definition_ids' => $definitionIds,
            'cadence' => $data->cadence->value,
            'is_active' => $data->isActive,
            // How many, not which: a team's competitor list is their commercial business, and the
            // audit trail has no need of a copy.
            'ignore_domains_count' => count($data->ignoreDomains),
            // Whether this watch follows accounts rather than places, and which list — an id, which
            // is the one thing about a watchlist worth recording here.
            'signal_watchlist_id' => $data->watchlistId,
        ];
    }
}
