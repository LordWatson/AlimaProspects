<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Notifications\Data\NotificationContent;
use App\Modules\Notifications\Enums\NotificationType;
use Illuminate\Support\Str;

/**
 * Turns a notification type plus its payload into what it says (Phase 12).
 *
 * One place, so the bell, the email and the lock screen cannot drift apart — and so a new type's
 * wording is written once rather than three times. Everything returned is a **translation key plus
 * params** (see {@see NotificationContent}).
 *
 * Two rules the copy here follows:
 *
 * - **Say the number.** "3 new strong matches" is worth reading; "New matches found" makes a member
 *   open the app to find out whether it was worth it. Counts come from the payload, so the sentence
 *   is as specific as the data allows.
 * - **Never a bare noun where a name fits.** "Dana gave you Acme Ltd" beats "A lead was assigned to
 *   you", and the payload carries the names precisely so it can.
 */
final class NotificationPresenter
{
    /** Long enough to be useful on a lock screen, short enough not to be truncated there. */
    private const int NAME_LIMIT = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function present(NotificationType $type, array $payload): NotificationContent
    {
        return match ($type) {
            NotificationType::DiscoveryComplete => $this->discoveryComplete($payload),
            NotificationType::TopLeads => $this->topLeads($payload),
            NotificationType::TaskDue => $this->taskDue($payload),
            NotificationType::LeadAssigned => $this->leadAssigned($payload),
            NotificationType::SignalsDetected => $this->signalsDetected($payload),
            NotificationType::InsightsReady => $this->insightsReady($payload),
            NotificationType::CreditLow => $this->creditLow($payload),
            NotificationType::Digest => $this->digest($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function discoveryComplete(array $payload): NotificationContent
    {
        $created = $this->count($payload, 'leads_created');
        $runId = $this->id($payload, 'run_id');

        // A search that found nothing is still news — it means the ICP or the terms need a look,
        // and saying so is more useful than a notification the member has to open to learn it.
        return new NotificationContent(
            type: NotificationType::DiscoveryComplete,
            title: $created === 0 ? 'Your search found nothing new' : 'Your search is done',
            body: $created === 0
                ? 'Nothing matched this time. Try widening the terms or the area.'
                : ':count new companies are waiting in your leads.',
            params: ['count' => $created],
            url: $runId === null ? '/leads' : '/discovery/'.$runId,
            actionLabel: $created === 0 ? 'Review the search' : 'See the leads',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function topLeads(array $payload): NotificationContent
    {
        $count = $this->count($payload, 'count');
        $names = $this->names($payload);

        return new NotificationContent(
            type: NotificationType::TopLeads,
            title: $count === 1 ? 'A strong new match' : ':count strong new matches',
            // Names when we have them: "Acme Ltd and 4 others" tells a member whether to stop what
            // they are doing, which ":count graded A" does not. The overflow is a separate param
            // rather than part of the string, so the sentence picks a key instead of this method
            // translating a fragment — see the note on `names()`.
            body: match (true) {
                $names['list'] === '' => 'Alima graded :count companies as a strong fit for you.',
                $names['others'] > 0 => 'Including :names and :others others.',
                default => 'Including :names.',
            },
            params: ['count' => $count, 'names' => $names['list'], 'others' => $names['others']],
            url: '/leads',
            actionLabel: 'See them',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function taskDue(array $payload): NotificationContent
    {
        return new NotificationContent(
            type: NotificationType::TaskDue,
            title: 'A reminder you set',
            body: ':task — :company',
            params: [
                'task' => $this->text($payload, 'title'),
                'company' => $this->text($payload, 'company'),
            ],
            url: $this->leadUrl($payload),
            actionLabel: 'Open the lead',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function leadAssigned(array $payload): NotificationContent
    {
        $actor = $this->text($payload, 'actor');

        return new NotificationContent(
            type: NotificationType::LeadAssigned,
            title: 'A lead for you',
            body: $actor === '' ? 'You are now working :company.' : ':actor gave you :company.',
            params: ['actor' => $actor, 'company' => $this->text($payload, 'company')],
            url: $this->leadUrl($payload),
            actionLabel: 'Open the lead',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signalsDetected(array $payload): NotificationContent
    {
        $count = $this->count($payload, 'count');

        return new NotificationContent(
            type: NotificationType::SignalsDetected,
            title: $count === 1 ? 'A new signal' : ':count new signals',
            body: 'Someone just came into money. Worth a look while it is fresh.',
            params: ['count' => $count],
            url: '/signals',
            actionLabel: 'See the signals',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insightsReady(array $payload): NotificationContent
    {
        $contact = $this->count($payload, 'contact_count');
        $stale = $this->count($payload, 'stale_count');

        // Lead with the thing to do. A member reading a lock screen at eight in the morning wants
        // "five worth a call", not "three new insights" — the count of insights is a count of
        // cards, which is our unit and not theirs. The cold ones come second because they are a
        // warning rather than a plan, and the generic line is only reached when the run produced
        // nothing but the model's commentary.
        return new NotificationContent(
            type: NotificationType::InsightsReady,
            title: 'Your morning read is ready',
            body: match (true) {
                $contact > 0 => ':contact companies are worth approaching today.',
                $stale > 0 => ':stale companies are going cold.',
                default => 'Alima has :count things to show you.',
            },
            params: [
                'contact' => $contact,
                'stale' => $stale,
                'count' => $this->count($payload, 'count'),
            ],
            url: '/insights',
            actionLabel: 'See the insights',
        );
    }

    /**
     * The one allowance warning, for either metered thing. The payload carries a `metric`
     * ({@see BillingMetric}) so the sentence names what has run out and what stops when it does —
     * "scoring and drafting stop" and "new searches stop" are different pieces of news, and a
     * message generic enough to cover both tells an Owner nothing they can act on.
     *
     * Defaults to AI credits when no metric is given, which is what rows stored before Phase 16
     * carry.
     *
     * @param  array<string, mixed>  $payload
     */
    private function creditLow(array $payload): NotificationContent
    {
        $remaining = $this->count($payload, 'remaining_percent');
        $metric = BillingMetric::tryFrom((string) ($payload['metric'] ?? '')) ?? BillingMetric::AiCredits;

        $thing = $metric === BillingMetric::Leads ? 'Your search allowance' : 'AI credit';

        return new NotificationContent(
            type: NotificationType::CreditLow,
            title: $remaining === 0 ? ':thing has run out' : ':thing is running low',
            body: $remaining === 0
                ? $metric->exhaustedDescription()
                : 'About :percent% is left. '.$metric->exhaustedDescription(),
            params: ['percent' => $remaining, 'thing' => $thing],
            url: '/billing',
            actionLabel: 'See the plan',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function digest(array $payload): NotificationContent
    {
        return new NotificationContent(
            type: NotificationType::Digest,
            title: $this->text($payload, 'period') === 'weekly'
                ? 'Your week on Alima'
                : 'Your day on Alima',
            // The digest's substance is its own email template's sections; this line is the
            // one-sentence version a push or the bell gets.
            body: ':count things happened. Here is the short version.',
            params: ['count' => $this->count($payload, 'count')],
            url: '/dashboard',
            actionLabel: 'Open Alima',
        );
    }

    /**
     * The names to show, comma-joined, plus how many are left over.
     *
     * **Nothing here is translated.** An earlier version joined with `__(' and ')`, which looked
     * tidier and was wrong: the result is *stored* on the notification row, so a notification
     * written while the app was in English would keep an English "and" when the member read it in
     * Arabic. Only punctuation is applied here; the joining word belongs to whichever sentence key
     * the caller picks.
     *
     * @param  array<string, mixed>  $payload
     * @return array{list: string, others: int}
     */
    private function names(array $payload): array
    {
        $names = $payload['names'] ?? null;

        if (! is_array($names)) {
            return ['list' => '', 'others' => 0];
        }

        $clean = array_values(array_filter(array_map(
            static fn (mixed $name): string => is_scalar($name) ? trim((string) $name) : '',
            $names,
        ), static fn (string $name): bool => $name !== ''));

        if ($clean === []) {
            return ['list' => '', 'others' => 0];
        }

        $clean = array_map(
            static fn (string $name): string => Str::limit($name, self::NAME_LIMIT, ''),
            $clean,
        );

        $total = $this->count($payload, 'count');
        $shown = array_slice($clean, 0, 2);

        return [
            'list' => implode(', ', $shown),
            'others' => max(0, $total - count($shown)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function leadUrl(array $payload): ?string
    {
        $leadId = $this->id($payload, 'lead_id');

        return $leadId === null ? null : '/leads/'.$leadId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function count(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function id(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A payload string, clamped. These are company names and task titles the member typed, so they
     * are data — never translated, and never allowed to run past a lock screen.
     *
     * @param  array<string, mixed>  $payload
     */
    private function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_scalar($value) ? Str::limit(Str::squish((string) $value), self::NAME_LIMIT, '') : '';
    }
}
