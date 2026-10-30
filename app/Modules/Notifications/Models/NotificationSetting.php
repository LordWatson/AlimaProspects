<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\DigestFrequency;
use Database\Factories\NotificationSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's settings that are about *all* their notifications in one team rather than one type
 * (Phase 12): the digest, and the mute.
 *
 * Separate from {@see NotificationPreference} because they answer different questions and have
 * different cardinality — one row per member per team here, one per member per team per type
 * there. Folding them together would mean either a nullable `type` with a unique index that MySQL
 * can't enforce, or repeating the digest hour on every type's row.
 *
 * @property int $id
 * @property int $user_id
 * @property int $team_id
 * @property DigestFrequency $digest
 * @property int $digest_hour Hour of the day in the **team's** timezone (0–23)
 * @property Carbon|null $digest_sent_at
 * @property Carbon|null $muted_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationSetting extends Model
{
    /** @use HasFactory<NotificationSettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'team_id',
        'digest',
        'digest_hour',
        'digest_sent_at',
        'muted_until',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'digest' => DigestFrequency::Off->value,
        'digest_hour' => 8,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Whether the member has asked for silence right now.
     *
     * A mute suppresses the channels that interrupt — email and push — and leaves the bell alone:
     * "stop buzzing me" is not "hide what happened", and a member coming back from a quiet week
     * still needs to find out what they missed.
     */
    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'digest' => DigestFrequency::class,
            'digest_hour' => 'integer',
            'digest_sent_at' => 'datetime',
            'muted_until' => 'datetime',
        ];
    }

    protected static function newFactory(): NotificationSettingFactory
    {
        return NotificationSettingFactory::new();
    }
}
