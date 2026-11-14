# AI relevance plan — Background Check → ICP → lead search

> Status: **PROPOSED — awaiting approval. Nothing is implemented.** · Written 2026-11-14
>
> Goal: the leads a discovery run returns should fit the business that asked for them. Relevance is
> the only success measure; every phase below reports precision@N, competitor leak rate and cost per
> run against a baseline, and a phase that does not move the number is questioned before it ships.

## 1. What I found

Each claim was checked in code; the file is the evidence.

### 1.1 Where context is lost

| #   | Loss                                                                                                                                                                                                                                                                                                                          | Evidence                                                                              |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| 1   | **The background check reads one page, capped at 12,000 characters, and is told nothing the team typed.** The prompt receives `business_name`, `website` and the homepage text. `products_services`, `customer_examples`, `regions_served` and pricing, which the team entered minutes earlier in onboarding, are not passed. | `BusinessIntelligence/Jobs/RunBackgroundCheckJob.php:117-128`, `config/web-fetch.php` |
| 2   | **The background check does not honour `robots.txt`.** `RobotsPolicy` has exactly one caller, Signals' article enricher. `lead-sourcing.md` (L0 §17) says the shared fetcher honours it; it does not. Harmless-ish for one homepage, not acceptable for a crawl.                                                              | `grep RobotsPolicy app/` → `Signals/Services/SignalArticleEnricher.php` only          |
| 3   | **Findings have no evidence and one global confidence.** The v2 schema has a single `confidence` number and no page or quote per finding. Only `summary`, `offering`, `positioning` are required, so the customer-side fields are ones the model may decline to write (the `icp.compose_profile` v1 lesson).                  | `AiPromptTemplateSeeder.php:104-143`                                                  |
| 4   | **Accepting the check writes two blank profile fields and nothing else.** `competitors`, `target_customers`, `customer_industries`, `decision_maker_titles` never reach the profile. They survive only if the member ticks "seed an ICP", and then only as a **non-active** ICP.                                              | `AcceptBackgroundCheckAction.php:87-112`                                              |
| 5   | **Findings never reach lead search directly.** Nothing in `LeadSourcing` reads `business_intel_reports`. A team that accepts the check but keeps its own ICP searches with none of what the check learned (competitor names above all).                                                                                       | no reference to `BusinessIntelReport` under `app/Modules/LeadSourcing`                |
| 6   | **The ICP composer sees four profile fields, squashed to 500 characters.** Name, industry, value proposition, products. Not `customer_examples`, regions, size, pricing, and no background-check findings.                                                                                                                    | `ICP/Actions/ComposeIcpAction.php:144-162`                                            |
| 7   | **The query shaper sees more, but not everything.** It gets `customer_examples`, locations and regions. It does not get `company_size` or pricing, and no findings.                                                                                                                                                           | `LeadSourcing/Services/DiscoveryPayloadBuilder.php:45-55`                             |
| 8   | **`pricing_*` and `company_size` reach no prospecting step at all.** The only readers outside onboarding are Outreach's payload builder and the BI review controller. A £50k-minimum consultancy and a £500 one get the same search.                                                                                          | `grep pricing_min\|company_size app/`                                                 |
| 9   | **The shaper is called once per source and emits one `terms` string each.** A three-source run is three reasoning-tier calls that do not share a cache key (the provider brief differs), each producing one query.                                                                                                            | `StartDiscoveryRunAction.php:77-86`, `DiscoveryQueryShaper.php:66-73`                 |
| 10  | **Web search makes one request.** One query, `num` ≤ 100, title → company name.                                                                                                                                                                                                                                               | `WebSearchProvider.php:52-69`                                                         |
| 11  | **AI discovery is recall, and is given less than the shaper was.** It receives the shaped `terms`, a one-line `customer_description`, location and exclusions. Not the business profile, not the ICP, not the proof customers. The prompt says "You have no search tool here".                                                | `AiLeadSourceProvider.php:71-81`, `AiPromptTemplateSeeder.php:501-551`                |
| 12  | **Companies House is a name search with no status filter.** `/search/companies?q=<trade word>`; `company_status` is stored and never filtered.                                                                                                                                                                                | `CompaniesHouseProvider.php:49-52`                                                    |
| 13  | **Nothing judges a candidate against the business before it becomes a lead.** `LeadCandidateFilter` is word/substring exclusion only. A competitor whose name does not contain the excluded word is stored, enriched (paid) and scored.                                                                                       | `LeadCandidateFilter.php`, `DiscoverLeadsJob.php:126`                                 |
| 14  | **Scoring's AI half never sees the seller.** `scoring.qualitative_fit` gets the ICP block and the lead block. It judges "does this lead match these criteria", never "would this company buy from this business".                                                                                                             | `Scoring/Services/ScoringPayloadBuilder.php`                                          |
| 15  | **Feedback is write-only.** `score_feedback` (good/bad) has a writer, two resources and no reader. `icp.refine_suggestions` v1 is seeded with no consumer. Calibration is displayed and nothing acts on it.                                                                                                                   | `grep ScoreFeedback app/`, `grep refine_suggestions app/` → nothing                   |

