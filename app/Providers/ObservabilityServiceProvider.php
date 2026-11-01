<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAbility;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Http\Middleware\Authorize;

/**
 * Who may open the operator dashboards (Phase 20, ADR 0017).
 *
 * One place for both gates, because both answer the same question and both shipped with the same
 * published default: `in_array(optional($user)->email, [])`. That denied everybody, so nothing was
 * *broken* — it was a hard-coded email list waiting for somebody to fill it in by hand, on the two
 * screens that show every tenant's slow queries, every tenant's exceptions and the shape of every
 * tenant's queue.
 *
 * The gate is the **last** of four controls, not the only one: `config/pulse.php` puts the admin
 * panel's own door in front of it — platform admin or **404**, enforced two-factor, and the
 * optional IP allowlist — because `/pulse` is not a lesser surface than `/admin`.
 *
 * The ability is `operations.manage`, the 2am incident one, rather than `platform.access`: reading
 * the queue is what the person on support does, and it is the same ability that already lets them
 * retry a failed job in the panel. Finance has no use for this screen.
 *
 * Horizon cannot run on Windows (it needs `ext-pcntl`), so locally there is nothing behind the
 * second gate — but a gate that is correct only because the package cannot start is not a control.
 * Phase 21 is where Horizon actually runs.
 *
 * @see Authorize The Pulse middleware that consumes `viewPulse`.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A closure per name rather than one shared callable, so a future divergence (Horizon's
        // "pause the queue" is a bigger power than Pulse's read-only screens) is a one-line change
        // here instead of an untangling.
        foreach (['viewPulse', 'viewHorizon'] as $gate) {
            Gate::define($gate, static fn (?User $user): bool => $user instanceof User
                && $user->isPlatformAdmin()
                && $user->hasAdminAbility(AdminAbility::ManageOperations));
        }
    }
}
