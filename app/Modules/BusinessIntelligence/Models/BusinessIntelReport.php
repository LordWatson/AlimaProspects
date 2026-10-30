<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Models;

use App\Models\User;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Jobs\RunBackgroundCheckJob;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Onboarding\Models\BusinessProfile;
use Database\Factories\BusinessIntelReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A versioned AI background check on the team's own business. Produced by
 * {@see RunBackgroundCheckJob} from the business's public web
 * presence via the `business_intel.background_check` prompt, reviewed by the team, and (on accept)
 * merged into the {@see BusinessProfile}. Tenant scoped via
 * {@see BelongsToTeam}; many rows per team, the highest `version` being the latest.
 *
 * @property int $id
 * @property int $team_id
 * @property int $version
 * @property BackgroundCheckStatus $status
 * @property string|null $business_name
 * @property string|null $website
 * @property array<string, mixed>|null $findings
 * @property string|null $prompt_key
 * @property int|null $prompt_version
 * @property string|null $model
 * @property int $cost_micros
 * @property string|null $error_message
 * @property Carbon|null $completed_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BusinessIntelReport extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<BusinessIntelReportFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'version',
        'status',
        'business_name',
        'website',
        'findings',
        'prompt_key',
        'prompt_version',
        'model',
        'cost_micros',
        'error_message',
        'completed_at',
        'accepted_at',
        'accepted_by',
    ];

    /**
     * Whether this report is finished and awaiting (or past) the team's review.
     */
    public function isReady(): bool
    {
        return $this->status === BackgroundCheckStatus::Ready;
    }

    public function isAccepted(): bool
    {
        return $this->status === BackgroundCheckStatus::Accepted;
    }

    /**
     * The user who accepted this report, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => BackgroundCheckStatus::class,
            'findings' => 'array',
            'version' => 'integer',
            'prompt_version' => 'integer',
            'cost_micros' => 'integer',
            'completed_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BusinessIntelReportFactory
    {
        return BusinessIntelReportFactory::new();
    }
}
