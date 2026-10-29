<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Data;

use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Http\Requests\StartDiscoveryRequest;
use Spatie\LaravelData\Data;

/**
 * The validated input for starting a discovery run (from {@see StartDiscoveryRequest}).
 * `icpId` is optional — when omitted the team's active ICP is used; `terms`/`location` override or
 * supplement what the ICP implies.
 */
final class StartDiscoveryData extends Data
{
    public function __construct(
        public LeadSource $source,
        public ?int $icpId = null,
        public ?string $terms = null,
        public ?string $location = null,
        public ?int $limit = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            source: LeadSource::from((string) $validated['source']),
            icpId: isset($validated['icp_id']) ? (int) $validated['icp_id'] : null,
            terms: isset($validated['terms']) && $validated['terms'] !== '' ? (string) $validated['terms'] : null,
            location: isset($validated['location']) && $validated['location'] !== '' ? (string) $validated['location'] : null,
            limit: isset($validated['limit']) ? (int) $validated['limit'] : null,
        );
    }
}