### 1.2 Where the brief is wrong or I would push back

1. **`customer_examples` is not unused.** It reaches the query shaper today (row 7). It is unused by the composer, the AI discovery prompt and scoring. Pricing and company size are the ones that are truly dead (row 8).
2. **"One shaped query shared across providers" is the wrong fix as worded.** Each provider genuinely needs different phrasing (a register wants a trade word, Places wants a category). The saving is **one call that returns every provider's queries**, not one query. Same cost win, no relevance loss.
3. **Grounded AI discovery and the web-search provider converge.** Once the model reads Serper results and may only name companies that trace to one, the "AI" source is "web search, read by a model". I propose making that explicit (the AI source runs the angle queries and extracts from them) rather than building a second Serper path. Its `costMicros()` can then no longer be 0: the Serper spend must be metered on the run.
4. **A normalised criterion vocabulary (workstream B, last bullet) is the highest-risk, lowest-evidence item here.** It restructures `icp_criteria`, the editor, the composer, the scorer and every saved ICP. `scoring.qualitative_fit` v2's deferred block already covers vocabulary gaps by meaning. I recommend **not** doing it in this plan and revisiting only if the eval shows vocabulary mismatch is a leading cause of bad leads after P2.
5. **Order.** The brief lists A → B → C. I propose the reverse emphasis: the search end first. A richer background check improves nothing until something downstream reads it, and today nothing does (rows 4, 5). Fixing the consumers first also means the existing, already-typed profile starts paying off immediately.
6. **The eval cannot be only hand-labelled companies.** Live search returns companies nobody labelled. See §2.

## 2. The measuring stick (P0)

### Design

- **Fixtures**: `tests/Fixtures/relevance/*.json`, six businesses. Four from the brief (UK design studio; UAE B2B SaaS; local trades firm; need-based agency with no vertical) plus two that stress known failures: a business that **does** sell to its own trade (must not be over-excluded), and a UK firm with a high price floor (tests size/price fit). Each fixture holds a full business profile, the homepage text for a faked background check, and **hand-labelled companies**: ≥10 good fit, ≥10 bad fit, ≥5 competitor, each with a one-line reason. Each also holds a **ground-truth buyer description** written by hand that the pipeline never sees.
- **Harness**: `php artisan relevance:eval {fixture?} {--live} {--record} {--n=20}`. Not a test; never part of `composer test`.
    - Runs profile → background check → ICP (seeded and composed) → shaping → providers → filters, calling the real Actions and services. **It persists no leads**: it stops at the candidate list, so nothing lands in the workspace, nothing is enriched, no lead allowance is spent.
    - **Replay mode (default)**: provider and AI responses come from cassettes recorded by `--live --record`. Deterministic, free, and what I rerun during development.
    - **Live mode**: real Serper / Companies House / Places / Anthropic. Run once per phase for the reported numbers.
