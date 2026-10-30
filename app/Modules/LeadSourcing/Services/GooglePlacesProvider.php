<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use Illuminate\Support\Str;

/**
 * Discovers local and small businesses from the Google Places API (New) Text Search (ADR 0008) —
 * strong for the UAE, where there is no free company registry. Maps each place into a
 * {@see SourcedLead} (name, website, address, Google Maps record URL). Hardening is inherited from
 * {@see AbstractHttpLeadSourceProvider}. Config: `config/lead-sourcing.php`.
 */
final class GooglePlacesProvider extends AbstractHttpLeadSourceProvider
{
    private const string FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,places.websiteUri,places.googleMapsUri,places.types,places.addressComponents';

    public function source(): LeadSource
    {
        return LeadSource::GooglePlaces;
    }

    public function discover(DiscoveryQuery $query): array
    {
        $textQuery = $this->textQuery($query);
        if ($textQuery === '') {
            return [];
        }

        $apiKey = $this->requireApiKey('lead-sourcing.providers.google_places.api_key');
        $baseUrl = rtrim((string) config('lead-sourcing.providers.google_places.base_url'), '/');
        // Places Text Search (New) returns at most 20 results per request.
        $max = max(1, min($query->limit, 20));

        $response = $this->guarded(fn () => $this->http
            ->baseUrl($baseUrl)
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => self::FIELD_MASK,
            ])
            ->timeout((int) config('lead-sourcing.providers.google_places.timeout', 10))
            ->connectTimeout((int) config('lead-sourcing.providers.google_places.connect_timeout', 5))
            ->retry(
                (int) config('lead-sourcing.providers.google_places.retry_times', 2),
                (int) config('lead-sourcing.providers.google_places.retry_sleep_ms', 250),
                $this->retryWhen(),
                throw: false,
            )
            ->acceptJson()
            ->post('/v1/places:searchText', [
                'textQuery' => $textQuery,
                'maxResultCount' => $max,
            ]));

        /** @var array<array-key, mixed> $places */
        $places = (array) $response->json('places', []);

        $leads = [];
        foreach ($places as $place) {
            if (! is_array($place)) {
                continue;
            }

            $lead = $this->mapPlace($place);
            if ($lead instanceof SourcedLead) {
                $leads[] = $lead;
            }

            if (count($leads) >= $query->limit) {
                break;
            }
        }

        return $leads;
    }

    public function costMicros(int $leadsReturned): int
    {
        // Text Search is billed per request (a run makes one), not per result; a no-op run (empty
        // terms → no request) shows no cost.
        if ($leadsReturned === 0) {
            return 0;
        }

        return (int) config('lead-sourcing.providers.google_places.cost_micros_per_request', 32_000);
    }

    /**
     * Compose the Places text query from the ICP-shaped terms and (optional) location — "<terms> in
     * <location>" biases Places toward that area, which is how UAE discovery is targeted.
     */
    private function textQuery(DiscoveryQuery $query): string
    {
        $terms = trim($query->terms);
        $location = trim((string) $query->location);

        if ($terms === '') {
            return '';
        }

        return $location !== '' ? "{$terms} in {$location}" : $terms;
    }

    /**
     * Map one Places result into a {@see SourcedLead}. Returns null when the place has no usable name.
     *
     * @param  array<array-key, mixed>  $place
     */
    private function mapPlace(array $place): ?SourcedLead
    {
        $name = '';
        if (is_array($place['displayName'] ?? null)) {
            $name = trim((string) ($place['displayName']['text'] ?? ''));
        }

        if ($name === '') {
            return null;
        }

        /** @var array<array-key, mixed> $components */
        $components = is_array($place['addressComponents'] ?? null) ? $place['addressComponents'] : [];

        $placeId = isset($place['id']) ? (string) $place['id'] : null;
        $mapsUrl = isset($place['googleMapsUri']) && is_string($place['googleMapsUri'])
            ? $place['googleMapsUri']
            : ($placeId !== null ? ((string) config('lead-sourcing.providers.google_places.maps_url')).$placeId : null);

        return new SourcedLead(
            source: LeadSource::GooglePlaces,
            companyName: $name,
            companyNumber: null,
            companyStatus: null,
            companyType: $this->firstType($place),
            incorporatedOn: null,
            website: is_string($place['websiteUri'] ?? null) ? $place['websiteUri'] : null,
            locality: $this->component($components, 'locality') ?? $this->component($components, 'postal_town'),
            region: $this->component($components, 'administrative_area_level_1'),
            postalCode: $this->component($components, 'postal_code'),
            country: $this->component($components, 'country', short: true),
            address: isset($place['formattedAddress']) && is_string($place['formattedAddress']) ? ['formatted' => $place['formattedAddress']] : [],
            sicCodes: [],
            sourceUrl: $mapsUrl,
            raw: $place,
            // The place id dedupes website-less places that a shared name would otherwise merge.
            externalId: $placeId,
        );
    }

    /**
     * The first place "type" (e.g. `restaurant`), used loosely as a company type.
     *
     * @param  array<array-key, mixed>  $place
     */
    private function firstType(array $place): ?string
    {
        $types = $place['types'] ?? null;

        if (is_array($types) && isset($types[0]) && is_string($types[0])) {
            return $types[0];
        }

        return null;
    }

    /**
     * Pull one address component's long (or short) text by its Google type.
     *
     * @param  array<array-key, mixed>  $components
     */
    private function component(array $components, string $type, bool $short = false): ?string
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $types = $component['types'] ?? [];
            if (is_array($types) && in_array($type, $types, strict: true)) {
                $value = $short ? ($component['shortText'] ?? null) : ($component['longText'] ?? null);

                if (is_string($value) && trim($value) !== '') {
                    return $short ? Str::upper(Str::substr(trim($value), 0, 2)) : trim($value);
                }
            }
        }

        return null;
    }
}
