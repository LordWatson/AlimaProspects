<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Services\Impersonator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Start and stop impersonating a customer (Phase 19a).
 *
 * The two halves sit in different places on purpose, and it is the asymmetry that makes the feature
 * safe:
 *
 * - **Starting** is an admin route: behind the whole stack, `admin.can:impersonate`, and only for
 *   the roles that need it (`super_admin` and `support`; never `finance`, never `read_only`).
 * - **Stopping** is *not*. By the time an operator wants out, `Auth::user()` is the customer — an
 *   account with no admin role — so an admin-gated stop route would 404 the only way back and leave
 *   them stuck inside somebody else's session until the TTL expired. The route is therefore on the
 *   ordinary `web` stack and authorised by the session itself: it works only if the session is
 *   actually impersonating, which {@see Impersonator::stop()} checks.
 */
final class ImpersonationController extends Controller
{
    public function __construct(private readonly Impersonator $impersonator) {}

    public function start(Request $request, User $user, Impersonator $impersonator): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        // Throws on self, and on another operator — a refusal rendered as a toast by the central
        // handler rather than a bare 403, because both are mistakes rather than attacks.
        $impersonator->start($operator, $user);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'You are now signed in as '.$user->name.'. Sending, billing and credentials are blocked.',
        ]);

        return redirect()->route('dashboard');
    }

    public function stop(): RedirectResponse
    {
        $operator = $this->impersonator->stop();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Welcome back, '.$operator->name.'.']);

        return redirect()->route('admin.dashboard');
    }
}
