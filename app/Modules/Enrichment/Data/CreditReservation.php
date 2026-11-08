<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\Enrichment\Enums\EnrichmentScope;
use Spatie\LaravelData\Data;

/**
 * A hold on a team's allowance, or a refusal with a reason (PL3).
 *
 * One type for both answers rather than a nullable return, so a caller cannot forget to ask: the
 * only way to read a reservation is through {@see self::granted()}, and a refusal carries the code
 * the run records and the screen explains.
 *
 * `$reference` is whatever the reserver needs to find its own booking again at commit time — a row
 * id for PL4, an empty string for the unmetered default. Enrichment never looks inside it.
 */
final class CreditReservation extends Data
{
    public function __construct(
        public EnrichmentScope $scope,
        /** How many units were held. */
        public int $units,
        /** The reserver's own handle on the booking. Opaque to this module. */
        public string $reference = '',
        /** Set only on a refusal — a stable code, never a message (`enrichment.no_allowance`). */
        public ?string $refusalCode = null,
    ) {}

    public static function grant(EnrichmentScope $scope, int $units, string $reference = ''): self
    {
        return new self(scope: $scope, units: $units, reference: $reference);
    }

    /**
     * A refusal. Not an exception: a team at its ceiling is an ordinary answer, and raising it
     * would put a red light on a provider that answered perfectly well.
     */
    public static function refuse(EnrichmentScope $scope, string $code): self
    {
        return new self(scope: $scope, units: 0, refusalCode: $code);
    }

    public function granted(): bool
    {
        return $this->refusalCode === null;
    }
}
