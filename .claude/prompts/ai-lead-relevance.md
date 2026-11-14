# Make the leads match the business — Background Check → ICP → AI lead search

## The goal

AlimaProspects finds prospects for a business. Today the leads it returns are too loosely related
to the business that asked for them. I want them as close a fit as possible.

The chain that decides relevance is:

1. **Business Profile + Background Check**: what Alima understands about the _seller_.
2. **ICP**: who the seller's _buyers_ are, as weighted criteria.
3. **Lead search** (query shaping + providers + AI discovery + filtering + scoring): turns 1 and 2
   into companies.

Each link loses context. A shallow background check gives a vague ICP, and a vague ICP gives a
broad search. Your job is to make each link carry and use more of the business's real context, so
that the leads at the end are measurably more relevant. **Relevance is the only success measure.**
More features, more prompts or more spend do not count unless the leads get better.

## Read first (in this order)

- `CLAUDE.md`: the golden rules, architecture rules and §5 decisions. They are binding. Note that
  you run the suite with `composer test` only, you must never run `migrate:fresh` or its siblings,
  and you use the Herd binaries.
- `docs/modules/business-intelligence.md`, `docs/modules/onboarding-business-profile.md`,
  `docs/modules/icp.md`, `docs/modules/lead-sourcing.md` (especially _Key flows_, _Known
  limitations_ and _Lead evidence and "why now"_), `docs/modules/scoring-matching.md`,
  `docs/modules/ai-platform.md`.
- `docs/ai-prompts.md`, in full. The history of `business_intel.background_check` v2,
  `icp.compose_profile` v2 and `lead_sourcing.shape_query` is the history of this exact problem: the
  **seller/buyer inversion**, where the seller's own trade leaked into the ICP and discovery found
  competitors. Do not reintroduce it.
- ADRs 0005 (AI behind `AiClient`), 0008, 0010, 0020 (an AI that drafts configuration drafts and
  never saves), 0025, 0028 (lead evidence and confidence).
- Code: `app/Modules/BusinessIntelligence/**`, `app/Modules/ICP/**`,
  `app/Modules/LeadSourcing/Services/{DiscoveryQueryShaper,DiscoveryPayloadBuilder,AiLeadSourceProvider,WebSearchProvider,LeadCandidateFilter}.php`,
  `app/Modules/LeadSourcing/Actions/StartDiscoveryRunAction.php`, `DiscoverLeadsJob`,
  `app/Modules/Scoring/**`, and `database/seeders/AiPromptTemplateSeeder.php`.
- Use `graphify query` / `graphify explain` before raw greps (see `CLAUDE.md` → graphify).

## What exists today (verify each point; don't trust my summary)

- **Background check**: reads **one page** (the homepage, through `WebContentFetcher`), makes one
  `business_intel.background_check` v2 call, and stores versioned findings: `own_industry`,
  `competitors`, `customer_industries`, `target_customers`, `decision_maker_titles`, offering,
  positioning. On accept it fills only **two blank** profile fields (industry, value proposition)
  and can seed a non-active ICP from the customer-side findings only.
- **Business Profile** holds `products_services`, `value_proposition`, `customer_examples`,
  `regions_served`, `locations`, pricing and `company_size`. Check which of these actually reach
  the ICP composer, the query shaper and the AI discovery prompt. I suspect several, especially
  `customer_examples` and pricing, are underused or unused.
- **ICP**: criteria are free-text strings by `CriterionType`. `icp.compose_profile` drafts from a
  sentence plus the profile. `icp.refine_suggestions` is seeded and **has no consumer**. Calibration
  is shown but nothing acts on it. `score_feedback` is captured and **nothing reads it**.
- **Lead search**: `lead_sourcing.shape_query` writes **one** query per provider. Web search makes
  one request. `lead_sourcing.ai_discovery` names companies **from model recall, with no
  grounding**. The docs call a web-search-grounded variant "the obvious next step, not built".
  Companies House search matches by **name**, not sector, and doesn't filter dissolved companies.
  Exclusions are word/substring matches. Scoring's sector taxonomy is a keyword map.

## What I want

Treat these as workstreams. **Propose the order and the scope yourself**, highest relevance gain
per unit of risk first, and challenge anything here that the code shows is wrong or low-value.

### A. A background check that actually understands the business

- Read more than the homepage: a bounded crawl of the pages that describe the business (services,
  about, case studies, clients, industries, pricing), and optionally a web-search pass for public
  context. Honour `robots.txt` (`Core\Support\RobotsPolicy`), reuse `WebContentFetcher` and
  `SafeUrl`, keep every byte/page/time bound, and wrap everything as `UntrustedContent`.
- Extract what a prospecting system needs and the current findings lack. For example: the
  problems the business solves; proof customers (named clients and case studies, which are the
  best lookalike seeds there are); deal size and price band; delivery model (local vs remote,
  which constrains geography); customer size range; buying triggers; anti-customers. Give each
  finding a **confidence** and the **evidence** (page and quote) that supports it, so the review
  screen can show why Alima believes it.
- On accept, map the richer findings onto the profile properly. "Blanks only, two fields" is too
  little, but the team's own entries must still win. A conflict is shown, never overwritten.
