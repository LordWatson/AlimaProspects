<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Contracts\TpsScreeningProvider;
use App\Modules\Compliance\Enums\TpsRegister;
use App\Modules\Core\Enums\TpsStatus;

/**
 * The shipped {@see TpsScreeningProvider}: no licence, so no answer (PL5).
 *
 * This is the default because TPS and CTPS are licensed datasets and screening inside a product
 * needs a licence that permits it, which is an open item rather than code. Everything around it is
 * finished: bind a licensed adapter and the cache, the 28-day re-screen, the badge and the dial-link
 * rule all start working with no other change.
 *
 * It returns **null, never `Clear`**. The temptation in an unlicensed build is to default to clear
 * so nothing looks broken; that would make every number in the product read as screened and
 * callable, which is the single worst thing this file could do.
 */
final class UnlicensedTpsScreening implements TpsScreeningProvider
{
    public function key(): string
    {
        return 'none';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function screen(string $digits, TpsRegister $register): ?TpsStatus
    {
        return null;
    }
}