- **Scoring a run** — two measures, reported separately because they fail differently:
    1. **Labelled-set discrimination** (no judge involved): feed the hand-labelled companies through every filter/gate stage as candidates. Reports competitor leak rate (competitors that survive) and good-fit loss rate (good fits wrongly dropped). Exact, cheap, and the only number that is not an AI opinion.
    2. **Live precision@N**: the top N candidates per source are graded good / bad / competitor by a judge prompt (`eval.judge_candidate`, reasoning tier, registered and versioned like any other) that is given the hidden ground-truth description. The judge is itself validated on the labelled set first; the harness prints judge agreement and refuses to report precision if agreement is under 85%.
- **Output**: a Markdown table per fixture and overall — precision@10, precision@20, competitor leak rate, good-fit loss, candidates returned, provider cost, AI cost — appended to `docs/modules/lead-sourcing.md` → _Relevance evaluation_ with the commit hash.

### Things you need to decide for P0

- **Where the eval's team lives.** `AiClient` bills the ambient team, so the harness needs a real `teams` row. I propose one clearly named team (`relevance-eval`) created additively in the dev database by the command, holding only profiles/ICPs/reports for the fixtures. The alternative is a separate SQLite file via a dedicated connection. I recommend the named team: it exercises MySQL and the real seams.
- **A live-run budget.** One full live pass is roughly 6 fixtures × (≈6 reasoning calls + ≈8 Serper queries + Places + judge calls). My estimate is **$3–6 per full live pass at baseline, $5–10 after P2**; I will measure rather than trust that. I would run about one live pass per phase.
- **Who labels.** I can draft the labelled companies from public knowledge and you correct them, or you supply them for the profiles you know. Labels I invent are the weakest part of the stick.

## 3. Phases

Ordered by relevance gain per unit of risk. Gains are hypotheses the eval confirms or kills.

### P0 — Eval harness and baseline

Section 2. No behaviour change. **Risk: low. Cost: eval spend only.** Deliverable: baseline numbers in the docs.

### P1 — Use what is already known (no new tables)

Fixes rows 6–9, 11, 12, 14. All prompt changes are new version rows.

- One `DiscoveryPayloadBuilder::businessBlock()` that carries the **whole** profile (adds company size, price band, formatted in major units) and, when an accepted report exists, the customer-side findings and competitor names. Findings reach Lead Sourcing through a new **LeadSourcing-owned contract** (`SellerContextSource`, null default, bound by Business Intelligence), not an import. The composer gets the same context through an ICP-owned equivalent.
- `lead_sourcing.shape_query` **v2**: one call per run, returning queries for every selected provider. Three reasoning calls become one.
- `lead_sourcing.ai_discovery` **v2** (interim, still recall): given the business and ICP blocks, not just a terms string.
- `icp.compose_profile` **v3**: full profile + findings; told to use proof customers and price band to set size criteria.
- `scoring.qualitative_fit` **v3**: add a short seller block so the AI half can judge "would they buy from this business" and say so in the reason.
- **Companies House**: switch to `/advanced-search/companies` with `sic_codes` + `company_status=active` when the shaper supplies SIC codes; fall back to today's name search otherwise. SIC codes the model returns are validated against a shipped SIC 2007 list before use (the "constraint lives in code" rule). I will confirm the endpoint's parameters against the live API docs before building, not from memory.
- Dissolved/liquidated companies dropped at mapping for both search paths.

**Expected gain:** moderate on precision (better-aimed queries, size/price fit), large on Companies House (currently close to noise). **Cost:** AI cost per multi-source run goes **down** (3 shaping calls → 1). **Risk:** low; every change degrades to current behaviour. **ADR:** none; a paragraph in ADR 0008's module doc for the SIC search.

### P2 — Grounded, multi-angle search with a relevance gate

Fixes rows 10, 11, 13. This is where I expect most of the gain, and it is the biggest change.

