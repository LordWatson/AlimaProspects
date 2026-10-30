<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Requests;

use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Outreach\Data\OutreachTemplateData;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a message brief.
 *
 * The name is unique **per team**, and the rule is scoped that way explicitly: without the
 * `team_id` clause, one team naming a brief "First touch" would stop every other team from doing
 * the same.
 */
final class SaveOutreachTemplateRequest extends FormRequest
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
        $teamId = $this->user()?->currentTeamId();

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('outreach_templates', 'name')
                    ->where('team_id', $teamId)
                    // On an update, the brief's own row must not collide with itself.
                    ->ignore($this->route('template')),
            ],
            'channel' => ['required', Rule::in(OutreachChannel::values())],
            'tone' => ['required', Rule::in(OutreachTone::values())],
            'locale' => ['nullable', Rule::in(SupportedLocale::values())],
            // Allowed for any channel and dropped for the ones with no subject line (see
            // OutreachTemplateData) — the form keeps the field mounted while the member switches
            // channel, and rejecting it would lose what they had typed.
            'subject' => ['nullable', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:4000'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function templateData(): OutreachTemplateData
    {
        return OutreachTemplateData::fromValidated($this->validated());
    }
}
