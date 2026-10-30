<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Requests;

use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Enums\Market;
use App\Modules\Onboarding\Enums\CompanySize;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Enums\ServedRegion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a single wizard step. The rule set is chosen from the `{step}` route segment so one
 * request covers the whole wizard while each step only validates (and the Action only writes) its
 * own fields. Authorisation is handled in the controller via the Business Profile policy.
 */
final class SaveOnboardingStepRequest extends FormRequest
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
        return match ($this->step()) {
            OnboardingStep::Company => [
                'company_name' => ['required', 'string', 'max:255'],
                'website' => ['nullable', 'string', 'url', 'max:255'],
            ],
            OnboardingStep::Industry => [
                'industry' => ['required', 'string', 'max:255'],
                'company_size' => ['required', Rule::enum(CompanySize::class)],
            ],
            OnboardingStep::Locations => [
                'locations' => ['required', 'array', 'min:1', 'max:20'],
                'locations.*' => ['required', 'string', 'max:255'],
                'regions_served' => ['required', 'array', 'min:1'],
                'regions_served.*' => [Rule::enum(ServedRegion::class)],
                'primary_market' => ['required', Rule::enum(Market::class)],
            ],
            OnboardingStep::Offering => [
                'products_services' => ['required', 'array', 'min:1', 'max:20'],
                'products_services.*' => ['required', 'string', 'max:255'],
                'value_proposition' => ['required', 'string', 'max:2000'],
            ],
            OnboardingStep::Pricing => [
                'pricing_currency' => ['required', Rule::enum(Currency::class)],
                'pricing_min' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
                'pricing_max' => ['nullable', 'numeric', 'min:0', 'max:1000000000', 'gte:pricing_min'],
                'customer_examples' => ['nullable', 'array', 'max:20'],
                'customer_examples.*' => ['required', 'string', 'max:255'],
            ],
            OnboardingStep::Review => [],
        };
    }

    /**
     * The step being saved, from the validated route segment.
     */
    public function step(): OnboardingStep
    {
        return OnboardingStep::from((string) $this->route('step'));
    }
}
