<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Http\Resources\LeadContactResource;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\Outreach\Actions\DraftMessageAction;
use App\Modules\Outreach\Actions\LogContactAttemptAction;
use App\Modules\Outreach\Actions\SendOutreachEmailAction;
use App\Modules\Outreach\Contracts\LeadRestrictionResolver;
use App\Modules\Outreach\Data\OutreachDecision;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Http\Controllers\Concerns\ResolvesOutreach;
use App\Modules\Outreach\Http\Requests\DraftMessageRequest;
use App\Modules\Outreach\Http\Requests\LogContactAttemptRequest;
use App\Modules\Outreach\Http\Requests\RecordOutcomeRequest;
use App\Modules\Outreach\Http\Requests\UpdateMessageRequest;
use App\Modules\Outreach\Http\Resources\ContactAttemptResource;
use App\Modules\Outreach\Http\Resources\OutreachMessageResource;
use App\Modules\Outreach\Http\Resources\OutreachTemplateResource;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachTemplate;
use App\Modules\Outreach\Services\OutreachGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The outreach screen for one lead (Phase 11): draft, edit, copy, send, log.
 *
 * One controller because it is one screen and one job — everything here is authorised by
 * `LeadPolicy::work` or `OutreachMessagePolicy`, and splitting it into four would be four copies of
 * the same five lines.
 *
 * Every write redirects back: the screen is where the member is working, and Inertia re-fetches the
 * page's props so the new draft, the sent status and the fresh guard decision all arrive together.
 * That last point matters — the guard decision is re-computed on every render, so an opt-out logged
 * a moment ago disables the Send button without anyone having to remember to refresh it.
 */
