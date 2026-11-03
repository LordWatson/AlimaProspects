<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Contracts\SignalOutreachDrafter;
use App\Modules\Signals\Data\SignalPlaybookData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a playbook on create and update (S14).
 *
 * The rules come from {@see SignalPlaybookData::rules()} so the web and API endpoints cannot drift
 * on what a rule may be. Authorisation lives in the controller via `SignalPlaybookPolicy`.
 *
 * The `after` hook is where this request earns its keep. What makes a step valid depends on its own
 * kind — a stage move needs a stage, a draft needs a channel this deployment offers — and expressing
 * that in rule strings over an array of objects would be unreadable and wrong the first time
 * somebody reordered the steps. So {@see SignalPlaybookData::problems()} answers it in PHP and the
 * messages are merged in **against their own fields**, which is what lets the builder's error
 * summary move focus to the control that is wrong rather than to the top of a long form.
 *
 * The stage and member ids are deliberately **not** checked against the team's own rows here, the
 * same treatment a watch's ICP and a watchlist's stages get: an id from another team can only arrive
 * from a tampered form, and the handlers resolve through the team's own scope so it matches nothing.
 * A rule that stopped saving because somebody deleted a stage last week would be the worse failure.
 */
final class SaveSignalPlaybookRequest extends FormRequest
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
        return SignalPlaybookData::rules();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    // The shape is already wrong, so the semantic messages would be noise on top of
                    // it — and `problems()` reads a half-validated payload.
                    return;
                }

                $drafter = app(SignalOutreachDrafter::class);

                $problems = $this->playbook()->problems(
                    array_map(static fn (array $channel): string => $channel['value'], $drafter->channels()),
                    array_map(static fn (array $tone): string => $tone['value'], $drafter->tones()),
                );

                foreach ($problems as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    public function playbook(): SignalPlaybookData
    {
        return SignalPlaybookData::fromValidated($this->validated());
    }
}
