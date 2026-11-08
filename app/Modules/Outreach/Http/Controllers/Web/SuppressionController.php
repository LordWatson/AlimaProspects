<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Actions\ImportSuppressionsAction;
use App\Modules\Outreach\Actions\SuppressIdentityAction;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Http\Controllers\Concerns\ResolvesOutreach;
use App\Modules\Outreach\Http\Requests\ImportSuppressionsRequest;
use App\Modules\Outreach\Http\Requests\SuppressIdentityRequest;
use App\Modules\Outreach\Http\Resources\OutreachSuppressionResource;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The do-not-contact list (Phase 11, docs/compliance.md §3.2).
 *
 * The screen shows the team's own entries **and** every global one, because a team puzzled about a
 * refused send needs to be able to see that the answer is "they opted out". All any of them
 * discloses is the masked preview.
 */
final class SuppressionController extends Controller
{
    use ResolvesOutreach;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function index(Request $request, SuppressionList $list): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $transient = $this->transient(OutreachSuppression::class, $team, ['scope' => SuppressionScope::Team->value]);

        return Inertia::render('outreach/Suppressions', [
            'entries' => OutreachSuppressionResource::collection(
                $list->forTeam($team)->load('team'),
            )->resolve($request),
            'options' => [
                'types' => array_map(static fn (SuppressionType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ], SuppressionType::cases()),
            ],
            'can' => [
                'manage' => $user->can('create', $transient),
            ],
        ]);
    }

    public function store(SuppressIdentityRequest $request, SuppressIdentityAction $suppress): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transient(
            OutreachSuppression::class,
            $team,
            ['scope' => SuppressionScope::Team->value],
        ));

        $this->teamContext->run($team, fn () => $suppress->handle(
            $team,
            $user,
            $request->type(),
            $request->value(),
            $request->note(),
        ));

        return back()->with('toast', ['type' => 'success', 'message' => __('Added to your do-not-contact list.')]);
    }

    /**
     * The bulk load (PL5). Same authorisation and same scope as adding one by hand — it is the same
     * act, repeated — so there is no second permission to get wrong.
     */
    public function import(ImportSuppressionsRequest $request, ImportSuppressionsAction $import): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transient(
            OutreachSuppression::class,
            $team,
            ['scope' => SuppressionScope::Team->value],
        ));

        $result = $this->teamContext->run($team, fn (): array => $import->handle(
            $team,
            $user,
            $request->type(),
            $request->values(),
            $request->note(),
        ));

        // The counts are the message. An import that quietly added 1,412 of 5,000 and said
        // "imported" would leave a team believing a list is covered when most of it is not.
        return back()->with('toast', [
            'type' => $result['added'] > 0 ? 'success' : 'info',
            'message' => $result['truncated']
                ? __(':added added, :duplicate already there, :skipped unusable. The file was longer than one import reads — upload the rest.', $result)
                : __(':added added, :duplicate already there, :skipped unusable.', $result),
        ]);
    }

    public function destroy(Request $request, int $entry, SuppressIdentityAction $suppress): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->suppression($request, $entry);

        $this->authorize('delete', $model);

        $this->teamContext->run($team, fn () => $suppress->lift($model, $user));

        return back();
    }
}