- **Angles.** `shape_query` v2's output grows to a small list of web queries (default 3, hard cap 5): best vertical × place, a problem/trigger angle, and a lookalike angle built from `customer_examples` (and proof customers once P3 lands). `DiscoveryQuery` gains `queries: list<string>`, round-tripped through `params`; `terms` stays as the first for backward compatibility.
- **Web search** runs each angle, merges by domain, and remembers which angle found each candidate.
- **Grounded AI discovery** (`ai_discovery` **v3**): the provider runs the angle queries through Serper, hands the numbered results (title, snippet, URL) to the model wrapped as `UntrustedContent`, and requires a `source_index` per company. **Code, not the prompt, enforces it:** a company whose index was not offered, or whose stated domain does not match the cited result's host, is dropped and counted. Recall-only naming is removed. Serper cost is metered on the run.
- **Relevance gate.** A new `CandidateRelevanceJudge` in Lead Sourcing, called in `DiscoverLeadsJob` after `LeadCandidateFilter` and before persistence. One **cheap-tier**, batched call (`lead_sourcing.judge_candidates` v1, ≤25 candidates per call, ≤2 calls per run) returning per candidate: verdict (`fit` / `unclear` / `not_fit` / `competitor`), confidence, one-line reason. Only `not_fit` and `competitor` above a confidence floor are dropped; `unclear` is kept. On any AI failure, exhausted credit or an over-budget run, the gate **passes everything through** and the run records that it ran ungated. It never fails a run.
- **The reason is stored through the existing evidence model**, not a new score: a kept candidate gets a `lead_evidence` row (`EvidenceKind::AiInference`, source `relevance_gate`, the reason as summary) via `RecordLeadEvidenceAction`, so "why this lead matches" appears in the panel that already exists. Rejected candidates are not stored as leads; the run gains `leads_rejected` and a small per-run rejection sample (company name + verdict + reason, no personal data) so a member can see what was left out and why.
- **Spend.** Per-run ceilings in `config/lead-sourcing.php` (max angles, max Serper queries, max gate calls). Serper spend reserved before the fan-out and recorded after, following the ADR 0012 reserve-then-settle shape. **Open question for you:** paid discovery searches are not a plan metric today. Either they stay a config ceiling (cheap, my recommendation for now) or they become a quota seam like `SignalQuotaSource`.

**Expected gain:** large — competitor leak should fall sharply (the gate is the first thing that can recognise a competitor by what it does rather than what it is called), and fabricated AI leads go to zero by construction. **Cost:** up. Per run roughly +2–4 Serper queries (~$0.003 each at the configured rate) and +1–2 cheap-tier calls; partly offset by not enriching rejected candidates, which is the expensive step. Measured in the eval. **Risk:** medium — the gate can drop good leads; the labelled good-fit loss rate is the guard, and the confidence floor is config. **ADR 0035:** grounded discovery and the relevance gate (what the gate may drop, why it degrades open, why relevance is evidence and not a second score, why a named company must trace to a result).

### P3 — A background check that understands the business

Fixes rows 1–4.

- **Bounded crawl.** A Core `SiteCrawler` over the existing fetcher: homepage + up to N same-host pages (default 6) chosen by link text/path (services, about, case studies, clients, industries, pricing), total character and wall-clock budgets, `RobotsPolicy::allows()` before every request, `SafeUrl` on every URL including redirects, each page wrapped as `UntrustedContent` with its URL. The fetcher currently returns text only, so link discovery needs one addition to the `WebContentFetcher` contract; I will keep it to that one method. The job's 85 s timeout forces the crawl budget to stay small or the job to checkpoint; I will size it in the phase.
- **Optional web-search pass** for public context: off by default behind config, one or two Serper queries. I would ship the crawl first and only build this if the eval shows the crawl leaves a gap.
- `business_intel.background_check` **v3**: also given the team's own typed profile (marked as the team's words). New findings: problems solved, proof customers (company names only — no individuals, ADR 0025), price band / deal size, delivery model (local / regional / remote), customer size range, buying triggers, anti-customers. **Every finding carries a confidence and evidence (page URL + short quote).** Code verifies each quote actually appears in the fetched text of the cited page and drops evidence that does not; customer-side fields become required-but-nullable.
- **Accept maps properly.** New nullable `business_profiles` columns (problems solved, delivery model, customer size range, buying triggers, anti-customers; proof customers merge into `customer_examples`). Blank fields are filled; where the team already has a value and the finding differs, the review screen shows **both** and the member chooses per field. Nothing is overwritten without that choice.
- **UI:** review screen shows evidence and confidence per finding and the conflict chooser. Through the `ui-design` skill, then `brand-reviewer` and `ux-reviewer`; EN/AR + RTL; four states.
- P1's context blocks pick up the new fields, so the shaper, composer, gate and scorer all benefit without further change. The lookalike angle now has real proof customers.

