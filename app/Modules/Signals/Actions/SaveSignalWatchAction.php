<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalWatch;
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
    ) {}

    public function handle(Team $team, User $actor, SignalWatchData $data, ?SignalWatch $watch = null): SignalWatch
    {
        return $this->teamContext->run($team, fn (): SignalWatch => DB::transaction(function () use ($team, $actor, $data, $watch): SignalWatch {
            $attributes = $data->toAttributes();
            $attributes['icp_id'] = $this->icpId($team, $data->icpId, $watch);

            if ($watch instanceof SignalWatch) {
                $watch->forceFill($attributes)->save();

                $this->audit->log('signals.watch_updated', $watch, $this->properties($data), $actor);

                return $watch;
            }

            $this->assertRoomForAnother($team);

            /** @var SignalWatch $created */
            $created = SignalWatch::query()->create([
                ...$attributes,
                'team_id' => $team->getKey(),
                // Never run. The scheduler treats a null `last_run_at` as due, so a new active
                // watch picks up within the hour without the member having to press anything.
                'last_run_at' => null,
            ]);

            $this->audit->log('signals.watch_created', $created, $this->properties($data), $actor);

            return $created;
        }));
    }

    /**
     * Refuse a new watch once the team is at its ceiling.
     *
     * Checked on **create only** — an existing watch is already counted, so editing one must not
     * fail because the team is at the limit. Inside the transaction, so two simultaneous creates
     * can't both see room (the count is a read, not a lock, but the window is a single statement
     * and the consequence of losing the race is one extra watch, not a runaway).
     *
     * @throws SignalsException when the team has no room.
     */
    private function assertRoomForAnother(Team $team): void
    {
        $max = max(1, (int) config('signals.max_watches_per_team', 10));

        if (SignalWatch::query()->where('team_id', $team->getKey())->count() >= $max) {
            throw SignalsException::tooManyWatches($max);
        }
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
     * What the audit row records: the shape of the watch, never a second copy of the team's own
     * wording beyond its name (CLAUDE.md logging rules).
     *
     * @return array<string, mixed>
     */
    private function properties(SignalWatchData $data): array
    {
        return [
            'location' => $data->location,
            'event_types' => array_map(static fn ($type): string => $type->value, $data->eventTypes),
            'cadence' => $data->cadence->value,
            'is_active' => $data->isActive,
            // How many, not which: a team's competitor list is their commercial business, and the
            // audit trail has no need of a copy.
            'ignore_domains_count' => count($data->ignoreDomains),
        ];
    }
}
