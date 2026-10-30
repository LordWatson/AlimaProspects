<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Services\SerperNewsClient;
use App\Modules\Signals\Services\SignalQueryBuilder;
use Spatie\LaravelData\Data;

/**
 * One resolved Serper `/news` query, built by {@see SignalQueryBuilder} from a watch and its active
 * ICP and executed by {@see SerperNewsClient}. Carries the event type it targets so extracted
 * results can be attributed, plus the Serper parameters (`gl` country, `hl` language, `tbs` recency,
 * `num` result count). Deterministic — no AI.
 *
 * `ignoreDomains` is the **watch's own** exclusion list (S6), carried on the query rather than read
 * from config by the client: the client is given everything one search needs and holds no notion of
 * which team asked, so it can't accidentally apply one team's list to another's results.
 */
final class SignalQuery extends Data
{
    /**
     * @param  list<string>  $ignoreDomains  Per-team domains to drop, merged with `signals.ignore_domains`.
     */
    public function __construct(
        public SignalEventType $eventType,
        public string $q,
        public string $gl,
        public string $hl,
        public string $tbs,
        public int $num,
        public array $ignoreDomains = [],
    ) {}
}
