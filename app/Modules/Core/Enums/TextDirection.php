<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * Writing direction for a locale. Single source of truth — no magic 'ltr'/'rtl'.
 */
enum TextDirection: string
{
    case Ltr = 'ltr';
    case Rtl = 'rtl';
}
