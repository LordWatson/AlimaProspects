# ADR 0039 — Claude's web search is a signal source: grounded, gap-filling, and capped

- **Status:** Accepted
- **Date:** 2026-11-14
- **Phase:** P4 of `docs/plans/signals-v3-plan.md`
- **Supersedes / amends:** nothing. It adds a sixth entry to **ADR 0023**'s registry, applies
  **ADR 0035**'s "no recall path" to it, and extends **ADR 0005** (every AI call through the AI
  Platform) with a second contract for a call that reads the live web.

## Context

Every existing source takes a query we composed and returns what an index holds. Where a keyword
template finds nothing, the watch finds nothing. A model that can search can be given the
question instead: this kind of event, in these places, for this seller.

Three things make that dangerous to add carelessly. A model asked for companies will supply some
from memory. A search-and-answer call costs about a hundred times a news query. And it is slow
enough to matter inside a queued run.

## Decision

1. **A second AI contract, `AiResearcher`**, beside `AiClient` and implemented by the same class.
   A structured call is a pure function of its input: cached, priced by tokens. A research call
   is neither, so it is **never cached**, its `ai_requests` row includes the provider's
   per-search fee, and its answer carries the pages its searches returned. Prompt registry,
   versioning, untrusted-content wrapping, the credit guard and schema validation are the same
   code path. `LaravelAiTransport` remains the only file that touches the SDK.
2. **Grounded, in code.** `AiResearchSignalSource` keeps an event only if the canonical-URL hash
   of its link matches a page the search tool returned **in that call**, and stores the tool's
   own URL. If the SDK ever stops exposing the returned pages, every item is dropped: the failure
   is silence, never an unverified link.
3. **It asserts nothing.** Its items carry no classification. The run classifies them against the
   team's types, judges the company (ADR 0037), applies the floors, de-duplicates, clusters and
   resolves the jurisdiction, exactly as for news.
4. **Definition-addressed.** One call per signal type, never per template or place.
5. **Gap-filling, decided by the run.** Research queries are moved to the end of a run's plan and
   one is made only for a type whose other sources returned fewer than `min_results` (5) items,
   at most `max_calls_per_run` (1) times.
6. **Capped by its own monthly allowance, in money.** `SignalAllowance::aiResearchMicros` is the
   ninth figure a plan sets, read through `SignalSpendGuard::remainingAiResearchMicros()` and
   counted over `ai_requests` (a record over the ledger, ADR 0012). Unlike the article-fetch
   figure, **zero means none**, and the module's own floor stands when plans are not enforced.
7. **Off for the platform by default** (`SIGNALS_AI_RESEARCH_ENABLED`). While off, the source is
   not registered and not offered, which keeps ADR 0023's invariant that a registered source is
   an available one. When on, a type opts in by ticking it.
8. **It degrades and never fails a run.** A timeout, a refusal, a spent allowance or an unusable
   answer returns nothing and is logged. This departs from `SignalSourceProvider::fetch()`'s
   "must throw on a genuine failure", deliberately: a throw fails the watch run, the job retries,
   and the retry re-bills the searches already paid for. `SignalArticleEnricher` makes the same
   choice for the same reason.
9. **Its own breaker and its own operations row**: `signals_ai_research` under `signals:cb`,
   `PlatformProvider::SignalsAiResearch`.
10. **A preview never calls it.** A preview is a cheap sample of a type's templates; this is
    neither cheap nor a template.

## Consequences

- `max_searches` (2) is per step of a call and a call can take two steps, so a call runs at most
  four searches. The fee is recorded from the searches actually run.
- The provider refuses a request that asks it to localise to a country it does not support; the
  UAE is one. Those are searched with no location set, steered by the prompt alone.
- The SDK maps the search tool to the provider's basic variant, not the newer one that filters
  results before they reach the model. It works; each call carries more tokens than it could.
- A research call is bounded at 75 seconds so it fits inside the run job's window. With one call
  per run that leaves the rest of the run about ten seconds less than before.
- The monthly allowance is checked before a call against an estimate, with no reservation. Runs
  for several watches starting together can each pass the check, so a month can overshoot by
  about one call per watch. A call that times out is recorded at zero cost, so a search the
  provider ran before the timeout is missed. Both err in the team's favour and both are bounded
  by one call per run.
- A research query that is not attempted (allowance spent, breaker open) is recorded as a skip,
  not as a search, and research is left out of the paid-query count that meters the search
  allowance: it is metered once, in money.
- Grounding ties the **link** to a returned page. The summary beside it is the model's and is
  read by the classifier as untrusted text; the date is the model's reading of the page.
- Measured cost and yield are in `docs/modules/signals.md` → _Signal evaluation_.
- Evaluated and not built: a deeper pass on watchlist accounts (worth doing only if the yield
  here justifies it), and a second opinion before a playbook acts (a playbook only drafts for a
  person to review; the money is better spent finding signals).
