<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Actions\UpdatePlatformSettingsAction;
use App\Modules\Admin\Http\Requests\UpdateSettingsRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform switches (Phase 19b).
 *
 * A bespoke screen rather than a registry resource, and for the registry's own stated reason: a
 * setting is not a row to edit, it is a typed value with a declared default that has to be cast
 * back out of a JSON column and audited with what it replaced. The descriptor would have needed
 * logic, and `AdminResource`'s rule is that descriptors return data.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly PlatformSettings $settings,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('admin/settings/Index', [
            'navigation' => $this->registry->navigation(),
            'settings' => $this->settings->describeAll(),
        ]);
    }

    public function update(
        UpdateSettingsRequest $request,
        UpdatePlatformSettingsAction $action,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        // One Action, one transaction. Each key is still audited on its own inside it — a single
        // "settings updated" entry would record that something changed and not what — but the page
        // either applies or it does not, rather than leaving whichever half came first in the
        // config file in force.
        $applied = $action->handle($request->changes(), $operator);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $applied === []
                ? 'Nothing to change — those are already the values in force.'
                : count($applied).' '.(count($applied) === 1 ? 'setting' : 'settings').' saved.',
        ]);

        return to_route('admin.settings.edit');
    }
}
