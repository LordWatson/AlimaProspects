<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\PushPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a device registration.
 *
 * The token's length bound is generous on purpose: FCM registration tokens are long and their
 * format is the provider's business, not ours. A bound exists only so a client cannot fill the
 * column — the only real test of a token is whether FCM accepts it, and the channel deletes the
 * ones it rejects.
 */
final class RegisterPushDeviceRequest extends FormRequest
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
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'platform' => ['required', Rule::in(PushPlatform::values())],
        ];
    }

    public function token(): string
    {
        return trim((string) $this->validated('token'));
    }

    public function platform(): PushPlatform
    {
        return PushPlatform::from((string) $this->validated('platform'));
    }
}
