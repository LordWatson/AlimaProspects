<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string $company_name
 * @property string|null $company_number
 * @property string|null $company_status
 * @property string|null $company_type
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
 * @property array<string, mixed>|null $raw
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
        'company_name',
        'company_number',
        'company_status',
        'company_type',
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
        'raw',
        'dedupe_key',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LeadStatus::Discovered->value,
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
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'status' => LeadStatus::class,
            'incorporated_on' => 'date',
            'address' => 'array',
            'sic_codes' => 'array',
            'collected_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }
}
