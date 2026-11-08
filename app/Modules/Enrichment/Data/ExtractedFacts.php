<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\AiPlatform\Data\StructuredAiResponse;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated shape of a `lead.enrich_extract` result, hydrated from the AI Platform's
 * {@see StructuredAiResponse}. Mirrors the prompt's JSON schema (all fields optional — the model
 * leaves a field null when the page doesn't state it). Every value is trimmed, clamped and (for the
 * lists) capped so a runaway or hostile response can't bloat storage or later prompts. These are
 * *candidate* facts, never asserted truth — the caller attaches provenance and verifies emails.
 */
final class ExtractedFacts extends Data
{
    private const int MAX_TEXT = 255;

    private const int MAX_ITEM = 120;

    private const int MAX_ITEMS = 20;

    private const int MAX_EMAILS = 10;

    private const int MAX_PEOPLE = 10;

    /**
     * @param  list<string>  $locations
     * @param  list<string>  $technologies
     * @param  list<string>  $emails
     * @param  list<array{name: string, title: ?string}>  $people  named people the page mentions
     *                                                             (name + optional role), used to
     *                                                             infer a contact email when the
     *                                                             page states none
     */
    public function __construct(
        public ?string $companyName = null,
        public ?string $website = null,
        public ?string $industry = null,
        public ?string $employeeRange = null,
        public array $locations = [],
        public array $technologies = [],
        public array $emails = [],
        public ?string $phone = null,
        public array $people = [],
    ) {}

    /**
     * Build from the AI response's raw validated data array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromAi(array $data): self
    {
        return new self(
            companyName: self::text($data['company_name'] ?? null),
            website: self::text($data['website'] ?? null),
            industry: self::text($data['industry'] ?? null),
            employeeRange: self::employeeRange($data['employee_range'] ?? null),
            locations: self::strings($data['locations'] ?? [], self::MAX_ITEMS),
            technologies: self::strings($data['technologies'] ?? [], self::MAX_ITEMS),
            // Lower-case and de-duplicate extracted emails, then cap how many we keep.
            emails: self::emails($data['emails'] ?? []),
            phone: self::text($data['phone'] ?? null),
            people: self::people($data['people'] ?? []),
        );
    }

    /**
     * Shape the extracted people into `{name, title}` rows: a trimmed, clamped name (required) and an
     * optional role. Entries without a usable name are dropped, and the list is capped.
     *
     * @return list<array{name: string, title: ?string}>
     */
    private static function people(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $person) {
            if (! is_array($person)) {
                continue;
            }

            $name = self::text($person['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $out[] = ['name' => $name, 'title' => self::text($person['title'] ?? null)];

            if (count($out) >= self::MAX_PEOPLE) {
                break;
            }
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        // A lead field is plain text, never markup. Decode HTML entities first (so "Pip &amp; Nut"
        // is stored as "Pip & Nut"), then strip any tags the extractor dragged off the page (a stray
        // "<br>Skip to content" and the like), then trim.
        $clean = trim(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5)));

        return $clean === '' ? null : Str::limit($clean, self::MAX_TEXT, '');
    }

    /**
     * Normalise the employee range to a size band and nothing else. A loosely-typed string field
     * is where a model's output goes wrong: one real lead came back with
     * `11-50','locality':'London'}]}` — the model leaking its own JSON structure (and page markup)
     * into the value. The scorer reads this field as a numeric band, and the UI shows it verbatim,
     * so both need it clean.
     *
     * HTML is stripped by {@see self::text()}. If what remains carries structural junk the model
     * never should have put in a size band — quotes, braces, brackets, backslashes, angle brackets —
     * only the leading size figure is kept (`11-50` out of the mess above); a value with no salvageable
     * figure is dropped. A clean value (numeric or a plain word like "Enterprise") passes through.
     */
    private static function employeeRange(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        if (preg_match('/[{}\[\]"\'\\\\<>|=]/', $text) !== 1) {
            return $text; // nothing suspect — keep it (already trimmed and clamped)
        }

        // Corrupted: salvage the first size-band figure ("11-50", "1,000 to 5,000", "500+") or drop it.
        if (preg_match('/\d[\d,]*(?:\s*(?:-|–|to|and)\s*\d[\d,]*)?\s*\+?/u', $text, $matches) === 1) {
            $band = trim($matches[0]);

            return $band === '' ? null : $band;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, int $max): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $trimmed = trim((string) $item);
                if ($trimmed !== '') {
                    $out[] = Str::limit($trimmed, self::MAX_ITEM, '');
                }
            }

            if (count($out) >= $max) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private static function emails(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $email = Str::lower(trim($item));
            if ($email !== '' && ! in_array($email, $out, strict: true)) {
                $out[] = Str::limit($email, self::MAX_TEXT, '');
            }

            if (count($out) >= self::MAX_EMAILS) {
                break;
            }
        }

        return $out;
    }
}
