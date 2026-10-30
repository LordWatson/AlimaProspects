<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Data;

use Spatie\LaravelData\Data;

/**
 * The validated choices a team makes when accepting a background-check report: whether to merge its
 * findings into the Business Profile, and whether to seed a suggested ICP from them.
 */
final class AcceptBackgroundCheckData extends Data
{
    public function __construct(
        public bool $applyToProfile = true,
        public bool $seedIcp = false,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            applyToProfile: (bool) ($validated['apply_to_profile'] ?? true),
            seedIcp: (bool) ($validated['seed_icp'] ?? false),
        );
    }
}
