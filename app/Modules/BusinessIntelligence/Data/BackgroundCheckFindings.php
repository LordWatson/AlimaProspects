<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Data;

use App\Modules\AiPlatform\Data\StructuredAiResponse;
use App\Modules\Core\Enums\Currency;
use App\Modules\Onboarding\Enums\DeliveryModel;
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
     * @param  list<string>  $proofCustomers  customers the site names — the best lookalike seeds there are
     * @param  list<string>  $problemsSolved
     * @param  list<string>  $buyingTriggers  situations that make a customer ready to buy
     * @param  list<string>  $antiCustomers  who the business says it does not serve
     * @param  int|null  $pricingMin  minor units
     * @param  int|null  $pricingMax  minor units
     * @param  list<array{field: string, value: string, page: int, quote: string, confidence: int, url?: string}>  $evidence
     *                                                                                                                        the page and the words each finding rests on
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
        public array $proofCustomers = [],
        public array $problemsSolved = [],
        public array $buyingTriggers = [],
        public array $antiCustomers = [],
        public ?int $pricingMin = null,
        public ?int $pricingMax = null,
        public ?Currency $pricingCurrency = null,
        public ?DeliveryModel $deliveryModel = null,
        public ?string $customerSizeRange = null,
        public array $evidence = [],
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
            proofCustomers: self::strings($data['proof_customers'] ?? []),
            problemsSolved: self::strings($data['problems_solved'] ?? []),
            buyingTriggers: self::strings($data['buying_triggers'] ?? []),
            antiCustomers: self::strings($data['anti_customers'] ?? []),
            pricingMin: self::money($data['pricing_min'] ?? null),
            pricingMax: self::money($data['pricing_max'] ?? null),
            // A value outside the enum is "not stated", never coerced: these two steer a search.
            pricingCurrency: Currency::tryFrom(Str::lower((string) self::nullableText($data['pricing_currency'] ?? null))),
            deliveryModel: DeliveryModel::tryFrom(Str::lower((string) self::nullableText($data['delivery_model'] ?? null))),
            // Clamped to its column: the model was asked for "10-200 employees" and once wrote a sentence.
            customerSizeRange: ($size = self::nullableText($data['customer_size_range'] ?? null)) === null ? null : Str::limit($size, 64, ''),
            evidence: self::evidence($data['evidence'] ?? []),
        );
    }

    /**
     * The same findings, keeping only the evidence that is really on the page it cites.
     *
     * The prompt is told to copy a short exact quote for each finding it can support. A prompt
     * describes a constraint, it does not enforce one — so each quote is looked for in the text
     * that was actually read, and an entry whose quote is not there (a paraphrase, a page number
     * that was never offered, something recalled) is dropped. The finding itself stays: it is then
     * simply a finding with nothing to show for it, and the review screen says so.
     *
     * @param  list<array{url: string, text: string}>  $pages  as sent to the model, numbered from 1
     */
    public function withVerifiedEvidence(array $pages): self
    {
        $kept = [];

        foreach ($this->evidence as $item) {
            $page = $pages[$item['page'] - 1] ?? null;
            $quote = self::fold($item['quote']);

            // Too short to be evidence of anything: "we do" is on every page.
            if ($page === null || mb_strlen($quote) < 12 || ! str_contains(self::fold($page['text']), $quote)) {
                continue;
            }

            $kept[] = [...$item, 'url' => $page['url']];
        }

        $clone = clone $this;
        $clone->evidence = $kept;

        return $clone;
    }

    /**
     * Case- and whitespace-insensitive, with typographic quotes and dashes flattened: a page's
     * curly apostrophe and the model's straight one are the same quote.
     */
    private static function fold(string $text): string
    {
        return Str::lower(Str::squish(strtr($text, [
            '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-',
        ])));
    }

    /**
     * A price in major units, as minor units. Null for anything that is not a positive number.
     */
    private static function money(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value > 0 && (float) $value < 1_000_000_000
            ? (int) round((float) $value * 100)
            : null;
    }

    /**
     * @return list<array{field: string, value: string, page: int, quote: string, confidence: int, url?: string}>
     */
    private static function evidence(mixed $value): array
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row) || ! is_string($row['field'] ?? null) || ! is_string($row['quote'] ?? null)) {
                continue;
            }

            $rows[] = array_filter([
                'field' => Str::limit($row['field'], 40, ''),
                'value' => self::text($row['value'] ?? ''),
                'page' => (int) ($row['page'] ?? 0),
                'quote' => Str::limit(Str::squish($row['quote']), 300, ''),
                'confidence' => max(0, min(100, (int) ($row['confidence'] ?? 0))),
                // Present on a stored report, where the quote has already been checked.
                'url' => is_string($row['url'] ?? null) ? $row['url'] : null,
            ], static fn (mixed $v): bool => $v !== null);

            if (count($rows) >= 40) {
                break;
            }
        }

        return $rows;
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
            'proof_customers' => $this->proofCustomers,
            'problems_solved' => $this->problemsSolved,
            'buying_triggers' => $this->buyingTriggers,
            'anti_customers' => $this->antiCustomers,
            // Major units, as the prompt returns them, so a stored report reads back the same way.
            'pricing_min' => $this->pricingMin === null ? null : $this->pricingMin / 100,
            'pricing_max' => $this->pricingMax === null ? null : $this->pricingMax / 100,
            'pricing_currency' => $this->pricingCurrency?->value,
            'delivery_model' => $this->deliveryModel?->value,
            'customer_size_range' => $this->customerSizeRange,
            'evidence' => $this->evidence,
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
