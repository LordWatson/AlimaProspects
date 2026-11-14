# Signals v3 — plan

Status: **awaiting approval.** Nothing below is built. Branch: `ai-relevance` (as instructed).

Goal: the share of signals in the feed that are a real, recent event, about a company that fits the
seller, and not a duplicate. Everything is judged against that number.

## 1. What exists today (recon, 2026-11-14)

| Area              | What is there                                                                                                                                                                           | What is missing                                                                                                                                                                                                      |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Seller context    | `RunSignalWatchAction::profileSummary()` and `ComposeSignalDefinitionAction::profileSummary()` — two private copies of a one-liner: company name, industry, value proposition, products | Neither uses `SellerBrief`. None of the nine seller-context fields (`buying_triggers`, `problems_solved`, `anti_customers`, `competitors`, `customer_industries`…) reaches the classifier or the composer            |
| Fit to the seller | `relevance` (0–100) from `signals.extract_event` v2, and the `IcpFit` score component via `SignalIcpFit` → `DeterministicScorer`                                                        | `IcpFit` builds a lead from a signal, which has no industry, size or technology, so most criteria come back Unknown and the component abstains. Nothing judges "is this subject a customer, a competitor or neither" |
| Drafting a type   | `signals.compose_definition` v1 from a **typed brief**; `PreviewSignalDefinitionAction` with its `signal_previews` ledger and daily budget; platform presets and packs                  | No draft without a brief. Nothing proposes types from the profile and ICP                                                                                                                                            |
| Queries           | `SignalQueryBuilder`: templates × places × regions, plus extra queries from ICP `BuyingSignal` criteria; ceilings in `config/signals.php`                                               | Customer industries and the profile's triggers do not shape queries                                                                                                                                                  |
| Sources           | Five, all available: `news`, `web_search` (Serper), `companies_house`, `feed`, `webhook`, behind `SignalSourceRegistry`                                                                 | No model-driven source                                                                                                                                                                                               |
| Accounts          | Watchlists, `SyncPipelineWatchlistAction` (a watch built from the team's own leads already exists)                                                                                      | —                                                                                                                                                                                                                    |
| "Why" on a card   | Stored score breakdown with eight components (ADR 0022)                                                                                                                                 | No sentence saying why this event matters to _this_ seller                                                                                                                                                           |
| Article text      | `SignalArticleEnricher`, off by default, five per run, stores no body                                                                                                                   | —                                                                                                                                                                                                                    |
| Anthropic tools   | `laravel/ai` 1.3 has `Providers\Tools\WebSearch` and `WebFetch`; its Anthropic gateway parses result URLs into `Meta->citations`                                                        | Our `AiTransport` / `RegisteredPromptAgent` pass no tools; `AiTransportResult` carries no citations and no search count; `ai_requests` cannot record a per-search fee                                                |
| Measurement       | Nothing. Spike tables in the module doc are one-off                                                                                                                                     | No `signals:eval`                                                                                                                                                                                                    |

Two things worth knowing before the design:

- `laravel/ai` maps web search to the **basic** tool variant (`web_search_20250305`). The current
  variant for Sonnet 5.5 is `web_search_20260209` (it filters results before they reach the
  context, so fewer tokens). The basic one works; the cost per call will be higher than it could be.
- `config/ai-platform.php`'s price comments (Sonnet ~$3/$15, Haiku ~$1/$5) do not match current
  list prices (Sonnet 5.5 $2/$10, Haiku 5.5 $0.10/$0.50). If the numbers beside them are also old,
  every AI cost this app reports, including the last plan's, is overstated. P0 checks this first,
  because the eval reports cost.

## 2. The measuring stick (P0)

`php artisan signals:eval {fixture?} {--n=} ` on the pattern of `relevance:eval`: live, paid, asks
for confirmation, refuses in production, never in `composer test`, writes
`storage/app/signals-eval/<timestamp>.json`.

Two measurements per fixture business (reuse the six relevance fixtures' profiles and ICPs; add a
`signals` block to each):

1. **Labelled set, through the real pipeline.** About 30 written articles per fixture fed through
   `FakeSignalSource` into the real classify → floor → dedupe → cluster → score path. Labels:
   `good` (real event, fitting company), `wrong_company` (real event, misfit or competitor),
   `not_event`, `stale`, `duplicate` (a reprint of a `good` one). Reports: leak per bad label,
   good-article loss, duplicate rate after clustering. Deterministic input, so this is the number
   that can be compared run to run.
2. **Live, judged.** The fixture's types run against live sources; each signal that reaches the
   feed is judged by a reasoning-tier prompt (`signals.eval_judge`) on three questions: real
   event, recent, subject fits the seller. Reports precision per source and cost split into
   search, AI and Claude web search. Judge agreement with the labelled set is reported, as before.

Nothing is persisted outside an eval team (same guard as `RelevanceEvaluator`: refuses a team with
members).

Trust: single live runs moved the last plan's totals by five points. Each headline figure will be
stated with how many runs it rests on, and a phase's delta is only claimed on the labelled set
unless the live figure moves by more than that noise.

## 3. Phases

### P1 — the classifier knows the seller, and judges fit

One change to one call; no new AI call per run.

- `SellerBrief` replaces both private `profileSummary()` copies.
- `signals.extract_event` **v3**: adds per item `subject_fit` (`fit` / `unknown` / `not_fit` /
  `competitor`), `fit_confidence`, and `why_it_matters` (one sentence, from the item and the
  seller brief only).
- A confident `not_fit` or `competitor` is dropped and remembered in `SignalRejectionLedger` under
  a new verdict, with the count on the run. `unknown` passes. An AI failure passes everything
  (degrade open), as the relevance gate does.
- A ninth `SignalScoreComponent`, `SellerFit`, stored in `score_breakdown`. `unknown` is left out
  of both sides of the average. `IcpFit` stays as it is.
- The card shows `why_it_matters` beside the article link, never without it.
- UI: one line on the card, one count on the run. Brand and UX review.

### P2 — "Draft me a signal"

- `signals.propose_definitions` v1 (reasoning tier): seller brief + active ICP + the team's
  existing types + the preset catalogue's names → up to five proposals. Each is either a **preset
  key** (offered as a clone) or a draft in the composer's own shape, validated by the save path's
  rules. Refusal is a valid answer. It saves nothing.
- Each proposal is previewed through `PreviewSignalDefinitionAction`, inside the existing daily
  preview budget; the proposals that fit the remaining budget are previewed, the rest are shown
  as "not yet tested". Ranked by what the preview found; a proposal that found nothing is shown
  last and marked.
- UI on the signal library / builder: "Suggest signals for my business", a list, and per row
  "Use this". `ui-design` first, then both reviewers.
- Sub-options, with my recommendation:
    - **One-step "set this up for me"** (save the ticked types and create one watch): **yes**, as
      the confirm step of the same screen, through the existing save Actions.
    - **Drafting from won or good-fit leads:** **not now.** It needs enough won leads with dated
      trigger evidence to say anything, and no team has that yet. Revisit when the eval has a
      fixture for it.
    - **An account watch from the team's leads:** **already exists** (`SyncPipelineWatchlistAction`).
      Offer it on the same screen; build nothing.

### P3 — search fixes, chosen by the baseline

Candidates, to be ranked by the P0 numbers before any is built. I expect to build the top two.

| Candidate                                                                                                               | Cost per run                                    | Expected effect                         |
| ----------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- | --------------------------------------- |
| Customer industries as a query qualifier for topical watches                                                            | none (same query count)                         | Higher live precision, some recall loss |
| Stale and reprint guard: drop an item whose own date is outside the recency window; treat a reprint as a cluster member | none                                            | Lower stale and duplicate rate          |
| Profile `buying_triggers` as extra queries, as ICP buying signals already are                                           | more Serper queries, inside the per-run ceiling | Recall                                  |
| Article fetch on by default for the top few                                                                             | already priced                                  | Better fit judgement on thin snippets   |

### P4 — Claude web search as a source

- **`AiClient` first.** Add `AiClient::research(string $key, array $input, WebSearchOptions)`
  returning the structured answer plus the URLs the tool returned and the number of searches.
  Same registry, versioning, credit guard, `ai_requests` row and `FakeAiTransport`; the row gains
  a search count and the cost includes the per-search fee. `LaravelAiTransport` stays the only
  file that touches the SDK.
- **Spike before building** (about $1): does `laravel/ai` allow a JSON schema and a provider tool
  in one call on Anthropic, and can we read the search count? If not: two calls (search with
  citations, then a cheap structuring call), and searches billed at `max_uses` when the count is
  unreadable.
- `SignalSourceKind::AiResearch`, its own `SignalSourceProvider`, breaker key under `signals:cb`,
  `PlatformProvider` case. **Definition-addressed:** one call per type per region, because the
  model writes its own searches; multiplying by template and place would multiply the most
  expensive source.
- **Grounded:** an item is kept only if its URL is one the tool returned in that call; the stored
  URL is the tool's. Items then go through the ordinary pipeline, including P1's fit judgement.
  The source asserts no score, jurisdiction or lead.
- **Bounded:** `max_uses` 3 per call, a per-run call ceiling, off by default
  (`SIGNALS_AI_RESEARCH_ENABLED`), spend through `SignalSpendGuard`.
- **Default mode is gap-filling:** it runs for a type only when that type's other sources returned
  fewer than a configured number of items in this run. This is my recommendation for the third
  sub-option, and it is what keeps the cost proportionate.
- Other sub-options:
    - **Deeper pass on watchlist accounts:** **yes, as P5, only if P4's numbers justify it.** One
      call per account, through the existing entity cursor.
    - **Second opinion before a playbook acts:** **no.** A playbook only drafts for review, never
      sends; a person already reads it. Spend the money on finding signals.
- Estimated cost: $0.01 per search plus tokens. Search results are input tokens, so I expect
  $0.05–0.15 per call on Sonnet 5.5. That is 50–150 times a Serper query. The eval will give the
  real figure.

### P5 (optional) — account research

Only if approved after P4's numbers.

## 4. Decisions I need

1. **Eval spend ceiling.** Estimate: P0 $2–3, P1–P3 about $2 each, P4 $5–8. I propose a hard cap
   of **$25** for the whole plan; I stop and ask if it would be passed.
2. **Dropping on fit (P1).** Drop confident misfits and competitors (recommended, with the count
   visible on the run), or only rank them lower?
3. **How Claude searches are limited by plan (P4).** They cost far more than a Serper query, so
   counting them in the same monthly `signal_queries` allowance misprices them. I recommend a
   separate monthly figure on `SignalQuotaSource` (calls per month, absent = zero, so no plan gets
   it by accident). The alternative is one shared allowance.
4. **Model for the research call.** Reasoning tier (Sonnet 5.5) recommended; Haiku 5.5 would be
   much cheaper and the eval can compare both in one extra pass if you want.
5. **Fixture articles are written by me** about fictional companies, as the relevance fixtures
   were. The live half uses real news.
6. **The relevance work is still uncommitted on this branch** (about 96 files). I recommend
   committing it before P0 so the two plans' commits do not mix. Your call; I commit only when
   asked.

## 5. Rules that bind every phase

`composer test` green, `composer analyse` clean, frontend gates pass, `laravel-module-reviewer` on
every phase, `ui-design` then `brand-reviewer` and `ux-reviewer` on UI. New prompt versions are new
rows. An ADR for P1's fit judgement, P2's proposer and P4's source. Docs, `docs/ai-prompts.md`,
`CHANGELOG.md` and the eval numbers updated per phase. No commit without being asked. ADR 0011's
personal-data limit stands; no article body is stored.

## 6. Progress

- **P0 — done 2026-11-14 (uncommitted).** `signals:eval`, `SignalEvaluator`, `signals.eval_judge`,
  four fixtures with twenty labelled articles each (not six with thirty: the fake source serves one
  page of twenty, and four businesses cover both markets). Gates: 3,139 passed, 5 skipped; PHPStan
  clean. Baseline in `docs/modules/signals.md` → _Signal evaluation_: **0 of 28 good articles
  reach the feed; 27 of 47 classifier calls fail at the 2,048-token limit.** The AI price table
  was wrong and is corrected. Eval spend so far: about $0.25.
- **Change to P1 because of the baseline:** P1 starts with the classifier's token limit and
  reasoning effort (a new `signals.extract_event` version), measured alone, before fit is added.
  Without that there is no working pipeline to measure fit on.
- **P1 — done 2026-11-14 (uncommitted).** `signals.extract_event` v3 and v4, the fit verdict,
  `not_fit` in the rejection ledger and on the run, `SellerFit` in the score, the card line and
  the preview verdict. ADR 0037. Labelled set: good shown 0 → 23 of 28; competitors and misfits
  shown 0 of 24. Live: 5 cards from 235 results, 1 good, so the searches are now the limit.
  Eval spend so far: about $0.80.
- **P2 — done 2026-11-14 (uncommitted).** Suggested signal types (ADR 0038). Live: 39 cards, 20 of
  27 judged good, against 5 cards and 1 good for the library types. Not built: the one-step "add
  these and start watching"; drafting from won leads.
- **P3 — done 2026-11-14 (uncommitted).** The place rule and the party rule (ADR 0040). Chosen by
  the numbers: the place rule was the whole of P1's remaining loss and was also why sample
  searches reported nothing. Reprints on their own card 2 → 0 of 8. Not built: query shaping from
  customer industries and article fetch by default; the suggested types do the first job better.
- **P4 — done 2026-11-14 (uncommitted).** Claude's web search as a source (ADR 0039), off by
  default. 20 calls, $1.67, three cards, none judged good. Not recommended for switching on.
- **P5 — not built.** Account research rests on P4's yield, which does not justify it.

- **For P4 (noted at P0):** `laravel/ai` sends native structured output (`output_config.format`) together with
  provider tools under `tool_choice: auto`, so one call can search and return a schema. The spike
  is reduced to reading the search count.

### Final measurement (2026-11-14)

|                                         | Baseline            | Finished                                   |
| --------------------------------------- | ------------------- | ------------------------------------------ |
| Labelled: good events on the feed       | 0 / 28              | 24 / 28 (23–26 across runs)                |
| Labelled: competitors and misfits shown | 1 / 24              | 0 / 24                                     |
| Labelled: reprints on their own card    | n/a (nothing shown) | 0 / 8                                      |
| Live: cards from four businesses        | 7, 3 good           | 39, 20 good of 27 judged (suggested types) |
| Classifier calls failing                | 27 of 47            | 0                                          |

Eval spend for the whole plan: about $3.85 against a cap of $25. Full tables and their caveats:
`docs/modules/signals.md` → _Signal evaluation_.
