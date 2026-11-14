# ADR 0036 — A background check that reads the site and shows its working

- **Status:** Accepted
- **Date:** 2026-11-14
- **Phase:** P3 of `docs/plans/ai-relevance-plan.md`
- **Supersedes / amends:** nothing. It lifts the "single-page, best-effort homepage read" limit
  `business-intelligence.md` has carried since Phase 6, applies **ADR 0020** (an AI that writes
  configuration is previewed before it is kept) to the profile, and holds the reader to
  `docs/compliance.md` §4 (we respect `robots.txt`).

## Context

The background check read one page, capped at 12,000 characters, and was told nothing the team had
typed in onboarding. It returned a list of findings with a single confidence figure and no way to
see where any of them came from. Accepting it wrote two blank profile fields.

Three problems followed. A homepage is a slogan: the customers a business names, what it charges
and who it turns away are one click further in. A finding nobody can check is a finding a member
either trusts blindly or ignores. And "blanks only, two fields" meant most of what the check learned
never reached anything that searches or scores.

It also did not consult `robots.txt`. `RobotsPolicy` had exactly one caller, in Signals.

## Decision

### 1. A reader, not a crawler

`Core\Services\SiteReader` reads the homepage and up to five more pages chosen from **the
homepage's own links**: same host, a shallow path, a path that says it is a services, case-study,
clients, industries, about or pricing page. Proof of who buys ranks first.

Every bound is hard, and whichever runs out first ends the read:

| Bound                          | Default             | Why                                                                      |
| ------------------------------ | ------------------- | ------------------------------------------------------------------------ |
| Pages                          | 6                   | Enough for one of each kind of page; a seventh adds cost, not knowledge  |
| Characters per page / in total | 8,000 / 40,000      | The prompt's input budget                                                |
| Seconds                        | 25                  | Leaves the job room for its AI call inside the queue's 85-second timeout |
| Links followed                 | The homepage's only | There is no frontier to grow, so there is nothing to bound it with       |

- **`robots.txt` is asked before every request, the homepage included.** A site that disallows us
  is not read at all, and the check proceeds on the team's own words. `RobotsPolicy` fails closed
  on a file it cannot fetch.
- **Every request goes through `WebContentFetcher`**, so the SSRF guard, the redirect
  re-validation, the byte cap and the timeout are the one copy that already exists. The contract
  gained one method, `fetchPage()`, which returns the text **and the same-host links** — the
  fetcher is what drops every off-site link, so a caller cannot be led off the site it was given.
- **Nothing is stored but what the model returns.** Page text goes into the prompt, wrapped as
  untrusted content, and is discarded.

A web-search pass for public context was considered and **not built**. The eval decides whether the
site alone leaves a gap; a second paid source is not added on a guess.

### 2. Findings carry evidence, and the evidence is checked

`business_intel.background_check` v3 returns, beside each finding it can support, the page number
and a **short exact quote**. `BackgroundCheckFindings::withVerifiedEvidence()` then looks for each
quote in the text of the page it cites (case, whitespace and typographic quotes folded) and drops
any entry that is not there: a paraphrase, a page that was never offered, something recalled.

The finding itself is kept. It becomes a finding with nothing to show for it, and the review screen
says so in words: "Alima worked this out from what you do. Nothing on your site says it outright."
That is an honest state, and often a correct inference; what it may not do is pass for something
the site states.

Confidence is per item of evidence and is shown as words (stated / suggested / a weaker hint), not
as a percentage nobody can act on.

### 3. New findings, chosen by what a search needs

Proof customers (company names only — ADR 0025's rule, applied to the seller's own site), problems
solved, the price of a job, how the business delivers (`DeliveryModel`), how big its customers are,
buying triggers and anti-customers. Each one constrains a search in a way an industry list cannot:
the price says how big a customer must be, the delivery model how far away it can be.

The customer-side fields are **required** in the schema. An optional field is one a model may
decline to write (`icp.compose_profile` v1's lesson); an empty list or a null still says "the pages
do not show this".

### 4. The team's own entries win; a conflict is shown, never overwritten

`BusinessIntelligence\Support\ProfileMapping` is the one definition of how findings reach the
profile. The review screen renders its rows and the accept Action applies them, so the preview
cannot disagree with the result. Each field is in one of three states:

- **fill** — the profile field is blank; accepting writes the finding.
- **same** — the profile already says it.
- **conflict** — the team entered something else. Both values are shown, and the finding replaces
  the team's only for a field the member ticks. The default is to keep theirs.

`customer_examples` merges rather than replaces: a customer the site names is added to the ones the
team typed. The audit entry records which fields were written and which of those overrode an entry.

### 5. The profile is where findings live once accepted

Decided in ADR 0035 §1 and relied on here: an accepted finding is the team's own description of
itself from then on, read by every consumer through `SellerBrief`. A report stays as the versioned
record of what was found and why.

## Consequences

- **A redirect can leave the site.** A same-host link may answer with a redirect to another public
  host. Every hop is re-validated against the SSRF guard, so no internal address is reachable, but
  the destination's `robots.txt` is not asked and its text is read. Refusing a final host that
  differs from the requested one is the fix; it needs care, because `www.` and apex redirects are
  common and legitimate. Known and not yet built.

- A check makes up to six requests to the team's own site instead of one, and a reasoning call
  with several times the input. It is still one check per run, triggered by a person or by
  completing onboarding.
- A site that blocks robots gets a check built from the team's own words alone. That is the
  correct outcome and a visibly thinner report.
- Evidence verification is strict on purpose. A model that paraphrases loses its evidence, not its
  finding; the count of evidence kept is logged so a prompt that starts paraphrasing shows up.
- The nine profile columns the check fills are not yet editable in the onboarding wizard. A member
  corrects them by re-running the check or choosing per field at accept. Making them editable is
  the obvious next step and is not built.
