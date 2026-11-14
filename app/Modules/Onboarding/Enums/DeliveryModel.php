<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Enums;

/**
 * How far a business can serve a customer. It constrains geography more than any typed location
 * does: an electrician who must turn up cannot use a lead two hundred miles away, and a software
 * company can.
 */
enum DeliveryModel: string
{
    case Local = 'local';
    case Regional = 'regional';
    case National = 'national';
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'In person, close to where we are based',
            self::Regional => 'In person, across our region',
            self::National => 'Anywhere in the country',
            self::Remote => 'Remotely, wherever the customer is',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
