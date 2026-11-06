<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Requests;

use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Policies\IcpPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an ICP create/update payload (name, flag and the full criteria set). Authorisation is
 * handled in the controller via {@see IcpPolicy}. The rules themselves live on
 * {@see IcpData::rules()}, because the AI composer validates a draft against exactly the same set.
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
        return IcpData::rules();
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
