<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Signals\Enums\SignalSuggestionStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Jobs\DraftSignalSuggestionsJob;
use App\Modules\Signals\Models\SignalSuggestionBatch;
use Illuminate\Support\Facades\DB;

/**
 * Starts "suggest signal types for my business" (Signals v3 P2).
 *
 * It writes one pending {@see SignalSuggestionBatch} and queues the work; the drafting call and
 * the sample searches take longer than a request should.
 *
 * Three refusals, all before anything is spent:
 *
 * - **The capability.** This is the composer's AI shortcut by another door, so it is gated by the
 *   same `Capability::SignalComposer` (ADR 0020 §6). The library and the manual form stay free.
 * - **No business profile.** The suggestions are drawn from it; without one the model would be
 *   guessing, and a confident guess is worse than a sentence asking for the profile.
 * - **Once a day per team.** A batch is a reasoning-tier call plus up to five previews. The limit
 *   is counted over this table, which is why the table exists.
 *
 * A batch still in flight is handed back rather than refused or duplicated, so a double click is
 * one request.
 */
final class RequestSignalSuggestionsAction
{
    /** After this long a pending batch is taken to have died with its worker. */
    public const int STALE_PENDING_MINUTES = 10;

    public function __construct(
        private readonly CapabilityGate $capabilities,
        private readonly TeamContext $teamContext,
    ) {}

    public function handle(Team $team, User $actor): SignalSuggestionBatch
    {
        foreach ([Capability::Signals, Capability::SignalComposer] as $capability) {
            if (! $this->capabilities->allows($team, $capability)) {
                throw CapabilityException::unavailable($capability);
            }
        }

        return $this->teamContext->run($team, function () use ($team, $actor): SignalSuggestionBatch {
            $teamId = (int) $team->getKey();

            if (! BusinessProfile::query()->where('team_id', $teamId)->exists()) {
                throw SignalsException::suggestionsNeedProfile();
            }

            $batch = DB::transaction(function () use ($teamId, $actor): SignalSuggestionBatch {
                // Locked, so two requests arriving together cannot both find "nothing today".
                $team = Team::query()->lockForUpdate()->findOrFail($teamId);

                $latest = SignalSuggestionBatch::query()->where('team_id', $teamId)->latest('id')->first();

                if ($latest instanceof SignalSuggestionBatch) {
                    if ($latest->status === SignalSuggestionStatus::Pending
                        && $latest->created_at?->gt(now()->subMinutes(self::STALE_PENDING_MINUTES)) === true) {
                        return $latest;
                    }

                    $hours = max(0, (int) config('signals.suggestions.cooldown_hours', 24));

                    // A batch that produced suggestions is the day's answer. One that failed may
                    // be asked for again, but a failure is not always free — the drafting call can
                    // be billed before the worker dies — so the number of attempts in the window
                    // is capped as well, whatever became of them (review).
                    $attempts = SignalSuggestionBatch::query()
                        ->where('team_id', $teamId)
                        ->where('created_at', '>', now()->subHours($hours))
                        ->count();

                    if (($latest->status === SignalSuggestionStatus::Ready && $latest->created_at?->gt(now()->subHours($hours)) === true)
                        || $attempts >= max(1, (int) config('signals.suggestions.max_attempts', 3))) {
                        throw SignalsException::suggestionsOnCooldown($hours);
                    }
                }

                return SignalSuggestionBatch::query()->create([
                    'team_id' => $team->getKey(),
                    'requested_by' => $actor->getKey(),
                    'status' => SignalSuggestionStatus::Pending,
                ]);
            });

            if ($batch->wasRecentlyCreated) {
                DB::afterCommit(static fn () => DraftSignalSuggestionsJob::dispatch($teamId, (int) $batch->getKey()));
            }

            return $batch;
        });
    }
}
