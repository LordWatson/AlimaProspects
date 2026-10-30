<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Data\NotificationPreferencesData;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a member's notification settings.
 *
 * `channels` is a map of type → list of channel values. The **type keys are not validated against
 * the enum**, and the channel values are validated loosely, because
 * {@see NotificationPreferencesData} drops anything it doesn't recognise: the screen may be a
 * cached page from before a type was renamed, and losing a whole form over one stale key would be
 * worse for the member than ignoring it.
 */
final class SaveNotificationPreferencesRequest extends FormRequest
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
            'channels' => ['nullable', 'array'],
            'channels.*' => ['array'],
            'channels.*.*' => [Rule::in(NotificationChannel::values())],

            'digest' => ['required', Rule::in(DigestFrequency::values())],
            'digest_hour' => ['nullable', 'integer', 'min:0', 'max:23'],

            // "Not this week." A date, because a mute nobody remembers switching on is a product
            // that looks broken.
            'muted_until' => ['nullable', 'date'],
        ];
    }

    public function preferencesData(): NotificationPreferencesData
    {
        return NotificationPreferencesData::fromValidated($this->validated());
    }
}
