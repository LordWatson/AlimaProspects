<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Signals\Events\AccountSignalsDetected;
use App\Modules\Signals\Models\Signal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the person working an account that something happened to it (S10).
 *
 * **Addressed to the lead's owners, not to the team**, which is the whole difference from
 * {@see AnnounceSignals}. A discovery signal is news for everybody — nobody owns a stranger. An
 * event about a company somebody is working is news for *that* person, and sending it to the team
 * would make it everybody's to ignore.
 *
 * **Aggregated per run and per recipient**, the way `AnnounceTopLeadsJob` aggregates: a run that
 * matched six accounts to one member's leads sends that member one notification saying six, not six
 * notifications. A watch runs daily, so the alternative is a feature somebody switches off inside a
 * week.
 *
 * A lead nobody owns falls back to the team. That is deliberate rather than a shrug: an unassigned
 * lead with an acquisition against it is exactly the lead somebody should pick up, and saying
 * nothing because it has no owner would be the wrong silence.
 *
 * **A retry resumes rather than resends.** It sends to several people in a loop, so a failure after
 * the third recipient would, on `tries: 3`, tell the first three again. Each recipient's send is
 * marked in the cache under the run, and a marked recipient is skipped — not a transaction, but the
 * right shape for a job whose unit of work is "one person has been told once".
 */
final class AnnounceAccountSignals implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly TeamContext $teamContext,
    ) {}

    public function handle(AccountSignalsDetected $event): void
    {
        if ($event->count < 1) {
            return;
        }

        $team = Team::query()->find($event->teamId);

        if (! $team instanceof Team) {
            return;
        }

        $this->teamContext->run($team, function () use ($team, $event): void {
            /** @var list<Signal> $matched */
            $matched = Signal::query()
                ->where('signal_run_id', $event->runId)
                ->whereNotNull('matched_lead_id')
                ->with('matchedLead')
                ->get()
                ->all();

            if ($matched === []) {
                return;
            }

            // lead id => [signal count, one company name, one lead id]
            $byLead = [];

            foreach ($matched as $signal) {
                $lead = $signal->matchedLead;

                if (! $lead instanceof Lead) {
                    // The lead was deleted between the run and this job. The signal keeps its own
                    // record; there is nobody to tell.
                    continue;
                }

                $leadId = (int) $lead->getKey();

                $byLead[$leadId] ??= ['count' => 0, 'company' => $lead->company_name];
                $byLead[$leadId]['count']++;
            }

            if ($byLead === []) {
                return;
            }

            $owners = $this->ownersByLead(array_keys($byLead));

            // recipient id => [accounts, events, one company, one lead id]
            $perRecipient = [];
            $unowned = ['accounts' => 0, 'events' => 0, 'company' => '', 'lead_id' => null];

            foreach ($byLead as $leadId => $summary) {
                $leadOwners = $owners[$leadId] ?? [];

                if ($leadOwners === []) {
                    $unowned['accounts']++;
                    $unowned['events'] += $summary['count'];
                    $unowned['company'] = $unowned['company'] === '' ? $summary['company'] : $unowned['company'];
                    $unowned['lead_id'] ??= $leadId;

                    continue;
                }

                foreach ($leadOwners as $userId) {
                    $perRecipient[$userId] ??= ['accounts' => 0, 'events' => 0, 'company' => '', 'lead_id' => null];
                    // **Accounts and events are counted separately**, because the sentence is about
                    // accounts: six events at one company is "News about Acme Ltd", not "news about
                    // 6 of your accounts" (review finding).
                    $perRecipient[$userId]['accounts']++;
                    $perRecipient[$userId]['events'] += $summary['count'];
                    $perRecipient[$userId]['company'] = $perRecipient[$userId]['company'] === ''
                        ? $summary['company']
                        : $perRecipient[$userId]['company'];
                    $perRecipient[$userId]['lead_id'] ??= $leadId;
                }
            }

            foreach ($perRecipient as $userId => $summary) {
                $user = User::query()->find($userId);

                if (! $user instanceof User) {
                    continue;
                }

                if (! $this->firstTime($event->runId, $userId)) {
                    continue;
                }

                $this->dispatcher->send($team, $user, NotificationType::SignalOnAccount, [
                    'count' => $summary['accounts'],
                    'events' => $summary['events'],
                    // A name and two ids. No headline: the payload stays keys and params, and the
                    // article's own words live on the signal (CLAUDE.md notifications rule).
                    'company' => $summary['company'],
                    'lead_id' => $summary['lead_id'],
                    'run_id' => $event->runId,
                ]);
            }

            if ($unowned['accounts'] > 0 && $this->firstTime($event->runId, 0)) {
                $this->dispatcher->toTeam($team, NotificationType::SignalOnAccount, [
                    'count' => $unowned['accounts'],
                    'events' => $unowned['events'],
                    'company' => $unowned['company'],
                    'lead_id' => $unowned['lead_id'],
                    'run_id' => $event->runId,
                ]);
            }
        });
    }

    /**
     * Whether this recipient still has to be told about this run.
     *
     * `0` stands for "the team", which is the one recipient that is not a user. The marker lasts a
     * day: long enough to cover every retry of a job that runs once, short enough not to be a
     * second store of state.
     */
    private function firstTime(int $runId, int $userId): bool
    {
        return Cache::add("signals:account-announced:{$runId}:{$userId}", true, now()->addDay());
    }

    /**
     * Who is working each of these leads.
     *
     * A lead can have several owners — a researcher and a closer on the same company is normal
     * (Phase 10b) — so every one of them hears about it. One query for the whole batch, because
     * this runs once per watch run and a per-lead query would be a fan-out of its own.
     *
     * @param  list<int>  $leadIds
     * @return array<int, list<int>>
     */
    private function ownersByLead(array $leadIds): array
    {
        $owners = [];

        foreach (LeadAssignment::query()->whereIn('lead_id', $leadIds)->get(['lead_id', 'user_id']) as $assignment) {
            $leadId = (int) $assignment->lead_id;
            $owners[$leadId] ??= [];

            if (! in_array((int) $assignment->user_id, $owners[$leadId], strict: true)) {
                $owners[$leadId][] = (int) $assignment->user_id;
            }
        }

        return $owners;
    }

    public function failed(AccountSignalsDetected $event, Throwable $exception): void
    {
        Log::channel('notifications')->error('Could not announce signals about existing accounts.', [
            'team_id' => $event->teamId,
            'run_id' => $event->runId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
