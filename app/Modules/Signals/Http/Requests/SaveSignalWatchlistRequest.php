<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Data\SignalWatchlistData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a watchlist on create and update (S10).
 *
 * The rules come from {@see SignalWatchlistData::rules()} so the web and API endpoints cannot drift
 * on what a watchlist may be. Authorisation lives in the controller via `SignalWatchlistPolicy`.
 *
 * The stage and owner ids are **not** checked against the team's own rows here, deliberately: the
 * same treatment a watch's ICP gets. An id from another team can only arrive from a tampered form,
 * and the sync drops it rather than failing — a list that mirrors "my open stages" should not stop
 * syncing because somebody deleted a stage.
 */
final class SaveSignalWatchlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SignalWatchlistData::rules();
    }

    public function watchlist(): SignalWatchlistData
    {
        return SignalWatchlistData::fromValidated($this->validated());
    }
}
