<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Data\SignalWatchData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a watch on create and update. The rules come from {@see SignalWatchData::rules()} so
 * the web (S5) and API (S6) endpoints can't drift apart on what a watch may be, with the ICP's
 * existence scoped to the member's own team here — the one check that needs the request to know
 * who is asking. Authorisation lives in the controller via `SignalWatchPolicy`.
 *
 * `market` is deliberately **not** accepted. Since S8 it is the team's **own** compliance regime
 * (ADR 0019), read from the team row rather than from the form — a member cannot choose which law
 * applies to them, and letting a form say so would be letting a tampered request pick its own
 * outreach restriction. Where to *look* is `regions`, which is on the form and validated against
 * the regions the business has actually taken a position on.
 */
final class SaveSignalWatchRequest extends FormRequest
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
        return [
            ...SignalWatchData::rules(),
            'icp_id' => [
                'nullable',
                'integer',
                Rule::exists('icps', 'id')->where('team_id', $this->user()?->currentTeamId()),
            ],
            // Scoped to the member's own team (S10). The watch then reads `$watch->watchlist`
            // through the ambient tenancy scope, so an id from another team would be a watch
            // pointed at a list its owner cannot see — and the FK itself is not team-qualified.
            'signal_watchlist_id' => [
                'nullable',
                'integer',
                Rule::exists('signal_watchlists', 'id')->where('team_id', $this->user()?->currentTeamId()),
            ],
        ];
    }

    public function watch(): SignalWatchData
    {
        return SignalWatchData::fromValidated(
            $this->validated(),
            SignalWatchData::marketFor($this->user()?->currentTeam()->first()?->market),
        );
    }
}
