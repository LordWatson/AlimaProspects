<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Requests;

use App\Modules\LeadSourcing\Enums\EvidenceVerdict;
use App\Modules\LeadSourcing\Policies\LeadEvidencePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a member's verdict on one item of evidence (PL1).
 *
 * Nothing here authorises anything — {@see LeadEvidencePolicy}
 * does, at the controller. The note is capped at the column's own length rather than left to be
 * truncated silently: a member writing why a filing is wrong should be told their explanation is
 * too long, not have the end of it quietly dropped.
 */
final class SubmitEvidenceFeedbackRequest extends FormRequest
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
            'verdict' => ['required', Rule::enum(EvidenceVerdict::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function verdict(): EvidenceVerdict
    {
        return EvidenceVerdict::from((string) $this->validated('verdict'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
