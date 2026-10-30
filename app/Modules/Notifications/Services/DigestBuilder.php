<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\Notifications\Data\DigestSummary;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Builds one member's round-up for a window (Phase 12).
 *
 * Two rules shape what goes in:
 *
 * - **Only what the member asked for.** Each section is gated on
 *   {@see NotificationPreferences::wants()} for the type it summarises, so somebody who switched
 *   discovery notifications off is not told about discovery runs in the digest either. A digest
 *   that ignores preferences is a way of re-enabling everything a member turned off.
 * - **Only what happened.** A section with a zero count is omitted, and a digest with no sections
 *   is not sent at all ({@see DigestSummary::isEmpty()}). "Nothing happened this week" is an email
 *   that teaches people to filter us.
 *
 * The one section that is **not** about the window is the open tasks: a digest's job on a Monday
 * morning is partly to say what is waiting, and a task that came due last week is more worth
 * mentioning than one that came due in the last 24 hours.
 */
final class DigestBuilder
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly NotificationPreferences $preferences,
    ) {}

    public function build(
        User $user,
        Team $team,
        DigestFrequency $frequency,
        // `DateTimeInterface` rather than Carbon: the app runs on CarbonImmutable
        // (AppServiceProvider), which is a sibling of Illuminate\Support\Carbon, not a subclass.
        ?DateTimeInterface $now = null,
    ): DigestSummary {
        $since = $frequency->windowStart($now ?? CarbonImmutable::now());

        return $this->teamContext->run($team, function () use ($user, $team, $frequency, $since): DigestSummary {
            $lines = [];
            $total = 0;

            foreach ($this->sections($user, $team, $since) as $section) {
                if ($section['count'] < 1) {
                    continue;
                }

                $total += $section['count'];
                $lines[] = [
                    'key' => $section['key'],
                    'params' => $section['params'],
                    'url' => $section['url'],
                ];
            }

            return new DigestSummary(frequency: $frequency, lines: $lines, total: $total);
        });
    }

    /**
     * Every candidate section, each already gated on the member's preferences.
     *
     * @return list<array{count: int, key: string, params: array<string, string|int>, url: string|null}>
     */
    private function sections(User $user, Team $team, CarbonImmutable $since): array
    {
        $sections = [];

        if ($this->preferences->wants($user, $team, NotificationType::DiscoveryComplete)) {
            $found = Lead::query()->where('created_at', '>=', $since)->count();

            $sections[] = [
                'count' => $found,
                'key' => ':count new companies found',
                'params' => ['count' => $found],
                'url' => '/leads',
            ];
        }

        if ($this->preferences->wants($user, $team, NotificationType::TopLeads)) {
            $strong = LeadScore::query()
                ->where('grade', ScoreGrade::A->value)
                ->where('scored_at', '>=', $since)
                ->whereHas('icp', fn ($query) => $query->where('is_active', true))
                ->count();

            $sections[] = [
                'count' => $strong,
                'key' => ':count graded a strong match',
                'params' => ['count' => $strong],
                'url' => '/leads',
            ];
        }

        if ($this->preferences->wants($user, $team, NotificationType::SignalsDetected)) {
            $signals = Signal::query()
                ->where('status', SignalStatus::New->value)
                ->where('created_at', '>=', $since)
                ->count();

            $sections[] = [
                'count' => $signals,
                'key' => ':count new signals waiting',
                'params' => ['count' => $signals],
                'url' => '/signals',
            ];
        }

        // Outreach, which is the team's own activity rather than something Alima did — so it is not
        // gated on a notification preference: there is no "tell me when I send an email" type, and
        // a member reading their round-up wants to know what the team has been doing.
        $approaches = ContactAttempt::query()->where('occurred_at', '>=', $since)->count();

        $sections[] = [
            'count' => $approaches,
            'key' => ':count companies approached',
            'params' => ['count' => $approaches],
            'url' => '/leads',
        ];

        $replies = ContactAttempt::query()
            ->where('occurred_at', '>=', $since)
            ->whereIn('outcome', [
                ContactOutcome::Replied->value,
                ContactOutcome::Interested->value,
                ContactOutcome::MeetingBooked->value,
            ])
            ->count();

        $sections[] = [
            'count' => $replies,
            'key' => ':count came back to you',
            'params' => ['count' => $replies],
            'url' => '/leads',
        ];

        if ($this->preferences->wants($user, $team, NotificationType::TaskDue)) {
            // Not windowed: on a Monday morning "what is waiting" matters more than "what became
            // due in the last 24 hours", and a task overdue since last week is the one to chase.
            $open = LeadTask::query()
                ->where('status', LeadTaskStatus::Open->value)
                ->where('assigned_to', $user->getKey())
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now())
                ->count();

            $sections[] = [
                'count' => $open,
                'key' => ':count of your tasks are due',
                'params' => ['count' => $open],
                'url' => '/leads',
            ];
        }

        return $sections;
    }
}
