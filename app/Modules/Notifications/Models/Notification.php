<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Notifications\TeamNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * One stored notification (Phase 12). Extends Laravel's {@see DatabaseNotification} so the
 * framework's `database` channel writes it, and adds the two things the product needs: the **team**
 * it belongs to, and the {@see NotificationType} read back off `data`.
 *
 * **Deliberately not `BelongsToTeam`.** The rows are written by Laravel's own channel, which does
 * not run inside our team context, so the trait's auto-assignment would never fire and its global
 * scope would then hide every row it had failed to stamp. The team is set explicitly by
 * {@see TeamNotification::databaseType()}'s sibling hook,
 * and every read goes through {@see self::scopeForTeam()} — which is also what the policy checks.
 *
 * `data` holds **structured values, never a formed sentence**: the app is EN/AR (ADR 0007), so the
 * wording is composed at render time from the type plus its params — the same rule
 * `lead_activities.properties` follows.
 *
 * @property string $id
 * @property int|null $team_id
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 */
class Notification extends DatabaseNotification
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'team_id',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The product's notification type, from the payload.
     *
     * Null for a notification raised outside this module — a Laravel password reset, say — which
     * the bell therefore skips rather than tries to render.
     */
    public function notificationType(): ?NotificationType
    {
        $type = $this->data['type'] ?? null;

        return is_string($type) ? NotificationType::tryFrom($type) : null;
    }

    /**
     * This team's notifications. The explicit scope that stands in for a tenant global scope here
     * (see the class note), so no read can forget it by omission.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForTeam(Builder $query, Team|int $team): Builder
    {
        return $query->where('team_id', $team instanceof Team ? $team->getKey() : $team);
    }

    /**
     * **The ownership condition, in one place.** This model deliberately has no tenant global
     * scope, so these predicates *are* the enforcement rather than a convenience on top of one —
     * which is exactly why they must not be copy-pasted into every reader and writer. A query that
     * omitted one would fail open.
     *
     * The `alima.%` filter excludes notifications raised elsewhere in the framework (a password
     * reset): those have no team and nothing the bell could render.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOwnedBy(Builder $query, User $user, Team|int $team): Builder
    {
        return $query
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->forTeam($team)
            ->where('type', 'like', 'alima.%');
    }

    /**
     * The params the screen interpolates into the type's sentence.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        $params = $this->data['params'] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Where clicking it should go, when there is somewhere.
     */
    public function url(): ?string
    {
        $url = $this->data['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
