<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Services\SignalScorer;
use Illuminate\Database\Eloquent\Builder;

/**
 * How the feed is ordered (S12). The app branches on it, so it is an enum.
 *
 * **Heat is the default and date is kept**, which is the honest arrangement for a ranking nobody
 * has learned to trust yet. {@see SignalScorer} is deterministic and every card can show its own
 * breakdown, but a member who suspects the ranking has to be able to fall back to the order they
 * already understand — and the fastest way to lose their trust in the score would be to take that
 * away. The sort travels in the query string like every other filter on this screen, so a shared
 * link opens the same feed.
 *
 * Both orders tie-break on `id` descending, which is what makes paging stable when a run writes a
 * dozen signals sharing a timestamp or a score.
 */
enum SignalSortOrder: string
{
    /** What this event is worth to this team, hottest first. Unscored signals sort last. */
    case Heat = 'heat';

    /** When the event was reported, newest first — v1's order, and the fallback. */
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Heat => 'Best for you',
            self::Published => 'Most recent',
        };
    }

    /** A translation key saying what the order means, for the control's own description. */
    public function description(): string
    {
        return match ($this) {
            self::Heat => 'Ranked by how much each event is worth to you. Every card can show its working.',
            self::Published => 'Newest first, by when the event was reported.',
        };
    }

    /**
     * Apply this order to the feed's query.
     *
     * The **null handling is the part worth reading**. `heat` is nullable: a signal found before
     * this phase has no score until `signals:rescore` gives it one, and `ORDER BY heat DESC` puts
     * nulls first on MySQL and last on SQLite — one of those is a feed that opens on its oldest
     * unscored rows, and which one you get depends on the database. `whereNotNull`-style ordering
     * via a sort key fixes it in one place for both: an unscored signal sorts **last**, because
     * "we have not judged this" is not "this is the best thing we found".
     *
     * @param  Builder<Signal>  $query
     */
    public function apply(Builder $query): void
    {
        match ($this) {
            self::Heat => $query
                ->orderByRaw('case when signals.heat is null then 1 else 0 end')
                ->orderByDesc('signals.heat')
                ->orderByDesc('signals.published_at'),
            self::Published => $query->orderByDesc('signals.published_at'),
        };

        $query->orderByDesc('signals.id');
    }

    /**
     * The order a value names, falling back to heat.
     *
     * An unrecognised value lands on the default rather than erroring, for the same reason an
     * unrecognised status lands on New ({@see ListSignalsAction}): a stale bookmark should open a
     * feed, not a 404.
     */
    public static function fromRequest(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Heat;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $order): string => $order->value, self::cases());
    }
}
