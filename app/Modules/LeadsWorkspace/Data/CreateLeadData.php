<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Data;

use App\Modules\LeadsWorkspace\Actions\CreateLeadAction;
use App\Modules\LeadsWorkspace\Actions\SyncLeadTagsAction;
use App\Modules\LeadsWorkspace\Support\OwnLeadFields;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for adding one lead **by hand** (Phase 10c) — the company a member met at an
 * event, was referred to, or found somewhere Alima doesn't look.
 *
 * What the shape is about:
 *
 * - **Only the company name is required.** The point of this screen is to let someone get a company
 *   out of their head (or off a business card) and into the product in seconds, from a phone, while
 *   they still remember the conversation. Everything else is optional, and enrichment fills in what
 *   it can afterwards. Asking for a website or a registry number up front would mean leads lost to
 *   a form.
 * - **A contact may carry an email and a phone number.** Unlike a signal — which holds a name and a
 *   public role only, because it was read off a news article — a hand-added contact is usually
 *   someone who handed over a card. The team is the controller for what they type (see
 *   docs/compliance.md), so the details are stored as given, attributed to the member, and left
 *   unverified for the normal verification to judge.
 * - **Values are clamped to their columns here**, so a long pasted company name can't 500 the write
 *   after the form has already validated. The clamping and the identity key are
 *   {@see OwnLeadFields}', shared with the CSV importer so the two ways a team supplies a company
 *   agree on both.
 *
 * @see CreateLeadAction for what is done with it.
 */
final class CreateLeadData extends Data
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $companyName,
        public readonly ?string $companyNumber = null,
        public readonly ?string $website = null,
        public readonly ?string $industry = null,
        public readonly ?string $employeeRange = null,
        public readonly ?string $locality = null,
        public readonly ?string $region = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $country = null,
        public readonly ?string $contactName = null,
        public readonly ?string $contactTitle = null,
        public readonly ?string $contactEmail = null,
        public readonly ?string $contactPhone = null,
        public readonly array $tags = [],
        public readonly ?int $pipelineStageId = null,
        public readonly ?string $note = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        /** @var array<int, mixed> $tags */
        $tags = is_array($validated['tags'] ?? null) ? $validated['tags'] : [];

        return new self(
            companyName: self::text($validated['company_name'] ?? null) ?? '',
            companyNumber: self::text($validated['company_number'] ?? null),
            website: self::text($validated['website'] ?? null),
            industry: self::text($validated['industry'] ?? null),
            employeeRange: self::text($validated['employee_range'] ?? null),
            locality: self::text($validated['locality'] ?? null),
            region: self::text($validated['region'] ?? null),
            postalCode: self::text($validated['postal_code'] ?? null),
            country: self::text($validated['country'] ?? null),
            contactName: self::text($validated['contact_name'] ?? null),
            contactTitle: self::text($validated['contact_title'] ?? null),
            contactEmail: self::text($validated['contact_email'] ?? null),
            contactPhone: self::text($validated['contact_phone'] ?? null),
            // Squished, clamped to the tag column and de-duplicated by the same splitter the CSV
            // importer uses — so a tag typed here and the same tag in a spreadsheet are one tag.
            tags: OwnLeadFields::splitTags(implode(',', array_map(
                static fn (mixed $tag): string => (string) $tag,
                $tags,
            ))),
            pipelineStageId: isset($validated['pipeline_stage_id']) ? (int) $validated['pipeline_stage_id'] : null,
            note: self::note($validated['note'] ?? null),
        );
    }

    /**
     * The validation rules the add-a-lead request applies. They live beside the shape they validate
     * so the web form and `/api/v1` can't drift apart on what adding a lead accepts.
     *
     * `company_name` is the only required field, and `website` is deliberately **not** `url:` —
     * someone typing a company off a card writes `brightbean.co.uk`, not `https://brightbean.co.uk`,
     * and {@see OwnLeadFields::safeUrl()} works out the rest, dropping anything that still isn't a
     * link we would render. A form that rejected a bare domain would be a form people stop using.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'company_number' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'employee_range' => ['nullable', 'string', 'max:64'],
            'locality' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            // Two letters or nothing: the column is char(2), so "United Kingdom" would otherwise be
            // stored as "UN".
            'country' => ['nullable', 'string', 'size:2'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_title' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'tags' => ['nullable', 'array', 'max:'.SyncLeadTagsAction::MAX_PER_LEAD],
            'tags.*' => ['string', 'max:40'],
            'pipeline_stage_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The company facts as {@see OwnLeadFields} wants them — a flat bag of strings keyed by column,
     * which is also what the CSV importer hands it. One normaliser, two ways in.
     *
     * @return array<string, string>
     */
    public function toValues(): array
    {
        return [
            'company_name' => $this->companyName,
            'company_number' => (string) $this->companyNumber,
            'website' => (string) $this->website,
            'industry' => (string) $this->industry,
            'employee_range' => (string) $this->employeeRange,
            'locality' => (string) $this->locality,
            'region' => (string) $this->region,
            'postal_code' => (string) $this->postalCode,
            'country' => (string) $this->country,
        ];
    }

    /**
     * Whether the member typed anything that amounts to a contact. A title alone doesn't: "Head of
     * Operations" with no name, email or phone is a note about the company, not a person to reach.
     */
    public function hasContact(): bool
    {
        return $this->contactName !== null
            || $this->contactEmail !== null
            || $this->contactPhone !== null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $squished = Str::squish($value);

        return $squished === '' ? null : Str::limit($squished, 255, '');
    }

    /**
     * The member's own note, kept as typed (newlines and all) but clamped to the column. Squishing
     * it would flatten a three-line note into one.
     */
    private static function note(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), 2000, '');
    }
}
