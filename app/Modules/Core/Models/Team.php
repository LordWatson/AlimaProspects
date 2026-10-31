<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;

/**
 * A tenant. Users may belong to many teams and switch the active one (ADR 0006).
 *
 * **The team is also the billable party** (Phase 16). Cashier's customer columns are on `teams`
 * and `Cashier::useCustomerModel()` points at this class, so the trait has to live here rather
 * than in the Billing module — Cashier resolves the customer model statically and the trait is
 * what gives it `subscriptions()`, `stripe_id` and the generic trial. The *domain* side of billing
 * (plans, allowances, enforcement) stays in `App\Modules\Billing`; what Core owns is the fact that
 * a tenant, not a user, is who pays. A subscription that followed whoever clicked subscribe would
 * break the moment somebody owned two teams.
 *
 * @property int $id
 * @property string $name
 * @property string $timezone
 * @property string $locale
 * @property string $market
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property Carbon|null $trial_ends_at
 * @property string $billing_mode see Billing\Enums\BillingMode — a plain column here, because
 *                                Core is the foundation Billing builds on and must not import it
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Team extends Model
{
    use Billable;

    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'timezone',
        'locale',
        'market',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'UTC',
        'locale' => 'en',
        'market' => 'uk',
    ];

    /**
     * Members of the team, with their team-scoped role on the pivot.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Whether an operator has suspended this tenant (Phase 19a).
     *
     * Deliberately **not** a login check. A suspended team's members sign in and read their own
     * data as before; what stops is sourcing, sending and spending — enforced in the guards, not in
     * middleware, because a queued discovery job and a scheduled signal watch spend a team's
     * allowance with no request behind them (ADR 0012's argument, unchanged).
     *
     * Not fillable: suspending a tenant goes through `Admin\Actions\SuspendTeamAction`, which
     * records who did it and why. A `$team->update(['suspended_at' => …])` from anywhere else
     * would be a suspension with no reason and no audit entry.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            // Cashier compares this against `now()` to decide whether a team is on its generic
            // (card-less) trial — a string would compare as a string and silently never expire.
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
