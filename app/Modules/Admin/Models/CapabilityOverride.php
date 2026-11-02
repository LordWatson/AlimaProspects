<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Capability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One tenant's exception to what their plan includes (Phase 19b, ADR 0015).
 *
 * A row rather than an edit to a plan: letting a pilot customer have Signals on Starter by editing
 * the Starter plan would give it to every Starter customer, and doing it with a hand-written `if`
 * would give it to them with no reason and no end date. Here both travel with the exception.
 *
 * `enabled` works in **both** directions, which is why it is a boolean rather than the row's mere
 * existence: on, for a pilot or a goodwill gesture; off, for a tenant who must not have something
 * their plan includes — a trial that should not be able to send, or a customer whose own compliance
 * team asked us to switch a feature off.
 *
 * @property int $id
 * @property int $team_id
 * @property Capability $capability
 * @property bool $enabled
 * @property string $reason
 * @property Carbon|null $expires_at
 * @property int|null $set_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CapabilityOverride extends Model
{
    use BelongsToTeam;

    /**
     * Named `team_capability_overrides` rather than letting Eloquent infer it. The prefix is deliberate: it
     * says what the row is scoped to, and these sit beside other platform-level tables where
     * an unprefixed name would read as ours rather than a tenant's.
     */
    protected $table = 'team_capability_overrides';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'capability',
        'enabled',
        'reason',
        'expires_at',
        'set_by',
    ];

    /**
     * Whether this override still applies. An expired one is dead rather than deleted, so the
     * record of what a tenant was given and why survives the giving.
     */
    public function isActive(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now('UTC');

        return $this->expires_at === null || $this->expires_at->isAfter($at);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInForce(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now('UTC');

        return $query->where(static fn (Builder $nested): Builder => $nested
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', $at));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capability' => Capability::class,
            'enabled' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}
