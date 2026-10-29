<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\LeadSourcing\Models\Lead;
use Database\Factories\LeadContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A contact point derived for a {@see Lead} during Enrichment (Phase 8) — in the MVP, from AI
 * extraction of the lead's public web text (no paid provider, ADR 0008). Carries its own source
 * attribution and, for emails, the free-tier verification result. Tenant scoped via
 * {@see BelongsToTeam}. The platform prefers business/role contacts; personal-looking ones are
 * flagged (`is_personal`) for compliance rather than silently used.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property string|null $name
 * @property string|null $title
 * @property string|null $email
 * @property string|null $phone
 * @property EmailVerificationStatus|null $email_verification_status
 * @property int|null $email_confidence
 * @property bool $is_role_based
 * @property bool $is_personal
 * @property string $source
 * @property string|null $source_url
 * @property string|null $method
 * @property Carbon|null $collected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadContact extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadContactFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'name',
        'title',
        'email',
        'phone',
        'email_verification_status',
        'email_confidence',
        'is_role_based',
        'is_personal',
        'source',
        'source_url',
        'method',
        'collected_at',
    ];

    /**
     * The lead this contact belongs to.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verification_status' => EmailVerificationStatus::class,
            'email_confidence' => 'integer',
            'is_role_based' => 'boolean',
            'is_personal' => 'boolean',
            'collected_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadContactFactory
    {
        return LeadContactFactory::new();
    }
}
