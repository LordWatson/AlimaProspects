<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Core\Enums\TeamRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteMemberRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            // Owners can be seeded but not invited — invitations grant managed roles only.
            'role' => ['required', Rule::in([
                TeamRole::Admin->value,
                TeamRole::Member->value,
                TeamRole::ReadOnly->value,
            ])],
        ];
    }
}
