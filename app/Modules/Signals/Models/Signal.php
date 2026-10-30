<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Currency;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use Database\Factories\SignalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single wealth/liquidity event kept from a {@see SignalRun} (ADR 0011): an AI-classified,
 * AI-summarised news item about a person or company. Holds a person's name and public role only —
 * never email or phone (data minimisation). Unique per team on `url_hash`. On conversion (S4) it
 * points one-way at the {@see Lead} it created. Tenant-scoped via {@see BelongsToTeam}.
 *
 * `signal_watch_id` and `signal_run_id` are **nullable**: a converted signal is the provenance of
 * a real lead, so deleting the watch that found it nulls the attribution rather than destroying
 * the record (see the S6 migration and `DeleteSignalWatchAction`).
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $signal_watch_id
 * @property int|null $signal_run_id
 * @property SignalEventType $event_type
 * @property SignalStatus $status
 * @property SignalDismissReason|null $dismiss_reason
 * @property string $headline
 * @property string $summary
 * @property string|null $subject_person_name
 * @property string|null $subject_person_role
 * @property string|null $subject_company_name
 * @property string|null $counterparty_name
 * @property int|null $amount_minor
 * @property Currency|null $currency
 * @property string|null $location
 * @property int $confidence
 * @property int $relevance
 * @property string|null $suggested_approach
 * @property string $source_url
 * @property string|null $source_name
 * @property Carbon|null $published_at
 * @property string $url_hash
 * @property array<string, mixed> $raw
 * @property int|null $lead_id
 * @property int|null $actioned_by
 * @property Carbon|null $actioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Signal extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'signal_watch_id',
        'signal_run_id',
        'event_type',
        'status',
        'dismiss_reason',
        'headline',
        'summary',
        'subject_person_name',
        'subject_person_role',
        'subject_company_name',
        'counterparty_name',
        'amount_minor',
        'currency',
        'location',
        'confidence',
        'relevance',
        'suggested_approach',
        'source_url',
        'source_name',
        'published_at',
        'url_hash',
        'raw',
        'lead_id',
        'actioned_by',
        'actioned_at',
    ];

    /**
     * The watch that produced this signal.
     *
     * @return BelongsTo<SignalWatch, $this>
     */
    public function watch(): BelongsTo
    {
        return $this->belongsTo(SignalWatch::class, 'signal_watch_id');
    }

    /**
     * The run that produced this signal.
     *
     * @return BelongsTo<SignalRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SignalRun::class, 'signal_run_id');
    }

    /**
     * The lead this signal was converted into (S4), if any. One-way: Lead Sourcing never depends on
     * Signals.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The member who dismissed or converted this signal.
     *
     * @return BelongsTo<User, $this>
     */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'event_type' => SignalEventType::class,
            'status' => SignalStatus::class,
            'dismiss_reason' => SignalDismissReason::class,
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'confidence' => 'integer',
            'relevance' => 'integer',
            'published_at' => 'datetime',
            'raw' => 'array',
            'actioned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalFactory
    {
        return SignalFactory::new();
    }
}
