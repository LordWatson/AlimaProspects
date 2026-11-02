<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\SerperNewsClient;
use App\Modules\Signals\Services\SignalQueryBuilder;
use Spatie\LaravelData\Data;

/**
 * One resolved Serper `/news` query, built by {@see SignalQueryBuilder} from a watch, its signal
 * definitions and the team's active ICP, and executed by {@see SerperNewsClient}. Carries the
 * Serper parameters (`gl` country, `hl` language, `tbs` recency, `num` result count).
 * Deterministic — no AI.
 *
 * `eventKey` is the {@see SignalDefinition} key this query targets, so a result can be attributed
 * back to the type that asked for it. A **key rather than the definition itself**: the query is
 * handed to an HTTP client that has no business holding a tenant's model, and the key is all the
 * attribution needs.
 *
 * `ignoreDomains` is the **watch's own** exclusion list (S6), carried on the query rather than read
 * from config by the client: the client is given everything one search needs and holds no notion of
 * which team asked, so it can't accidentally apply one team's list to another's results.
 */
final class SignalQuery extends Data
{
    /**
     * @param  string  $eventKey  The signal definition's key this query is looking for.
     * @param  list<string>  $ignoreDomains  Per-team domains to drop, merged with `signals.ignore_domains`.
     */
    public function __construct(
        public string $eventKey,
        public string $q,
        public string $gl,
        public string $hl,
        public string $tbs,
        public int $num,
        public array $ignoreDomains = [],
    ) {}
}