**Expected gain:** moderate overall, large for teams with thin profiles (which is most new teams). Measured as the delta when the eval's fixtures start from website-only. **Cost:** one-off per check: up to 6 fetches and a larger reasoning prompt (roughly 3–5× the current check's input tokens). **Risk:** medium — crawl safety, and a longer job. **ADR 0036:** the crawl's bounds and robots position; evidence-carrying findings; "conflict is shown, never overwritten".

### P4 — An ICP that sharpens with use

Fixes row 15 and the `icp.md` own-trade hole.

- **Own-trade warning.** On save and in the editor, an industry signal that matches the profile's own industry (via the existing `SectorTaxonomy`, plus the findings' `own_industry`) shows a warning with "we do sell to our own trade" as a dismissible, remembered answer. Warns, never blocks.
- **Seeding** from the background check uses the P3 findings: size from customer size range, geography from delivery model, buying signals from triggers, anti-customers as exclusions.
- **`icp.refine_suggestions` v2 gets a consumer.** A `SuggestIcpRefinementsAction` fed by the profile **and** evidence: per-criterion calibration, `score_feedback` good/bad, relevance-gate rejections, and which leads were progressed past the default stage or dismissed. ICP reads that evidence through an ICP-owned contract (extending the `IcpCalibrationSource` pattern) so ICP still never imports Scoring or Leads Workspace. Inputs are counts and criterion values, not lead names.
- **Proposes, never applies** (ADR 0020, S12 precedent): new `icp_suggestions` table (`team_id`, `BelongsToTeam`, cross-tenant test), each with a reason; accepting is an ordinary audited `UpdateIcpAction` edit; a decline is kept so it is not raised twice. Triggered by a member, throttled; no schedule in this plan.
- **UI:** suggestions panel on the ICP index beside calibration. Same skill and reviewers.

**Expected gain:** small at first, compounding; it only matters once a team has feedback. The eval measures it by replaying synthetic feedback from the labelled set and checking precision rises after accepting suggestions. If it does not, I will say so. **Cost:** one reasoning call per request. **Risk:** low (nothing changes without a click). **ADR 0037** if the evidence seam's shape warrants one; otherwise a section in `icp.md`.

### Deferred, with reasons

- **Normalised criterion vocabulary** — §1.2 item 4.
- **Lookalikes from best-converted leads** as a discovery angle — needs conversion data real teams do not have yet; the proof-customer lookalike in P2/P3 covers the cold-start case. Revisit after P4.
- **Re-checking exclusions on CSV import / manual entry** — real, but outside "lead search"; flagged, not planned.
- **Scheduled background-check refresh / scheduled suggestions.**

## 4. Constraints I will hold to

Repo rules win. In particular: `composer test` only and Herd binaries; `php artisan migrate` only, new migrations, never the destructive commands; no cross-module imports around a contract (three new seams above, each owned by the consumer with a null default); Actions in `DB::transaction()` with external calls outside; every AI call through `AiClient` with a new **version row**, schema-validated, `UntrustedContent` around anything fetched or typed; no named individual stored by a provider; suppression and `LeadInterestGate` untouched; logs carry IDs and counts, never page text; unit and feature tests fake every provider; branch off `main`; commit only when you ask. Each phase closes with the `phase-complete` skill and `laravel-module-reviewer`, plus `brand-reviewer` / `ux-reviewer` for P3 and P4.

