<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use Database\Factories\OutreachTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team's reusable brief for a message (Phase 11): the angle they want taken, for one channel,
 * tone and language. Not a mail-merge template — the body is handed to the drafting model as
 * untrusted content, so a team can say "lead with the compliance angle, mention our SLA, never
 * promise a discount" in their own words and have every draft follow it.
 *
 * Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by
 * @property string $name
 * @property OutreachChannel $channel
 * @property OutreachTone $tone
 * @property SupportedLocale $locale
 * @property string|null $subject
 * @property string $body
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OutreachTemplate extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<OutreachTemplateFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'created_by',
        'name',
        'channel',
        'tone',
        'locale',
        'subject',
        'body',
        'is_default',
    ];

    /**
     * Who wrote it. Null once they have left the team — the template stays.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The template offered first when drafting on this channel, when the team has nominated one.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDefaultFor(Builder $query, OutreachChannel $channel): Builder
    {
        return $query->where('channel', $channel->value)->where('is_default', true);
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
            'is_default' => 'boolean',
        ];
    }

    protected static function newFactory(): OutreachTemplateFactory
    {
        return OutreachTemplateFactory::new();
    }
}
