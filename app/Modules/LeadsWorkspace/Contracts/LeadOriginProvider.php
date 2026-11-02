<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Contracts;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Data\LeadOrigin;
use App\Modules\LeadsWorkspace\Services\NoLeadOrigin;

/**
 * Resolves the richer story behind a lead for the detail screen's origin panel (S4).
 *
 * This exists to keep one dependency pointing one way. Signals creates leads, so Signals already
 * knows about Lead Sourcing and the workspace; the workspace must not learn about Signals in
 * return just to render a panel. So the workspace asks for an origin through this contract and the
 * default binding ({@see NoLeadOrigin}) answers "none" — Signals' provider replaces it, and a
 * later module with its own kind of origin does the same with no change to the screen or the
 * controller.
 */
interface LeadOriginProvider
{
    /** The most events the detail panel will show. A panel is not a feed. */
    public const int MAX_EVENTS = 6;

    /**
     * The lead's origin, or null when it has none beyond its `source` (the ordinary case).
     *
     * Called once per detail-screen render, so it must not be expensive: at most one indexed query.
     * Must be called inside the lead's team context.
     */
    public function originFor(Lead $lead): ?LeadOrigin;

    /**
     * **Everything** that has happened to this company, newest first (S10).
     *
     * The panel was one origin — *why this lead exists* — until a watchlist could put events on a
     * lead that already existed. Those are not origins: nothing about them explains where the lead
     * came from, and they keep arriving. So the screen shows a list, the first entry of which is
     * usually what {@see self::originFor()} returns.
     *
     * Still cheap: one indexed read bounded by {@see self::MAX_EVENTS}. A lead with two hundred
     * events shows the last few, because a panel is not a feed.
     *
     * @return list<LeadOrigin>
     */
    public function eventsFor(Lead $lead): array;
}
