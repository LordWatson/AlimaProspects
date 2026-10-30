<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Events\LeadAssigned;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells members a lead has been put in their hands (Phase 12).
 *
 * **Never the person who did the assigning**, even when they assigned it to themselves — which is
 * the single most common assignment in the product, since a member picking up a lead assigns it to
 * their own name. Notifying them about their own click is the fastest way to teach somebody that
 * our notifications are worthless.
 */
final class AnnounceLeadAssignment implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    public bool $afterCommit = true;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function handle(LeadAssigned $event): void
    {
        $recipientIds = array_values(array_filter(
            $event->userIds,
            static fn (int $id): bool => $id !== $event->actorId,
        ));

        if ($recipientIds === []) {
            return;
        }

        $team = Team::query()->find($event->teamId);

        if (! $team instanceof Team) {
            return;
        }

        $lead = $this->teamContext->run($team, fn (): ?Lead => Lead::query()->find($event->leadId));

        if (! $lead instanceof Lead) {
            return;
        }

        $recipients = User::query()->whereIn('id', $recipientIds)->get();
        $actor = $event->actorId === null ? null : User::query()->find($event->actorId);

        $this->dispatcher->send($team, $recipients, NotificationType::LeadAssigned, [
            'lead_id' => $lead->getKey(),
            'company' => $lead->company_name,
            // Their name, not their id: the sentence reads "Dana gave you Acme Ltd", and a
            // notification read six months later should not depend on that account still existing.
            'actor' => $actor instanceof User ? (string) $actor->name : '',
        ]);
    }

    public function failed(LeadAssigned $event, Throwable $exception): void
    {
        Log::channel('notifications')->error('Could not announce a lead assignment.', [
            'team_id' => $event->teamId,
            'lead_id' => $event->leadId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