- New prompt **version** rows only. Never edit a prompt in place.

### B. An ICP that is built from that understanding and gets sharper with use

- The composer and the background-check seeding should both draw on the full profile and findings:
  proof customers, price band, delivery model and triggers, not just industries and titles.
- Give `icp.refine_suggestions` a real consumer. Suggestions should come from the profile **and**
  from evidence (calibration, `score_feedback`, which leads were converted or dismissed). Per ADR
  0020 and the S12 tuning advisor precedent, the system **proposes** and a member accepts; it never
  silently edits an ICP.
- Close the known hole in `docs/modules/icp.md`: an industry signal that names the team's own
  trade. Warn rather than block, because selling to your own trade is a real case.
- Consider whether criteria need to be more than free strings (e.g. a normalised sector/size
  vocabulary shared with Scoring) so that the ICP, the search and the scorer mean the same thing.

### C. Lead search that is grounded, targeted and checked

- **Ground the AI discovery.** Let the model reason over real search results (Serper) instead of
  recall, and require every company it names to trace back to a result it was given. A named
  company with no source is dropped, not stored.
- **Several targeted queries, not one broad one.** Derive distinct angles from the ICP and the
  findings (vertical × trigger × place, lookalikes of the proof customers, the problem the seller
  solves). Respect the existing spend guards, quotas and ADR 0012 seams. Reserve before you spend
  and record what you spent.
- **Lookalike discovery** seeded from the team's proof customers and its best-converted leads.
- **A relevance gate before a candidate becomes a lead.** A cheap, batched AI judgement of each
  candidate against the profile + ICP, with a stored reason, should sit alongside the existing
  `LeadCandidateFilter`. That also covers the "is this a competitor?" case, which word matching
  can't. It must be bounded in cost, and it must degrade gracefully rather than failing the run.
- Fix the cheap wins the docs already name: Companies House sector (SIC) search and status
  filtering, one shaped query shared across providers instead of one shaping call per provider,
  and the web-search query count.
- Feed relevance into the existing scoring and evidence model (ADR 0028) rather than inventing a
  parallel score. A lead should be able to say _why_ it matches this business.

## Prove it: build the measuring stick first

Before changing behaviour, build a **relevance evaluation** you can rerun:

- A small fixture set of realistic business profiles (e.g. a UK design studio, a UAE B2B SaaS, a
  local trades firm, a need-based agency with no vertical). Each has hand-labelled "good fit / bad
  fit / competitor" example companies.
- A repeatable harness (an artisan command or a dedicated test group, **not** part of the default
  `composer test` run if it calls live providers) that runs profile → ICP → search and reports
  precision@N, the competitor leak rate and the cost per run.
- Record a baseline on the current code, then report the delta after each workstream. Put the
  numbers in the module docs. If a change doesn't move the number, say so and question whether it
  should ship.

Unit and feature tests must still fake every external provider (AI, Serper, Companies House,
Places), as `CLAUDE.md` requires.

## Constraints (non-negotiable; the repo rules win any conflict with this brief)

- Module boundaries and the seams in `CLAUDE.md`: no module imports another to reach around a
  contract. Thin controllers, Actions in `DB::transaction()`, external calls never inside a
  transaction, jobs idempotent with `tries`/`backoff`/`timeout`/`failed()`.
- Tenancy on every new table (`team_id`, `BelongsToTeam`, a cross-tenant test), unless it is a
  documented platform dataset.
- Every AI call goes through `AiClient` with a registered, versioned prompt, schema-validated
  output, `UntrustedContent` around anything fetched, and cost recorded. The AI credit allowance
  and plan guards still apply.
- Compliance: no named individuals stored by a `LeadSourceProvider` (ADR 0025). Suppression and
  `LeadInterestGate` are still honoured. Log IDs, never page text or personal data.
- Any UI change (the review screen, the ICP editor, "why this lead") obeys the brand (§2a),
  handles EN/AR + RTL and all four states, and goes through the `ui-design` skill, then the
  `brand-reviewer` and `ux-reviewer` agents.
- Schema changes go through new migrations and `php artisan migrate` only.

## How to work

1. **Recon and plan first, then stop.** Write `docs/plans/ai-relevance-plan.md` with: what you
   found (where context is lost, citing files), the eval design, and phases with the expected
   relevance gain, cost impact, risk and an ADR where a decision warrants one. **Wait for my
   approval before implementing.**
2. Then work phase by phase in small conventional commits on a branch off `main`. Commit only
   when I ask.
3. Each phase is done only when `composer test` is green, `composer analyse` is clean, the
   frontend gates pass (`npm run check`, `npm run types:check`, `npm run build`), the module docs,
   `docs/ai-prompts.md`, `CHANGELOG.md` and the eval numbers are updated, and the
   `laravel-module-reviewer` agent has reviewed it (`phase-complete` skill).
4. At the end of each phase, summarise: what changed, the eval delta, the cost per run before and
   after, anything deferred, and anything I need to decide or supply (keys, spend limits, brand
   approvals).

If anything here conflicts with the code or the repo rules, or is ambiguous, **stop and ask**
rather than guess.
