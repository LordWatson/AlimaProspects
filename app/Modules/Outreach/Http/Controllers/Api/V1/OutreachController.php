<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Actions\DraftMessageAction;
use App\Modules\Outreach\Actions\LogContactAttemptAction;
use App\Modules\Outreach\Actions\SendOutreachEmailAction;
use App\Modules\Outreach\Data\OutreachDecision;
use App\Modules\Outreach\Http\Controllers\Concerns\ResolvesOutreach;
use App\Modules\Outreach\Http\Requests\DraftMessageRequest;
use App\Modules\Outreach\Http\Requests\LogContactAttemptRequest;
use App\Modules\Outreach\Http\Requests\RecordOutcomeRequest;
use App\Modules\Outreach\Http\Requests\UpdateMessageRequest;
use App\Modules\Outreach\Http\Resources\ContactAttemptResource;
use App\Modules\Outreach\Http\Resources\OutreachMessageResource;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Services\OutreachGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Outreach over the API (Phase 11). Parity with the web screen, and the same Actions behind both.
 *
 * `decisions` is exposed as its own endpoint as well as inline on the lead's messages, because a
 * mobile client needs to know which channels are open **before** it offers a compose screen —
 * discovering at send time that a lead may not be cold-emailed is a wasted draft and a confusing
 * error.
 */
final class OutreachController extends Controller
{
    use ResolvesOutreach;

    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * The lead's drafts and sent messages, newest first.
     */
    public function index(Request $request, int $lead): AnonymousResourceCollection
    {
        $team = $this->team($request);
        $model = $this->lead($request, $lead);

        $this->authorize('view', $model);

        return $this->teamContext->run($team, fn (): AnonymousResourceCollection => OutreachMessageResource::collection(
            OutreachMessage::query()
                ->where('lead_id', $model->getKey())
                ->with(['team', 'creator:id,name'])
                ->latest('id')
                ->paginate(perPage: 20),
        ));
    }

    /**
     * Which channels this lead may be approached on, and why not when they may not.
     */
    public function decisions(Request $request, int $lead, OutreachGuard $guard): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->lead($request, $lead);

        $this->authorize('view', $model);

        return $this->teamContext->run($team, function () use ($team, $model, $user, $guard): JsonResponse {
            $contacts = $this->contactsFor($model);
            $selected = $contacts->first(static fn ($contact): bool => trim((string) $contact->email) !== '');

            return new JsonResponse([
                'data' => array_values(array_map(
                    static fn (OutreachDecision $decision): array => $decision->toPayload(),
                    $guard->decideAll($team, $model, $selected, $user),
                )),
            ]);
        });
    }

    public function draft(DraftMessageRequest $request, int $lead, DraftMessageAction $draft): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->lead($request, $lead, ['activeScore']);

        $this->authorize('work', $model);

        $message = $this->teamContext->run(
            $team,
            fn (): OutreachMessage => $draft->handle($team, $model, $user, $request->draftData()),
        );

        return OutreachMessageResource::make($message)->response()->setStatusCode(201);
    }

    public function show(Request $request, int $message): OutreachMessageResource
    {
        $model = $this->message($request, $message, ['creator:id,name']);

        $this->authorize('view', $model);

        return OutreachMessageResource::make($model);
    }

    public function update(UpdateMessageRequest $request, int $message): OutreachMessageResource
    {
        $model = $this->message($request, $message);

        $this->authorize('update', $model);

        $this->teamContext->run($this->team($request), static function () use ($model, $request): void {
            $model->forceFill([
                ...($model->channel->hasSubject() ? ['subject' => $request->subject()] : []),
                'body' => $request->body(),
            ])->save();
        });

        return OutreachMessageResource::make($model);
    }

    public function send(Request $request, int $message, SendOutreachEmailAction $send): OutreachMessageResource
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->message($request, $message, ['lead.team', 'contact']);

        $this->authorize('send', $model);

        $sent = $this->teamContext->run(
            $team,
            fn (): OutreachMessage => $send->handle($team, $model, $user),
        );

        return OutreachMessageResource::make($sent);
    }

    /**
     * The lead's contact history.
     */
    public function attempts(Request $request, int $lead): AnonymousResourceCollection
    {
        $team = $this->team($request);
        $model = $this->lead($request, $lead);

        $this->authorize('view', $model);

        return $this->teamContext->run($team, fn (): AnonymousResourceCollection => ContactAttemptResource::collection(
            ContactAttempt::query()
                ->where('lead_id', $model->getKey())
                ->with(['contact', 'creator:id,name', 'message'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(perPage: 30),
        ));
    }

    public function log(LogContactAttemptRequest $request, int $lead, LogContactAttemptAction $log): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->lead($request, $lead);

        $this->authorize('work', $model);

        $attempt = $this->teamContext->run(
            $team,
            fn (): ContactAttempt => $log->handle($team, $model, $user, $request->attemptData()),
        );

        return ContactAttemptResource::make($attempt->load(['contact', 'creator:id,name']))
            ->response()
            ->setStatusCode(201);
    }

    public function outcome(
        RecordOutcomeRequest $request,
        int $attempt,
        LogContactAttemptAction $log,
    ): ContactAttemptResource {
        $user = $this->user($request);
        $team = $this->team($request);
        $model = $this->attempt($request, $attempt);

        $this->authorize('work', $model->lead);

        $updated = $this->teamContext->run(
            $team,
            fn (): ContactAttempt => $log->updateOutcome($team, $model, $user, $request->outcome(), $request->notes()),
        );

        return ContactAttemptResource::make($updated->load(['contact', 'creator:id,name']));
    }

    public function destroy(Request $request, int $message): JsonResponse
    {
        $model = $this->message($request, $message);

        $this->authorize('delete', $model);

        $this->teamContext->run($this->team($request), static fn () => $model->delete());

        return new JsonResponse(status: 204);
    }
}
