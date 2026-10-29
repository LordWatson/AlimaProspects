<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Actions\StartDiscoveryRunAction;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use Database\Factories\DiscoveryRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One lead-discovery run against a single provider (Phase 7). Created {@see DiscoveryRunStatus::Pending}
 * by {@see StartDiscoveryRunAction}, worked by
 * {@see DiscoverLeadsJob} (which broadcasts progress and writes the {@see Lead} rows it yields), and
 * settled Completed/Failed. Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $icp_id
 * @property LeadSource $source
 * @property DiscoveryRunStatus $status
 * @property array<string, mixed>|null $params
 * @property int $requested_limit
 * @property int $leads_found
 * @property int $leads_created
 * @property int $cost_micros
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DiscoveryRun extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<DiscoveryRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'icp_id',
        'source',
        'status',
        'params',
        'requested_limit',
        'leads_found',
        'leads_created',
        'cost_micros',
        'error_message',
        'started_at',
        'completed_at',
        'created_by',
    ];

    /**
     * The ICP that shaped this run, if any.
     *
     * @return BelongsTo<Icp, $this>
     */
    public function icp(): BelongsTo
    {
        return $this->belongsTo(Icp::class);
    }

    /**
     * The member who started the run.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The leads this run discovered.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'status' => DiscoveryRunStatus::class,
            'params' => 'array',
            'requested_limit' => 'integer',
            'leads_found' => 'integer',
            'leads_created' => 'integer',
            'cost_micros' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DiscoveryRunFactory
    {
        return DiscoveryRunFactory::new();
    }
}
