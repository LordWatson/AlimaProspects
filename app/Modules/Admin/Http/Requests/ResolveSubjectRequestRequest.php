<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Models\User;
use App\Modules\Compliance\Enums\SubjectRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Closing a public data-subject request (PL5).
 *
 * Only the two **outcome** statuses are accepted, so there is no route back to `open` or
 * `pending_verification` through this form — a request that has been answered has been answered,
 * and re-opening one would reset a statutory clock that has already run.
 *
 * The note is `required_if` a rejection rather than always required, which is the same asymmetry
 * {@see RestoreTeamRequest} keeps: completing a request is the expected outcome and needs no
 * justification, while a refusal is the one a regulator will ask about. The Action checks the same
 * thing, because the rule is the domain's and not the form's.
 */
final class ResolveSubjectRequestRequest extends FormRequest
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
            'status' => ['required', Rule::in([
                SubjectRequestStatus::Completed->value,
                SubjectRequestStatus::Rejected->value,
            ])],
            'note' => [
                'nullable',
                'required_if:status,'.SubjectRequestStatus::Rejected->value,
                'string',
                'max:1000',
            ],
        ];
    }

    public function status(): SubjectRequestStatus
    {
        return SubjectRequestStatus::from((string) $this->validated('status'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    /**
     * The signed-in operator. Guaranteed by the panel's stack — `auth` then `EnsurePlatformAdmin`
     * — so this narrows the type rather than checking anything.
     */
    public function operator(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
