<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Models\Signal;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for turning a {@see Signal} into a lead (S4). The convert form is prefilled
 * from the signal — {@see self::prefillFrom()} — and the member may correct every field before
 * committing, because an article's wording is not a company record.
 *
 * Two things are deliberate:
 *
 * - **`companyName` is required and never invented.** A relocation signal often names a person and
 *   no company at all (ADR 0011 risk note). Rather than guessing a holding entity or filing the
 *   person as a company, the form asks: a lead in this product is a company, so the member supplies
 *   the person's business or holding entity, or doesn't convert.
 * - **No email, no phone.** A signal stores a name and a public role only (data minimisation), so
 *   there is nothing here to carry one, and {@see ConvertSignalToLeadAction} writes the contact with
 *   both columns null. The next step is LinkedIn or a referral (UAE PDPL, ADR 0007).
 *
 * Values are clamped to their columns here, so a long pasted company name can't 500 the conversion
 * after the form has validated.
 */
final class ConvertSignalData extends Data
{
    private const int MAX_TEXT = 255;

    /** What a contact created from a signal records as its provenance (ADR 0008). */
    public const string CONTACT_SOURCE = 'signal';

    public const string CONTACT_METHOD = 'news_extraction';

    public function __construct(
        public readonly string $companyName,
        public readonly ?string $website = null,
        public readonly ?string $personName = null,
        public readonly ?string $personRole = null,
        public readonly ?string $locality = null,
        public readonly ?string $country = null,
        public readonly ?int $pipelineStageId = null,
        public readonly ?string $note = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated, Signal $signal): self
    {
        $prefill = self::prefillFrom($signal);

        return new self(
            companyName: self::text($validated['company_name'] ?? null) ?? $prefill->companyName,
            website: self::text($validated['website'] ?? null),
            // A blank person field means "don't record a contact", not "fall back to the article" —
            // a member who cleared the name did so on purpose.
            personName: array_key_exists('person_name', $validated)
                ? self::text($validated['person_name'])
                : $prefill->personName,
            personRole: array_key_exists('person_role', $validated)
                ? self::text($validated['person_role'])
                : $prefill->personRole,
            locality: self::text($validated['locality'] ?? null) ?? $prefill->locality,
            country: self::country($validated['country'] ?? null) ?? $prefill->country,
            pipelineStageId: isset($validated['pipeline_stage_id']) ? (int) $validated['pipeline_stage_id'] : null,
            note: self::note($validated['note'] ?? null),
        );
    }

    /**
     * What the form shows before the member touches it: the company, person and place the AI
     * extracted, falling back to the watched market's defaults (v1 is UAE-only, ADR 0011).
     * `companyName` may come back empty — that is exactly the case the required field exists for.
     */
    public static function prefillFrom(Signal $signal): self
    {
        return new self(
            companyName: self::text($signal->subject_company_name) ?? '',
            personName: self::text($signal->subject_person_name),
            personRole: self::text($signal->subject_person_role),
            locality: self::text($signal->location) ?? self::defaultLocality(),
            country: self::defaultCountry(),
        );
    }

    /**
     * The validation rules the convert request applies. Lives beside the shape it validates so the
     * web and API endpoints (S5/S6) can't drift apart on what a conversion accepts.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'person_name' => ['nullable', 'string', 'max:255'],
            'person_role' => ['nullable', 'string', 'max:255'],
            'locality' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'pipeline_stage_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The market default seeded into the form. `Dubai` per the v1 scope, from the same config the
     * query builder reads, so the two can't disagree about which market Signals is for.
     */
    private static function defaultLocality(): string
    {
        $location = config('signals.markets.uae.default_location', 'Dubai');

        return is_string($location) && $location !== '' ? $location : 'Dubai';
    }

    private static function defaultCountry(): string
    {
        $gl = config('signals.markets.uae.gl', 'ae');

        return Str::upper(is_string($gl) && strlen($gl) === 2 ? $gl : 'ae');
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $squished = Str::squish($value);

        return $squished === '' ? null : Str::limit($squished, self::MAX_TEXT, '');
    }

    /**
     * A two-letter ISO code upper-cased, or null — the column is `char(2)`, so a member who typed
     * "United Arab Emirates" must not have it silently truncated to "UN".
     */
    private static function country(mixed $value): ?string
    {
        if (! is_string($value) || Str::length(trim($value)) !== 2) {
            return null;
        }

        return Str::upper(trim($value));
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
