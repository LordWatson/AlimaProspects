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

    /**
     * @param  list<string>  $locations
     * @param  list<string>  $technologies
     * @param  list<string>  $emails
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
            employeeRange: self::text($data['employee_range'] ?? null),
            locations: self::strings($data['locations'] ?? [], self::MAX_ITEMS),
            technologies: self::strings($data['technologies'] ?? [], self::MAX_ITEMS),
            // Lower-case and de-duplicate extracted emails, then cap how many we keep.
            emails: self::emails($data['emails'] ?? []),
            phone: self::text($data['phone'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, self::MAX_TEXT, '');
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
