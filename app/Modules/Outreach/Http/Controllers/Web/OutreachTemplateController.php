<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Actions\DeleteOutreachTemplateAction;
use App\Modules\Outreach\Actions\SaveOutreachTemplateAction;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use App\Modules\Outreach\Http\Controllers\Concerns\ResolvesOutreach;
use App\Modules\Outreach\Http\Requests\SaveOutreachTemplateRequest;
use App\Modules\Outreach\Http\Resources\OutreachTemplateResource;
use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A team's message briefs (Phase 11) — the angles they want their outreach to take, which the
 * drafting model is briefed with.
 */
final class OutreachTemplateController extends Controller
{
    use ResolvesOutreach;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $transient = $this->transient(OutreachTemplate::class, $team);

        return $this->teamContext->run($team, function () use ($request, $user, $transient): Response {
            return Inertia::render('outreach/Templates', [
                'templates' => OutreachTemplateResource::collection(
                    OutreachTemplate::query()->with('team')->orderBy('channel')->orderBy('name')->get(),
                )->resolve($request),
                'options' => [
                    'channels' => array_map(static fn (OutreachChannel $channel): array => [
                        'value' => $channel->value,
                        'label' => $channel->label(),
                        'has_subject' => $channel->hasSubject(),
                    ], OutreachChannel::cases()),
                    'tones' => array_map(static fn (OutreachTone $tone): array => [
                        'value' => $tone->value,
                        'label' => $tone->label(),
                        'description' => $tone->description(),
                    ], OutreachTone::cases()),
                    'locales' => array_map(static fn (SupportedLocale $locale): array => [
                        'value' => $locale->value,
                        'label' => $locale->native(),
                    ], SupportedLocale::cases()),
                ],
                'can' => [
                    'manage' => $user->can('create', $transient),
                ],
            ]);
        });
    }

    public function store(SaveOutreachTemplateRequest $request, SaveOutreachTemplateAction $save): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transient(OutreachTemplate::class, $team));

        $this->teamContext->run($team, fn () => $save->handle($team, $user, $request->templateData()));

        return back();
    }

    public function update(
        SaveOutreachTemplateRequest $request,
        int $template,
        SaveOutreachTemplateAction $save,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->template($request, $template);

        $this->authorize('update', $model);

        $this->teamContext->run($team, fn () => $save->handle($team, $user, $request->templateData(), $model));

        return back();
    }

    public function destroy(Request $request, int $template, DeleteOutreachTemplateAction $delete): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->template($request, $template);

        $this->authorize('delete', $model);

        $this->teamContext->run($team, fn () => $delete->handle($model, $user));

        return back();
    }
}
