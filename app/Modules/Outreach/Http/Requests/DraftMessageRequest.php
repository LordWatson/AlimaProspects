<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Requests;

use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Outreach\Data\DraftMessageData;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use App\Modules\Outreach\Services\OutreachGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a drafting request.
 *
 * The two existence rules are the tenancy ones, and both are scoped to the caller's own team: a
 * contact id from another team's lead, or another team's brief, must fail validation rather than be
 * quietly ignored. Whether the *channel* may be used is not asked here — that is
 * {@see OutreachGuard}'s decision, and a compliance rule expressed
 * as a validation message would be a compliance rule a client could read off the form.
 */
final class DraftMessageRequest extends FormRequest
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
            'channel' => ['required', Rule::in(OutreachChannel::values())],
            'tone' => ['required', Rule::in(OutreachTone::values())],
            'locale' => ['nullable', Rule::in(SupportedLocale::values())],
            'lead_contact_id' => [
                'nullable',
                'integer',
                Rule::exists('lead_contacts', 'id')->where('team_id', $this->user()?->currentTeamId()),
            ],
            'template_id' => [
                'nullable',
                'integer',
                Rule::exists('outreach_templates', 'id')->where('team_id', $this->user()?->currentTeamId()),
            ],
        ];
    }

    public function draftData(): DraftMessageData
    {
        return DraftMessageData::fromValidated(
            $this->validated(),
            // The language the member is reading the app in, as the default for what they write —
            // but only a default: a bilingual team drafts Arabic from an English UI all day.
            SupportedLocale::tryFromString(app()->getLocale()) ?? SupportedLocale::default(),
        );
    }
}
