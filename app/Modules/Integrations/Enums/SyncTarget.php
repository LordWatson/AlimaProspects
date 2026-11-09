<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

/**
 * Which of our rows a link or a log entry is about (PL7).
 *
 * Two cases, because the external side has many names for the same two things — Pipedrive calls
 * them organizations and persons, HubSpot companies and contacts — and `integration_links.external_type`
 * is a plain string for exactly that reason. This is our half of the pair, and ours is closed.
 */
enum SyncTarget: string
{
    case Lead = 'lead';
    case Contact = 'contact';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Company',
            self::Contact => 'Contact',
        };
    }
}
