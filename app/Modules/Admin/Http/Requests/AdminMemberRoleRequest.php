<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Core\Enums\TeamRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing a member's role on a team from the panel (Phase 19a).
 *
 * Its own request rather than Auth's `UpdateMemberRoleRequest`, because that one authorises against
 * the team policy — the member must be an Owner or Admin *of that team* — and an operator is a
 * member of no team at all. The authorisation here is the admin stack and the route's
 * `admin.can:teams.manage`; the rules are the same because the rules are about the role, not about
 * who is asking.
 *
 * This is the transfer-of-ownership path too: promoting somebody to Owner is how a tenant whose
 * only Owner has left the company gets their account back. `UpdateTeamMemberRoleAction` still
 * refuses to demote the last Owner, so the panel cannot leave a team ownerless either.
 */
final class AdminMemberRoleRequest extends FormRequest
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
            'role' => ['required', Rule::enum(TeamRole::class)],
        ];
    }

    public function role(): TeamRole
    {
        return TeamRole::from((string) $this->validated('role'));
    }
}
