<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\NewsSignalSource;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Services\WebSearchSignalSource;
use Spatie\LaravelData\Data;

/**
 * One resolved query against one signal source, built by {@see SignalQueryBuilder} from a watch, its
 * signal definitions and the team's active ICP, and executed by whichever provider the registry
 * resolves for its `kind`.
 *
 * **Renamed from `SignalQuery` at S13** (ADR 0023), because the DTO had stopped being "a Serper
 * `/news` query" — the same shape now addresses `/search`, and S13b's sources will address a
 * filings poll and a feed. Carries the Serper parameters (`gl` country, `hl` language, `tbs`
 * recency, `num` result count), which the Serper family reads and a non-search source ignores.
 * Deterministic — no AI.
 *
 * `eventKey` is the {@see SignalDefinition} key this query targets, so a result can be attributed
 * back to the type that asked for it. A **key rather than the definition itself**: the query is
 * handed to an HTTP client that has no business holding a tenant's model, and the key is all the
 * attribution needs.
 *
 * `kind` is which source will run it ({@see NewsSignalSource}, {@see WebSearchSignalSource}, …). The
 * builder emits one query per (place × region × definition × template × **source**), so the same
 * template can be asked of the news and of the wider web and the two results are attributable
 * separately — which is what makes the run's per-source breakdown and the card's source badge mean
 * anything.
 *
 * `region` is where this search is being asked (S8, ADR 0019), and `gl`/`hl` come from it. `gl` is
 * **nullable**, because `SearchRegion::Global` is not a country and sending a made-up code for it
 * would bias a search that is meant to be unbiased. The region travels beside them so a result can
 * be attributed to the place the team asked about, not just to the two-letter code.
 *
 * `ignoreDomains` is the **watch's own** exclusion list (S6), carried on the query rather than read
 * from config by the client: the client is given everything one search needs and holds no notion of
 * which team asked, so it can't accidentally apply one team's list to another's results. A source's
 * *own* defaults are not here — those are the source's business and it merges them itself
 * ({@see SignalSourceKind::ignoreDomains()}), because a team's list applies to every source and a
 * source's list applies to every team.
 *
 * `config` is the per-source settings from `signal_definitions.source_config` for this `kind` only,
 * so a provider never sees another source's configuration.
 */
final class SignalSourceQuery extends Data
{
    /**
     * @param  string  $eventKey  The signal definition's key this query is looking for.
     * @param  SignalSourceKind  $kind  Which source runs it (S13).
     * @param  SearchRegion  $region  Where this search is being asked (S8).
     * @param  string|null  $gl  The Serper country code, or null for an unbiased search.
     * @param  list<string>  $ignoreDomains  Per-team domains to drop, merged with `signals.ignore_domains`.
     * @param  array<string, mixed>  $config  This source's slice of the definition's `source_config`.
     */
    public function __construct(
        public string $eventKey,
        public string $q,
        public SearchRegion $region,
        public ?string $gl,
        public string $hl,
        public string $tbs,
        public int $num,
        public array $ignoreDomains = [],
        public SignalSourceKind $kind = SignalSourceKind::News,
        public array $config = [],
    ) {}
}
