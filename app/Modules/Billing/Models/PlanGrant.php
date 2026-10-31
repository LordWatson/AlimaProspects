<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A plan an operator granted, rather than one Stripe is charging for (Phase 19b).
 *
 * Read by `PlanResolver` **above** the Stripe subscription, which is what makes "turn Stripe off"
 * and "take somebody's plan away" two different acts.
 *
 * The three nullable allowance columns are the Enterprise answer: `config/billing.php` gives that
 * plan `null` seats, leads and credits, which `PlanData` reads as *unlimited* — but a signed
 * contract says 25 seats and 40,000 leads. Null here means **use the plan's own number**, not
 * unlimited, and conflating those two would quietly uncap a contract.
 *
 * @property int $id
 * @property int $team_id
 * @property PlanKey $plan
 * @property int|null $seats
 * @property int|null $leads
 * @property int|null $credits
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string $note
 * @property int|null $granted_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlanGrant extends Model
{
    use BelongsToTeam;

    /**
     * Named `team_plan_grants` rather than letting Eloquent infer it. The prefix is deliberate: it
     * says what the row is scoped to, and these sit beside other platform-level tables where
     * an unprefixed name would read as ours rather than a tenant's.
     */
    protected $table = 'team_plan_grants';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'plan',
        'seats',
        'leads',
        'credits',
        'starts_at',
        'ends_at',
        'note',
        'granted_by',
    ];

    /**
     * Whether this grant is the one in force right now.
     *
     * Three conditions, and the window is the one that matters: a goodwill upgrade with an
     * `ends_at` should stop being an upgrade on its own, rather than becoming permanent because
     * nobody remembered to revoke it.
     */
    public function isActive(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now('UTC');

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->starts_at->isAfter($at)) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isAfter($at);
    }

    /**
     * The grants in force for a team at a moment, newest window first.
     *
     * Written as one scope rather than copied into the resolver and the admin screen, because the
     * definition of "in force" is three conditions and two copies of it would disagree the first
     * time one of them changed.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInForce(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now('UTC');

        return $query
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where(static fn (Builder $nested): Builder => $nested
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', $at))
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => PlanKey::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
