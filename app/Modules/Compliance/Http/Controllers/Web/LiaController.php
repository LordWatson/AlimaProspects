<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\SaveLiaRecordAction;
use App\Modules\Compliance\Http\Controllers\Web\Concerns\ResolvesCompliance;
use App\Modules\Compliance\Http\Requests\SaveLiaRecordRequest;
use App\Modules\Compliance\Http\Resources\LiaRecordResource;
use App\Modules\Compliance\Services\LegitimateInterestGate;
use App\Modules\Core\Enums\Market;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The legitimate-interest assessment screen (Inertia), one market at a time.
 *
 * The market is a query parameter rather than a route segment: the team's own market is the default
 * and the overwhelming case, and a URL per regime would imply a team has to think about both.
 *
 * Signing is the same endpoint as saving, with a flag. Two endpoints would mean two places that
 * could disagree about what a complete assessment is.
 */
final class LiaController extends Controller
{
    use ResolvesCompliance;

    public function edit(Request $request, LegitimateInterestGate $gate): Response
    {
        $team = $this->team($request);
        $user = $this->user($request);

        $market = $this->market($request, $gate);
        $record = $this->liaRecord($team, $market);

        $this->authorize('view', $record);

        return Inertia::render('compliance/Lia', [
            'assessment' => LiaRecordResource::make($record)->resolve($request),
            'team_market' => $gate->marketFor($team)->value,
            'options' => [
                'markets' => array_map(static fn (Market $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                ], Market::cases()),
            ],
            // How long a signature lasts, so the screen can say so before it is given rather than
            // after.
            'review_months' => max(1, (int) config('compliance.lia.review_after_months', 12)),
            'can' => ['manage' => $user->can('update', $record)],
        ]);
    }

    public function update(SaveLiaRecordRequest $request, SaveLiaRecordAction $action): RedirectResponse
    {
        $team = $this->team($request);
        $data = $request->liaData();

        $record = $this->liaRecord($team, $data->market);

        $this->authorize('update', $record);

        $saved = $action->handle($team, $this->user($request), $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $saved->isInForce()
                ? __('Assessment signed off. Lead discovery is open.')
                : __('Saved as a draft. Sign it off when you are ready — discovery stays closed until you do.'),
        ]);

        return back();
    }

    public function withdraw(Request $request, SaveLiaRecordAction $action, LegitimateInterestGate $gate): RedirectResponse
    {
        $team = $this->team($request);

        $record = $this->liaRecord($team, $this->market($request, $gate));

        $this->authorize('withdraw', $record);

        if ($record->exists) {
            $action->withdraw($team, $this->user($request), $record);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Assessment withdrawn. Lead discovery is closed for this market.'),
        ]);

        return back();
    }

    /**
     * The market being assessed: the one asked for, or the team's own.
     *
     * An unrecognised value falls back to the team's market rather than 404ing — this is a settings
     * screen reached from a link, and a hand-edited query string should show the team their own
     * assessment, not an error page.
     */
    private function market(Request $request, LegitimateInterestGate $gate): Market
    {
        $requested = $request->query('market');

        return (is_string($requested) ? Market::tryFrom($requested) : null)
            ?? $gate->marketFor($this->team($request));
    }
}
