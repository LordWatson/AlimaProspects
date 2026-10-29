<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Middleware\SetLocale;
use App\Modules\Core\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * Switches the viewer's UI language. Stores the choice in a long-lived cookie
 * (a per-user preference that overrides the team default) and returns the user
 * to where they were — {@see SetLocale} applies it on the next request. No
 * authorisation gate: language is a guest-accessible preference.
 */
final class LocaleController extends Controller
{
    /** Cookie lifetime: one year (minutes), so the preference survives sessions. */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    public function __invoke(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = (string) $request->validated('locale');

        Cookie::queue(Cookie::make('locale', $locale, self::COOKIE_MINUTES));

        return back();
    }
}