## 5. Decisions I need from you

1. **Approve the order** P0 → P1 → P2 → P3 → P4 (search end first), or tell me to follow A → B → C.
2. **Eval team in the dev MySQL database** (`relevance-eval`, additive) — yes, or a separate SQLite file?
3. **Live eval budget**: is about $10 per live pass, one pass per phase, acceptable? Confirm the Serper, Companies House, Places and Anthropic keys in `.env` may be used for it.
4. **Fixture labels**: shall I draft them for you to correct, or will you supply companies for some profiles?
5. **Paid discovery searches**: config ceiling only for now, or a plan quota seam in P2?
6. **Skip the normalised vocabulary** unless the eval asks for it?
7. **Branch**: this session is on `ui-overhaul` with uncommitted graph changes. I will branch `ai-relevance` off `main` when you approve; say if you want a different base.

## 6. Progress

- **P0 — harness built, baseline not yet recorded** (2026-11-14). Three departures from §2, each on
  purpose: no record/replay mode (a cassette keyed on prompt input misses the moment a prompt
  changes, which is every phase); the fixture companies are **fictional** with snippet-style
  `about` lines, not real firms; and the baseline measures the **seeded** ICP path only, not the
  composer. Found and fixed on the way: the ICP composer was refusing every draft
  (`DraftIcp::fromAi()` read the wrong validator), which was failing two tests on `main`.
- **P1 — done** (2026-11-14). The seller description reaches every prompt; one shaping call;
  Companies House by SIC code. Precision did not move on its own (62% → 57%); see the eval
  section in `docs/modules/lead-sourcing.md` for why that first measurement overstated P1's cost.
  Departure from §3: the profile, not a seam into Business Intelligence, carries the findings.
- **P2 — done** (2026-11-14). Query angles, grounded AI discovery, the relevance gate (ADR 0035).
  **62% → 88% good fit and competitor leak 86% → 0% on that run** (83% and 8% on the finished code; cost figure corrected below).
- **P3 — done** (2026-11-14). `SiteReader`, evidence-carrying findings, `ProfileMapping` and the
  review screen (ADR 0036). Measured from a name-and-website-only profile: **73% → 81% good fit,
  leak 11% → 6%**, about a cent more per check. The web-search pass for public context was not
  built; the site alone was measured first, as planned.
- **P4 — done** (2026-11-14). Own-trade warning in the editor, richer ICP seeding, and
  `icp.refine_suggestions` v2 with a consumer that proposes and never applies. **Not measured by
  the eval** — a suggestion changes nothing until a member accepts it. Departures from §3: the
  warning has no "we sell to our own trade" remembered answer (it is a passive note, so there is
  nothing to dismiss), suggestions do not yet read lead stage changes, and the evidence reaches
  ICP through one more method on the existing `IcpCalibrationSource` rather than a new contract.
- **Not done, and still worth doing:** make the nine profile fields the check fills editable in
  the onboarding wizard; a normalised criterion vocabulary (deferred — nothing in the eval pointed
  at it); lookalikes from converted leads; re-checking exclusions on CSV import.

### Final measurement (2026-11-14)

On the finished code, full typed profile: **83% good fit (baseline 62%), competitor leak 8%
(baseline 86%), good-fit loss 3%**. From a name and a website only: 81%. A four-source discovery
run costs about $0.13 (baseline $0.11) and a background check about $0.04 (baseline $0.01) — the
plan's expectation that search would get cheaper was wrong. Every figure is a single live run;
between runs one fixture alone moved the total by five points. The full tables and their caveats
are in `docs/modules/lead-sourcing.md` → _Relevance evaluation_.

Total spent on evaluation across the whole plan: nine live passes, roughly $10.
