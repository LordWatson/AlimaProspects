<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Services\SignalQueryBuilder;
use Spatie\LaravelData\Data;

/**
 * What a run is about to search for, and what it wanted to (S8, ADR 0019 §4).
 *
 * {@see SignalQueryBuilder} used to return a bare list, which meant the caller could not tell a run
 * of forty queries from a run of forty queries that had been **cut down** from six hundred. S8
 * makes that distinction matter: the query count is templates × locations now, so a team adding
 * places can hit the `signals.max_queries_per_run` ceiling without anything on screen saying their
 * coverage stopped growing. A silent trim is a lie about what was searched.
 *
 * So the plan carries both numbers, {@see SignalRun} stores both, and the watch card reads the
 * difference.
 */
final class SignalQueryPlan extends Data
{
    /**
     * @param  list<SignalQuery>  $queries  What will actually run, after the ceiling.
     * @param  int  $requested  What the watch's types and places came to before it.
     */
    public function __construct(
        public readonly array $queries,
        public readonly int $requested,
    ) {}

    public function count(): int
    {
        return count($this->queries);
    }

    /**
     * Whether the ceiling cut this plan down.
     */
    public function wasTrimmed(): bool
    {
        return $this->requested > $this->count();
    }
}
