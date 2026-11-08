<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * Which UK do-not-call register a number was screened against (PL5).
 *
 * Two registers, because the UK has two and they cover different subscribers: **TPS** is the
 * individual one (including sole traders and partnerships), **CTPS** the corporate one. A number
 * clear on one may be registered on the other, so a screening says which it asked — and which one
 * to ask is decided by {@see PecrSubscriberType}, not by the caller's preference.
 *
 * An enum rather than a string because the application branches on it (ADR 0018 §1): the provider
 * is asked a different question and a different licence covers each.
 */
enum TpsRegister: string
{
    case Tps = 'tps';
    case Ctps = 'ctps';

    public function label(): string
    {
        return match ($this) {
            self::Tps => 'TPS',
            self::Ctps => 'CTPS',
        };
    }
}
