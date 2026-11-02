<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Data\SignalDefinitionData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a signal definition on create and update (S7, ADR 0018). The rules come from
 * {@see SignalDefinitionData::rules()} so the web and API endpoints can't drift apart on what a
 * definition may be. Authorisation lives in the controller via `SignalDefinitionPolicy`.
 *
 * There is nothing to add to those rules here — unlike the watch request, which has to scope the
 * ICP's existence to the caller's team. A definition owns nothing outside itself.
 */
final class SaveSignalDefinitionRequest extends FormRequest
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
        return SignalDefinitionData::rules();
    }

    public function definition(): SignalDefinitionData
    {
        return SignalDefinitionData::fromValidated($this->validated());
    }
}
