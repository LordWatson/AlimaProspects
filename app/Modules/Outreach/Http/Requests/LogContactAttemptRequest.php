<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Requests;

use App\Modules\Outreach\Data\LogAttemptData;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a logged approach.
 *
 * `occurred_at` may be in the past — a member logs yesterday's call today — but not in the future:
 * a contact attempt is a thing that happened, and a dated-ahead one would sit in the history as
 * something the team believes they have already done.
 */
final class LogContactAttemptRequest extends FormRequest
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
        $teamId = $this->user()?->currentTeamId();

        return [
            'channel' => ['required', Rule::in(OutreachChannel::values())],
            'outcome' => ['nullable', Rule::in(ContactOutcome::values())],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'lead_contact_id' => [
                'nullable',
                'integer',
                Rule::exists('lead_contacts', 'id')->where('team_id', $teamId),
            ],
            'message_id' => [
                'nullable',
                'integer',
                Rule::exists('outreach_messages', 'id')->where('team_id', $teamId),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attemptData(): LogAttemptData
    {
        return LogAttemptData::fromValidated($this->validated());
    }
}
