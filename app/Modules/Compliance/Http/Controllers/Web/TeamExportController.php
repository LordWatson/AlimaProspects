<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\ExportTeamDataAction;
use App\Modules\Compliance\Http\Controllers\Web\Concerns\ResolvesCompliance;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Hands a team its own data as a ZIP (docs/compliance.md §3.6).
 *
 * A **POST**, not a GET, although it only reads: it is metered, audited and expensive, and a GET
 * would be prefetched by a browser, warmed by a link checker and cached by anything in between —
 * each of which is an unasked-for copy of a tenant's whole prospect database.
 *
 * Rate-limited per member by the `compliance-export` limiter (see the module provider): the export
 * reads every table the team owns, which makes it the heaviest thing a member can ask for.
 */
final class TeamExportController extends Controller
{
    use ResolvesCompliance;

    public function store(Request $request, ExportTeamDataAction $action): BinaryFileResponse
    {
        $team = $this->team($request);

        $this->authorize('compliance.export', $team);

        return $action->handle($team, $this->user($request));
    }
}
