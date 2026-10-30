<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Requests;

use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Policies\IcpPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an ICP create/update payload (name, flag and the full criteria set). Authorisation is
 * handled in the controller via {@see IcpPolicy}. Weight is only meaningful
 * for positive signals — it's optional here and normalised in {@see IcpData}.
 */
final class SaveIcpRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],

            'criteria' => ['present', 'array', 'max:50'],
            'criteria.*.type' => ['required', Rule::enum(CriterionType::class)],
            'criteria.*.value' => ['required', 'string', 'max:255'],
            'criteria.*.weight' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'criteria.*.type' => 'criterion type',
            'criteria.*.value' => 'criterion value',
            'criteria.*.weight' => 'criterion weight',
        ];
    }
}
