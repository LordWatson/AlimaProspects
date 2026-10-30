<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Actions\SwapPlanAction;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Services\PlanRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The plan a team has picked — for starting a checkout or swapping an existing subscription.
 *
 * Validation only (CLAUDE.md §2): whether this *member* may change the plan is the controller's
 * gate, and whether the team *fits* the plan is {@see SwapPlanAction}'s
 * business. All this says is that the string names a plan somebody can actually buy, which keeps
 * `free` and `enterprise` out at the door rather than deep inside the gateway.
 */
final class ChoosePlanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => [
                'required',
                'string',
                Rule::in(array_map(
                    static fn (PlanData $plan): string => $plan->key->value,
                    app(PlanRegistry::class)->purchasable(),
                )),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan.in' => __('That plan isn’t something you can switch to here — talk to us and we’ll set it up.'),
        ];
    }

    public function plan(): PlanData
    {
        return app(PlanRegistry::class)->get(PlanKey::from((string) $this->validated('plan')));
    }
}
