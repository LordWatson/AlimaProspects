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

    public function originFor(Lead $lead): ?LeadOrigin
    {
        $signalId = $lead->raw['signal_id'] ?? null;

        if (! is_int($signalId) && ! (is_string($signalId) && ctype_digit($signalId))) {
            return null;
        }

        $signal = Signal::query()
            ->where('team_id', $lead->team_id)
            ->whereKey((int) $signalId)
            ->first();

        if (! $signal instanceof Signal) {
            // The signal was swept by the retention job (S6) while its lead lived on. The lead keeps
            // its `source` attribution; there is simply no event left to show.
            return null;
        }

        return new LeadOrigin(
            kind: self::KIND,
            label: LeadSource::Signal->label(),
            headline: $signal->headline,
            detail: $signal->event_type->label(),
            url: $signal->source_url,
            sourceName: $signal->source_name,
            occurredAt: $signal->published_at?->utc()->toIso8601String(),
            // The Converted tab of the feed, which is where this signal now lives. Not
            // `?signal={id}`: the feed has no such filter, so that link would silently land on
            // the New tab and show everything *except* the signal it promised.
            href: '/signals?status='.SignalStatus::Converted->value,
        );
    }
}
