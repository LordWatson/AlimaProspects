<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

enum LeadSortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public function flip(): self
    {
        return $this === self::Asc ? self::Desc : self::Asc;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $direction): string => $direction->value, self::cases());
    }
}
