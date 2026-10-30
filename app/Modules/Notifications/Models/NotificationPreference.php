<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's channel choices for one notification type in one team (Phase 12).
 *
 * **A row means the member has an opinion.** With no row, the type's own
 * {@see NotificationType::defaultChannels()} apply — so adding a type never needs a backfill, and
 * never arrives switched off for everyone who signed up before it existed.
 *
 * Not `BelongsToTeam`: the rows are per **user and** team, read for a member who may belong to
 * several, and the queries here always name both. A global scope would add nothing and would make
 * the preference screen's "my settings in this team" read ambiguous.
 *
 * @property int $id
 * @property int $user_id
 * @property int $team_id
 * @property NotificationType $type
 * @property bool $in_app
 * @property bool $mail
 * @property bool $push
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'team_id',
        'type',
        'in_app',
        'mail',
        'push',
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
     * Whether this row allows the channel. Reads the column the channel names, so a new channel
     * cannot be checked against the wrong one.
     */
    public function allows(NotificationChannel $channel): bool
    {
        return (bool) $this->getAttribute($channel->column());
    }

    /**
     * The channels this row allows, in enum order.
     *
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return array_values(array_filter(
            NotificationChannel::cases(),
            fn (NotificationChannel $channel): bool => $this->allows($channel),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'in_app' => 'boolean',
            'mail' => 'boolean',
            'push' => 'boolean',
        ];
    }

    protected static function newFactory(): NotificationPreferenceFactory
    {
        return NotificationPreferenceFactory::new();
    }
}
