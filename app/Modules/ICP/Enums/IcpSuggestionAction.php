<?php

declare(strict_types=1);

namespace App\Modules\ICP\Enums;

/**
 * What a suggestion proposes to do to a profile. Three verbs and no fourth: a suggestion changes
 * one criterion, so a member can accept it knowing exactly what will be different afterwards.
 */
enum IcpSuggestionAction: string
{
    case Add = 'add';
    case Remove = 'remove';
    case Reweight = 'reweight';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
