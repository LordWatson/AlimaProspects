<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use Database\Factories\ContactAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded approach to a lead (Phase 11) — the email Alima sent, the LinkedIn message the
 * member pasted, the call they made. `occurred_at` is when the approach happened and not when the
 * row was written, because a member logs yesterday's call today.
 *
 * Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property int|null $lead_contact_id
 * @property int|null $outreach_message_id
 * @property int|null $created_by
 * @property OutreachChannel $channel
 * @property ContactOutcome $outcome
 * @property Carbon $occurred_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContactAttempt extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<ContactAttemptFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'lead_contact_id',
        'outreach_message_id',
        'created_by',
        'channel',
        'outcome',
        'occurred_at',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'outcome' => ContactOutcome::Pending->value,
    ];

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<LeadContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(LeadContact::class, 'lead_contact_id');
    }

    /**
     * The draft it was made with, when there was one. A call logged straight off the lead screen
     * has none.
     *
     * @return BelongsTo<OutreachMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(OutreachMessage::class, 'outreach_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'channel' => OutreachChannel::class,
            'outcome' => ContactOutcome::class,
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ContactAttemptFactory
    {
        return ContactAttemptFactory::new();
    }
}
