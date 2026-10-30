<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Actions\SendOutreachEmailAction;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\OutreachTone;
use Database\Factories\OutreachMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One drafted message for one {@see Lead} (Phase 11) — what the model wrote, what the member edited
 * it to, and, for email, what happened when Alima sent it.
 *
 * It is the record of what was actually said, so the parts that could drift are copied onto the row
 * rather than read back through a relation: `sent_to` is the address used at the time, not whatever
 * the contact's address is today. Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property int|null $lead_contact_id
 * @property int|null $outreach_template_id
 * @property int|null $created_by
 * @property OutreachChannel $channel
 * @property OutreachTone $tone
 * @property SupportedLocale $locale
 * @property OutreachMessageStatus $status
 * @property string|null $subject
 * @property string $body
 * @property list<array<string, mixed>>|null $variants Decoded JSON — the shape is what the
 *                                                     drafting action wrote, not something the column guarantees, so readers coalesce.
 * @property string|null $sent_to
 * @property string|null $provider_message_id
 * @property string|null $error_message
 * @property Carbon|null $sent_at
 * @property string|null $prompt_key
 * @property int|null $prompt_version
 * @property string|null $model
 * @property int $cost_micros
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OutreachMessage extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<OutreachMessageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'lead_contact_id',
        'outreach_template_id',
        'created_by',
        'channel',
        'tone',
        'locale',
        'status',
        'subject',
        'body',
        'variants',
        'sent_to',
        'provider_message_id',
        'error_message',
        'sent_at',
        'prompt_key',
        'prompt_version',
        'model',
        'cost_micros',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => OutreachMessageStatus::Draft->value,
        'cost_micros' => 0,
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
     * @return BelongsTo<OutreachTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(OutreachTemplate::class, 'outreach_template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The approaches made with this message. Usually one — but a member who sends an email and then
     * calls to follow it up logs two against the same draft.
     *
     * @return HasMany<ContactAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class)->latest('occurred_at');
    }

    /**
     * Whether this draft is no longer available to send — already gone out, or already in flight.
     *
     * A courtesy check, **not** the guarantee: it reads a status loaded into memory a moment
     * earlier, and two simultaneous Send requests would both pass it. The guarantee is the
     * conditional claim in {@see SendOutreachEmailAction::claim()}.
     * This is what the screen disables the button on and what gives the member a readable error.
     */
    public function isSpent(): bool
    {
        return ! $this->status->allowsSend();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'channel' => OutreachChannel::class,
            'tone' => OutreachTone::class,
            'locale' => SupportedLocale::class,
            'status' => OutreachMessageStatus::class,
            'variants' => 'array',
            'sent_at' => 'datetime',
            'prompt_version' => 'integer',
            'cost_micros' => 'integer',
        ];
    }

    protected static function newFactory(): OutreachMessageFactory
    {
        return OutreachMessageFactory::new();
    }
}
