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
 * `market` is deliberately **not** accepted: v1 is UAE-only and the value is set by the DTO (see
 * {@see SignalWatchData::DEFAULT_MARKET}), so a posted market is ignored rather than honoured into
 * a watch that would silently find nothing.
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
        ];
    }

    public function watch(): SignalWatchData
    {
        return SignalWatchData::fromValidated($this->validated());
    }
}
