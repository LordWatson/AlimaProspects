<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Enrichment\Casts\SourcedValueMap;
use App\Modules\Enrichment\Data\SourcedValue;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A raw, company-level lead discovered from an external provider (Phase 7). Owned by the
 * {@see DiscoveryRun} that found it and carries source attribution on the record. Enrichment
 * (Phase 8) adds contacts + per-field provenance; Scoring (Phase 9) grades it. Tenant scoped via
 * {@see BelongsToTeam}. No personal contact data is stored at discovery — company facts only.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $discovery_run_id
 * @property LeadSource $source
 * @property LeadStatus $status
 * @property EnrichmentStatus $enrichment_status
 * @property string $company_name
 * @property string|null $company_number
 * @property string|null $company_status
 * @property string|null $company_type
 * @property string|null $industry
 * @property string|null $employee_range
 * @property list<string>|null $technologies
 * @property Carbon|null $incorporated_on
 * @property string|null $website
 * @property string|null $locality
 * @property string|null $region
 * @property string|null $postal_code
 * @property string|null $country
 * @property array<string, mixed>|null $address
 * @property list<string>|null $sic_codes
 * @property string|null $source_url
 * @property Carbon|null $collected_at
 * @property Carbon|null $enriched_at
 * @property int $enrichment_cost_micros
 * @property array<string, mixed>|null $raw
 * @property array<string, SourcedValue> $field_sources
 * @property bool $has_personal_data
 * @property string $dedupe_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Lead extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'discovery_run_id',
        'source',
        'status',
        'enrichment_status',
        'company_name',
        'company_number',
        'company_status',
        'company_type',
        'industry',
        'employee_range',
        'technologies',
        'incorporated_on',
        'website',
        'locality',
        'region',
        'postal_code',
        'country',
        'address',
        'sic_codes',
        'source_url',
        'collected_at',
        'enriched_at',
        'enrichment_cost_micros',
        'raw',
        'field_sources',
        'has_personal_data',
        'dedupe_key',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LeadStatus::Discovered->value,
        'enrichment_status' => EnrichmentStatus::Pending->value,
    ];

    /**
     * The run that discovered this lead.
     *
     * @return BelongsTo<DiscoveryRun, $this>
     */
    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class);
    }

    /**
     * Contacts derived for this lead during enrichment.
     *
     * @return HasMany<LeadContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(LeadContact::class);
    }

    /**
     * The bare registrable domain of the lead's website, lower-cased and without a leading `www.`,
     * or null. Used by enrichment de-duplication to match the same company across providers.
     */
    public function domain(): ?string
    {
        $website = trim((string) $this->website);
        if ($website === '') {
            return null;
        }

        $host = parse_url(
            Str::startsWith($website, ['http://', 'https://']) ? $website : 'https://'.$website,
            PHP_URL_HOST,
        );

        if (! is_string($host) || $host === '') {
            return null;
        }

        return Str::lower(Str::replaceStart('www.', '', $host));
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'status' => LeadStatus::class,
            'enrichment_status' => EnrichmentStatus::class,
            'incorporated_on' => 'date',
            'address' => 'array',
            'sic_codes' => 'array',
            'technologies' => 'array',
            'collected_at' => 'datetime',
            'enriched_at' => 'datetime',
            'enrichment_cost_micros' => 'integer',
            'raw' => 'array',
            'field_sources' => SourcedValueMap::class,
            'has_personal_data' => 'boolean',
        ];
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }
}
