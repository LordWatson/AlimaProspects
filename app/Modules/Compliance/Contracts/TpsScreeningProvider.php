<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Contracts;

use App\Modules\Compliance\Enums\TpsRegister;
use App\Modules\Core\Enums\TpsStatus;

/**
 * A licensed lookup against a UK do-not-call register (PL5).
 *
 * The same shape as every other provider seam here (ADR 0008 / 0023 / 0029): one narrow contract,
 * one key, one availability question, and a default that says "I cannot answer" rather than one
 * that guesses. There is **no real adapter yet** and that is a licensing fact rather than an
 * omission — TPS and CTPS are licensed datasets, and automated screening inside a product needs a
 * licence that permits it (`docs/prelaunch/progress.md`, open items). The screener, the cache, the
 * staleness window and the badge all work the moment one is bound.
 *
 * **Health and validity are different questions**, as everywhere else in this codebase: a provider
 * that is down returns null from {@see self::screen()} and leaves the number `unchecked`, which is
 * the honest state and the one the product warns on. It must never return {@see TpsStatus::Clear}
 * to mean "I could not reach the register" — that single conflation would put a green light on
 * every number in the product during an outage.
 */
interface TpsScreeningProvider
{
    /**
     * The provider key, recorded on the cached verdict so an operator can tell a licensed lookup
     * from a seeded fixture.
     */
    public function key(): string;

    /**
     * Whether this provider is configured enough to be asked. A provider that is not available is
     * never called, and the number stays `unchecked`.
     */
    public function isAvailable(): bool;

    /**
     * The register's answer for these normalised digits, or **null** when it could not be obtained.
     *
     * Only {@see TpsStatus::Clear} and {@see TpsStatus::Registered} are valid answers; anything
     * else is null. Implementations must not throw for an ordinary outage — the screening is an
     * enhancement to a reveal that has already happened, and failing the reveal because a register
     * was slow would charge a member for nothing.
     */
    public function screen(string $digits, TpsRegister $register): ?TpsStatus;
}
