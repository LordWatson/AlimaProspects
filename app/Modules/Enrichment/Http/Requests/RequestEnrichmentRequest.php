<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Http\Requests;

use App\Modules\Enrichment\Enums\EnrichmentScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to enrich a lead (PL3). Authorisation is the controller's, via the policy.
 *
 * `leads` is only present on the bulk form. It is capped here **and** in the Action: this request
 * never sees a bulk call that arrives through `/api/v1`, and the cap is the thing that bounds what
 * one click can spend.
 */
final class RequestEnrichmentRequest extends FormRequest
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
            'scope' => ['required', Rule::enum(EnrichmentScope::class)],
            'leads' => ['sometimes', 'array', 'max:'.(int) config('enrichment.max_bulk_leads', 50)],
            'leads.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    public function scope(): EnrichmentScope
    {
        return EnrichmentScope::from((string) $this->validated('scope'));
    }

    /**
     * @return list<int>
     */
    public function leadIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = (array) ($this->validated('leads') ?? []);

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
