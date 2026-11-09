<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Data;

/**
 * A reference to one record in somebody else's CRM (PL7).
 *
 * Two strings, and the pair is the point: an id alone is meaningless across providers that number
 * organisations and persons separately, and `integration_links` stores both for the same reason.
 * `type` is the **provider's own noun** — `organization` at Pipedrive, `company` at HubSpot —
 * because translating them into one vocabulary would mean our word appearing in a URL we build to
 * their record, and getting it wrong silently.
 *
 * A plain readonly value object rather than a `spatie/laravel-data` DTO: nothing validates it and
 * nothing casts it from a request. It only ever travels between a connector and the Action that
 * called it.
 */
final readonly class CrmRecord
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}
}
