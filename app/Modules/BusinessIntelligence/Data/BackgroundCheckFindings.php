<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Data;

use App\Modules\AiPlatform\Data\StructuredAiResponse;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated shape of a `business_intel.background_check` result, hydrated from the AI Platform's
 * {@see StructuredAiResponse}. Mirrors the prompt's JSON schema
 * (summary/offering/positioning required; the lists default to empty; confidence is 0–1). Kept as a
 * DTO so the job, the stored report and the review UI share one typed contract.
 *
 * **The seller/buyer split is the point of this DTO.** `ownIndustry` and `competitors` describe the
 * team's own business; `customerIndustries`, `targetCustomers` and `decisionMakerTitles` describe who
 * buys from it. Prompt v1 had a single `suggested_industries` list and said nothing about which it
 * meant, so the same values were written to the team's own profile *and* seeded as the ICP's industry
 * signal — which is how a design studio ended up with an ICP that found design studios. Keeping the
 * two sides in separate, named properties is what stops that recurring.
 */
final class BackgroundCheckFindings extends Data
{
    /** Clamp the free-text fields so a runaway model response can't bloat storage or later prompts. */
    private const int MAX_TEXT = 2_000;

    /** Clamp each list item, and cap how many items a list may contribute. */
    private const int MAX_ITEM = 160;

    private const int MAX_ITEMS = 12;

    /**
     * @param  list<string>  $customerIndustries  industries the team's CUSTOMERS are in
     * @param  list<string>  $targetCustomers  kinds of organisation that buy
     * @param  list<string>  $decisionMakerTitles  job titles that buy, at a customer
     * @param  list<string>  $competitors  businesses that sell what the team sells
     */
    public function __construct(
        public string $summary,
        public string $offering,
        public string $positioning,
        public ?string $ownIndustry = null,
        public array $customerIndustries = [],
        public array $targetCustomers = [],
        public array $decisionMakerTitles = [],
        public array $competitors = [],
        public ?float $confidence = null,
    ) {}

    /**
     * Build from the AI response's raw validated data array, or from a stored report's JSON.
     *
     * Reports written under prompt v1 hold a `suggested_industries` list whose values are the team's
     * **own** trade (that was the only reading v1's framing supported). They are read back into
     * `ownIndustry` and deliberately **not** into `customerIndustries`: an empty customer list makes
     * the gap visible and offers a re-run, where quietly reusing the old values would re-seed exactly
     * the wrong ICP.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromAi(array $data): self
    {
        $legacy = self::strings($data['suggested_industries'] ?? []);

        return new self(
            summary: self::text($data['summary'] ?? ''),
            offering: self::text($data['offering'] ?? ''),
            positioning: self::text($data['positioning'] ?? ''),
            ownIndustry: self::nullableText($data['own_industry'] ?? ($legacy[0] ?? null)),
            customerIndustries: self::strings($data['customer_industries'] ?? []),
            targetCustomers: self::strings($data['target_customers'] ?? []),
            decisionMakerTitles: self::strings($data['decision_maker_titles'] ?? []),
            competitors: self::strings($data['competitors'] ?? []),
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
        );
    }

    /**
     * Whether the check produced anything usable as a customer-side signal. False means the model
     * couldn't tell who buys from this business — the review screen says so and offers a re-run
     * rather than seeding an ICP from the seller's own trade.
     */
    public function hasCustomerSignals(): bool
    {
        return $this->customerIndustries !== []
            || $this->targetCustomers !== []
            || $this->decisionMakerTitles !== [];
    }

    /**
     * The persisted/storable associative form (snake_case keys matching the prompt schema).
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'summary' => $this->summary,
            'offering' => $this->offering,
            'positioning' => $this->positioning,
            'own_industry' => $this->ownIndustry,
            'customer_industries' => $this->customerIndustries,
            'target_customers' => $this->targetCustomers,
            'decision_maker_titles' => $this->decisionMakerTitles,
            'competitors' => $this->competitors,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * Coerce a loosely-typed value into a clean, bounded list of non-empty strings (each clamped,
     * the list capped) so a runaway response can't bloat storage, the profile or later prompts.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $trimmed = trim((string) $item);
                if ($trimmed !== '') {
                    $strings[] = Str::limit($trimmed, self::MAX_ITEM, '');
                }
            }

            if (count($strings) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $strings;
    }

    /**
     * Trim and clamp a free-text field.
     */
    private static function text(mixed $value): string
    {
        return Str::limit(trim((string) $value), self::MAX_TEXT, '');
    }

    /**
     * Trim and clamp an optional single-line field, collapsing blank to null.
     */
    private static function nullableText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, self::MAX_ITEM, '');
    }
}