final class OutreachController extends Controller
{
    use ResolvesOutreach;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function show(Request $request, int $lead, OutreachGuard $guard): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead, ['activeScore']);

        $this->authorize('view', $model);

        return $this->teamContext->run($team, function () use ($request, $team, $model, $user, $guard): Response {
            $contacts = $this->contactsFor($model);
            // The contact the picker will open on — the first with an address, which is what the
            // guard's decisions should describe. Deciding against "no contact" when one exists
            // would show the member a "no contact details" block they can't act on.
            $selected = $contacts->first(static fn (LeadContact $contact): bool => trim((string) $contact->email) !== '');

            // The restriction in force, from the same resolver the guard uses — so the banner on
            // the screen and the closed channel beside it always agree (Phase 15).
            $restriction = app(LeadRestrictionResolver::class)->restrictionFor($team, $model);

            return Inertia::render('outreach/Show', [
                'lead' => LeadResource::make($model)->resolve($request),
                'contacts' => LeadContactResource::collection($contacts)->resolve($request),
                'selectedContactId' => $selected?->getKey(),
                'messages' => OutreachMessageResource::collection(
                    OutreachMessage::query()
                        ->where('lead_id', $model->getKey())
                        ->with(['team', 'creator:id,name'])
                        ->latest('id')
                        ->limit(20)
                        ->get(),
                )->resolve($request),
                'attempts' => ContactAttemptResource::collection(
                    ContactAttempt::query()
                        ->where('lead_id', $model->getKey())
                        ->with(['contact', 'creator:id,name', 'message'])
                        ->orderByDesc('occurred_at')
                        ->orderByDesc('id')
                        ->limit(30)
                        ->get(),
                )->resolve($request),
                'templates' => OutreachTemplateResource::collection(
                    OutreachTemplate::query()->with('team')->orderBy('name')->get(),
                )->resolve($request),
                'options' => [
                    'channels' => $this->channelOptions(),
                    'tones' => $this->toneOptions(),
                    'outcomes' => $this->outcomeOptions(),
                    'locales' => array_map(static fn (SupportedLocale $locale): array => [
                        'value' => $locale->value,
                        'label' => $locale->native(),
                    ], SupportedLocale::cases()),
                ],
                // Per channel, with the opening contact in hand and the member's own sender
                // identity asserted — so the screen's Send button is never offered for something
                // the send would refuse.
                'decisions' => array_map(
                    static fn (OutreachDecision $decision): array => $decision->toPayload(),
                    $guard->decideAll($team, $model, $selected, $user),
                ),
                // The lead's own restriction, shown as a standing note rather than only as a
                // refusal — a member should know *why* email is off before they try it.
                'restriction' => $restriction === null ? null : [
                    'value' => $restriction->value,
                    'label' => $restriction->label(),
                    'guidance' => $restriction->guidance(),
                    'allows_direct_marketing' => $restriction->allowsDirectMarketing(),
                ],
                'can' => [
                    'work' => $user->can('work', $model),
                ],
            ]);
        });
    }

    public function draft(DraftMessageRequest $request, int $lead, DraftMessageAction $draft): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead, ['activeScore']);

        $this->authorize('work', $model);

        $this->teamContext->run($team, fn () => $draft->handle($team, $model, $user, $request->draftData()));

        return back();
    }

    /**
     * The member rewriting what the model wrote. Only the words — see {@see UpdateMessageRequest}.
     */
    public function update(UpdateMessageRequest $request, int $message): RedirectResponse
    {
        $model = $this->message($request, $message);

        $this->authorize('update', $model);

        $this->teamContext->run($this->team($request), static function () use ($model, $request): void {
            $model->forceFill([
                // Only on a channel that has one, so switching nothing else can't introduce a
                // subject on a call script.
                ...($model->channel->hasSubject() ? ['subject' => $request->subject()] : []),
                'body' => $request->body(),
            ])->save();
        });

        return back();
    }

    public function send(Request $request, int $message, SendOutreachEmailAction $send): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->message($request, $message, ['lead.team', 'contact']);

        $this->authorize('send', $model);

        $this->teamContext->run($team, fn () => $send->handle($team, $model, $user));

        return back()->with('toast', ['type' => 'success', 'message' => __('Message sent.')]);
    }

    public function log(LogContactAttemptRequest $request, int $lead, LogContactAttemptAction $log): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead);

        $this->authorize('work', $model);

        $this->teamContext->run($team, fn () => $log->handle($team, $model, $user, $request->attemptData()));

        return back();
    }

    public function outcome(
        RecordOutcomeRequest $request,
        int $attempt,
        LogContactAttemptAction $log,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->attempt($request, $attempt);

        // Authorised on the lead, not the attempt: recording how an approach went is working the
        // lead, and it is the same permission as having made the approach in the first place.
        $this->authorize('work', $model->lead);

        $this->teamContext->run(
            $team,
            fn () => $log->updateOutcome($team, $model, $user, $request->outcome(), $request->notes()),
        );

        return back();
    }

    public function destroy(Request $request, int $message): RedirectResponse
    {
        $model = $this->message($request, $message);

        $this->authorize('delete', $model);

        $this->teamContext->run($this->team($request), static fn () => $model->delete());

        return back();
    }

    /**
     * @return list<array{value: string, label: string, description: string, is_sendable: bool}>
     */
    private function channelOptions(): array
    {
        return array_map(static fn (OutreachChannel $channel): array => [
            'value' => $channel->value,
            'label' => $channel->label(),
            'description' => $channel->description(),
            'is_sendable' => $channel->isSendable(),
        ], OutreachChannel::cases());
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function toneOptions(): array
    {
        return array_map(static fn (OutreachTone $tone): array => [
            'value' => $tone->value,
            'label' => $tone->label(),
            'description' => $tone->description(),
        ], OutreachTone::cases());
    }

    /**
     * The outcomes a member may pick, each flagged with whether choosing it suppresses the contact
     * — so the screen can warn before, rather than surprise after.
     *
     * @return list<array{value: string, label: string, suppresses: bool, is_global: bool}>
     */
    private function outcomeOptions(): array
    {
        return array_map(static function (ContactOutcome $outcome): array {
            $scope = $outcome->suppressionScope();

            return [
                'value' => $outcome->value,
                'label' => $outcome->label(),
                'suppresses' => $scope !== null,
                'is_global' => $scope === SuppressionScope::Global,
            ];
        }, ContactOutcome::cases());
    }
}
