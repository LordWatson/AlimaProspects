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
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Scoring\Models\LeadScore;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property int|null $pipeline_stage_id
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
 * @property Carbon|null $anonymised_at
 * @property string $dedupe_key
 * @property-read int|null $contacts_count
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
        'pipeline_stage_id',
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
     * ICP-fit scores for this lead — one per ICP it has been graded against (Phase 9).
     *
     * @return HasMany<LeadScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }

    /**
     * The lead's timeline (Phase 10): everything that has happened to it, newest first. Append-only
     * history, so the relation is read in the workspace and written only by the Leads Workspace.
     *
     * @return HasMany<LeadActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('occurred_at');
    }

    /**
     * The board column the lead sits in (Phase 10b). **Null means the team's default stage** — see
     * the `pipeline_stage_id` migration — so a reader that cares about the column a lead appears in
     * must fall back to the default rather than treating null as "nowhere".
     *
     * @return BelongsTo<PipelineStage, $this>
     */
    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class);
    }

    /**
     * The team's labels on this lead (Phase 10b).
     *
     * @return BelongsToMany<LeadTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(LeadTag::class, 'lead_lead_tag')->orderBy('name');
    }

    /**
     * What the team has written about this lead, newest first (Phase 10b).
     *
     * @return HasMany<LeadNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest('id');
    }

    /**
     * Work outstanding on this lead (Phase 10b), soonest due first with undated tasks last.
     *
     * @return HasMany<LeadTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(LeadTask::class)
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->orderBy('id');
    }

    /**
     * Who is working this lead (Phase 10b).
     *
     * @return HasMany<LeadAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class);
    }

    /**
     * Every recorded approach to this company (Phase 11), newest first — the email Alima sent, the
     * LinkedIn message a member pasted, the call they made. Ordered by when the approach *happened*
     * rather than when the row was written, because a member logs yesterday's call today.
     *
     * @return HasMany<ContactAttempt, $this>
     */
    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class)->latest('occurred_at');
    }

    /**
     * Messages drafted for this company (Phase 11), newest first. A draft is not an approach —
     * {@see self::contactAttempts()} is the record of what actually went out.
     *
     * @return HasMany<OutreachMessage, $this>
     */
    public function outreachMessages(): HasMany
    {
        return $this->hasMany(OutreachMessage::class)->latest('id');
    }

    /**
     * The score against the team's **active** ICP — the one a list or badge should show. Eager-load
     * this (rather than all `scores`) wherever only the current grade is needed.
     *
     * @return HasOne<LeadScore, $this>
     */
    public function activeScore(): HasOne
    {
        return $this->hasOne(LeadScore::class)
            ->whereHas('icp', fn (Builder $query): Builder => $query->where('is_active', true));
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
            // Set by the Compliance retention sweep when the lead's personal data was stripped
            // (docs/compliance.md §3.4). Not fillable: nothing but that sweep and the DSAR erasure
            // may claim a lead has been anonymised, and both set it with `forceFill`.
            'anonymised_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }
}
