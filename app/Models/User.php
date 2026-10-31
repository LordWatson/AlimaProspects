<?php

namespace App\Models;

use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Core\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $current_team_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property AdminRole|null $admin_role
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable {
        // Our team membership relation (team_user pivot) is authoritative; spatie's
        // convenience teams() relation (over model_has_roles) is aliased out of the way.
        HasTeams::teams insteadof HasRoles;
        HasRoles::teams as permissionTeams;
    }

    /**
     * Whether this account is a platform operator (Phase 19a, ADR 0014).
     *
     * One column on `users`, not a second guard over a second table: an operator is a person with
     * one credential, one 2FA secret and one audit trail. Separation from the tenant app is a
     * middleware stack and a route prefix — see `Admin\Http\Middleware\EnsurePlatformAdmin`.
     *
     * `admin_role` is **not fillable**, so no mass assignment anywhere in the product can grant it;
     * granting it is a deliberate `forceFill` in the seeder or a `super_admin`'s own action.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->adminRole() !== null;
    }

    /**
     * Strict-safe read of the platform role: null when the attribute is not hydrated, rather than
     * throwing under `shouldBeStrict()` — the same reason {@see HasTeams::currentTeamId()} exists.
     */
    public function adminRole(): ?AdminRole
    {
        $value = $this->getAttributes()['admin_role'] ?? null;

        if ($value instanceof AdminRole) {
            return $value;
        }

        return is_string($value) ? AdminRole::tryFrom($value) : null;
    }

    /**
     * Whether this operator's role carries an ability. False for every customer, which is the
     * answer the admin gates need first.
     */
    public function hasAdminAbility(AdminAbility $ability): bool
    {
        return $this->adminRole()?->can($ability) ?? false;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'admin_role' => AdminRole::class,
        ];
    }
}
