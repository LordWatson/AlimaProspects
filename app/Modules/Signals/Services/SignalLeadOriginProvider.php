<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\LeadsWorkspace\Data\LeadOrigin;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;

/**
 * Tells the Leads Workspace that a lead began as a signal, so the detail screen can show the event
 * it came from and link to the article (S4).
 *
 * The lookup is **`raw.signal_id` alone**, written by `ConvertSignalToLeadAction`, and deliberately
 * not the lead's `source`. A signal converted onto a company the team already had attaches to that
 * lead and leaves its stronger attribution intact — the source stays `Import` or `Companies House`
 * — but the event is still where this lead came to the team's attention, and that is the lead most
 * worth showing it on.
 *
 * The signal row is then **re-read** rather than trusting the copy in `raw`: the row is the current
 * truth about what the event was, while `raw` is a snapshot from the moment of conversion.
 *
 * Nothing here is expensive — one array lookup for the ordinary lead that has no pointer, and one
 * indexed primary-key read for one that does. The query is scoped by `team_id` beside the global
 * scope, so a lead can never show another team's signal even if a `raw` payload were tampered with.
 */
final class SignalLeadOriginProvider implements LeadOriginProvider
{
    /** The discriminator the lead detail screen keys its icon and heading off. */
    public const string KIND = 'signal';

    /**
     * Everything that has happened to this company, newest first (S10).
     *
     * Two kinds of row, read in one query: the signal this lead was **converted from** (where there
     * is one) and the signals **matched to** it since — events about a company the team already
     * had. The distinction is in `detail` rather than in two lists, because a member reading the
     * panel wants the history of the company, not a taxonomy of how Alima found each line of it.
     *
     * One indexed read, bounded by {@see LeadOriginProvider::MAX_EVENTS}. A matched signal keeps
     * arriving; the panel shows the last few and the timeline holds the rest.
     *
     * @return list<LeadOrigin>
     */
    public function eventsFor(Lead $lead): array
    {
        $convertedId = $this->signalIdFrom($lead);

        $signals = Signal::query()
            ->where('team_id', $lead->team_id)
            ->with('definition')
            ->where(function ($query) use ($lead, $convertedId): void {
                $query->where('matched_lead_id', $lead->getKey());

                if ($convertedId !== null) {
                    $query->orWhere('id', $convertedId);
                }
            })
            // Newest first, by when the event was published rather than when we stored it: a
            // member reading this wants the company's history in the order it happened.
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(LeadOriginProvider::MAX_EVENTS)
            ->get();

        $events = [];

        foreach ($signals as $signal) {
            $events[] = $this->toOrigin($signal, converted: (int) $signal->getKey() === $convertedId);
        }

        return $events;
    }

    public function originFor(Lead $lead): ?LeadOrigin
    {
        $signalId = $lead->raw['signal_id'] ?? null;

        if (! is_int($signalId) && ! (is_string($signalId) && ctype_digit($signalId))) {
            return null;
        }

        $signal = Signal::query()
            ->where('team_id', $lead->team_id)
            // Eager-loaded because `eventLabel()` reads it, and a lazy read would trip
            // `preventLazyLoading` on the lead detail screen (S7, ADR 0018).
            ->with('definition')
            ->whereKey((int) $signalId)
            ->first();

        if (! $signal instanceof Signal) {
            // The signal was swept by the retention job (S6) while its lead lived on. The lead keeps
            // its `source` attribution; there is simply no event left to show.
            return null;
        }

        return $this->toOrigin($signal, converted: true);
    }

    /**
     * The lead's `raw.signal_id`, when it has a readable one.
     */
    private function signalIdFrom(Lead $lead): ?int
    {
        $signalId = $lead->raw['signal_id'] ?? null;

        if (is_int($signalId)) {
            return $signalId;
        }

        return is_string($signalId) && ctype_digit($signalId) ? (int) $signalId : null;
    }

    /**
     * One signal as the workspace's generic origin shape.
     *
     * `$converted` decides the wording and the link: the signal this lead came **from** points at
     * the Converted tab where it now lives, and an event merely **about** the lead points at the
     * accounts tab, where the rest of its kind are. Sending both to the same place would be a link
     * that lands on a list the signal is not in.
     */
    private function toOrigin(Signal $signal, bool $converted): LeadOrigin
    {
        return new LeadOrigin(
            kind: self::KIND,
            label: $converted ? LeadSource::Signal->label() : 'Something happened',
            headline: $signal->headline,
            // The definition's name, or a humanised key where the type has since been deleted -
            // a lead's origin panel must not go blank because the team tidied up their library.
            detail: $signal->eventLabel(),
            url: $signal->source_url,
            sourceName: $signal->source_name,
            occurredAt: $signal->published_at?->utc()->toIso8601String(),
            // The Converted tab of the feed, which is where a converted signal now lives. Not
            // `?signal={id}`: the feed has no such filter, so that link would silently land on
            // the New tab and show everything *except* the signal it promised.
            href: $converted
                ? '/signals?status='.SignalStatus::Converted->value
                : '/signals?status='.SignalStatus::New->value.'&scope=accounts',
        );
    }
}
