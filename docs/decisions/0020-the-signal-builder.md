# ADR 0020 — The signal builder: the AI drafts, the preview decides

- **Status:** Accepted
- **Date:** 2026-11-02
- **Extends:** ADR 0011 (Signals module), ADR 0015 (plan capabilities), ADR 0017 (prompt input bounds), ADR 0018 (signal definitions are data), ADR 0019 (search geography)
- **Phase:** S9 of `signals-v2-plan.md`

## Context

S7 made a signal type a row a team owns, which was the right change and left the team holding a
blank page. The S7 screen could create one; it could not help anybody write a good one, and "a good
one" is not a matter of taste:

> The S0 spike exists because **nobody can tell whether a query template is any good by reading
> it.** Seven of eight hand-written templates looked fine and returned **zero** results on Serper
> `/news` with a one-week recency window.

That spike was a developer spending an afternoon and $0.01 before shipping. S7 handed the same
problem to customers without the afternoon: a team writes three plausible templates, saves them,
attaches them to a watch, and finds out in a week — except what they see is an empty feed, which
reads as _Signals does not work_ rather than _that template is too specific_. Worse, the templates
run daily in the meantime, so the silence costs money.

So S9 is not "a nicer form". It is the two things that make a type authored by somebody who is not
us as good as one authored by us: a draft they did not have to invent, and an answer before they
commit.

## Decision

### 1. Three doors, one review form

The **library** (now six vertical packs), the **composer** (a sentence), and the **hand-written
form**. All three land in the same editor, and all three can be previewed from it. There is no
"advanced mode" that bypasses the review step, because the review step is where somebody reads what
they are about to pay for.

The library is deliberately **first on the screen** and the composer second. For most teams the
fastest path to a working type is still "take one of ours", and a blank text box at the top would
hide that behind the impression that authoring is expected.

### 2. The AI drafts; the member owns

`ComposeSignalDefinitionAction` returns a `DraftSignalDefinition` and **never writes anything**.
Saving is a separate Action behind a separate button, reached only after somebody has read the
draft.

This is not ceremony. A signal definition is a standing instruction to spend money on searches every
day; a composer with write access would be a prompt with write access to a recurring cost. The
separation also makes the honest failure mode available: the member edits the draft, because the
draft is a suggestion rather than a result.

**A refusal is a first-class answer.** `is_viable: false` with a reason, for a brief that is not an
event a news search could find — "find me rich people in Dubai", a request for contact details,
anything about a private individual rather than a business event. A composer that produced plausible
templates for those would be strictly worse than one that refuses: it would spend a team's money
daily on a search that cannot work, and they would conclude the product is broken.

### 3. Three layers of bound on the model's output, because each catches a different thing

1. **The schema** bounds the shape: six templates, 200 characters each, scores 0–100. A hijacked or
   confused response cannot return an unbounded array (the S6 security review's standing rule).
2. **`SignalDefinitionData::rules()`** — the _save path's own rules_ — bounds the meaning.
   `DraftSignalDefinition::fromAi()` re-validates against them, so a schema-valid draft with a
   category nobody defined is refused here rather than reaching the form half-filled. ADR 0018 §7
   made the same argument about the extraction prompt: a prompt describes a constraint, it does not
   enforce one.
3. **`UntrustedContent`** wraps the member's brief, and ADR 0017's character bound applies to it
   twice: in the validator and again in the Action.

    **The brief is the one _externally sourced_ untrusted input, not the only customer-written text
    in these prompts**, and the first draft of this ADR said otherwise. The security review was
    right to correct it: a definition's own `description`, `includes` and `excludes` reach the
    classifier prompt **unwrapped**, in a trusted slot, both here and in a real run since S7 — and
    they have to, because steering the classifier is literally their job ("what counts", "what does
    not"). What follows from that is a bounded, tenant-internal residual: a member with the composer
    and the preview can iterate their own rule text until the classifier's system prompt leaks back
    to them in a `summary`. There is no cross-tenant reach, nothing is persisted, and no privilege
    changes — they are extracting our prompt wording, to themselves. It is accepted rather than
    fixed, because wrapping first-party configuration as untrusted data would tell the model to
    ignore the instructions the member is paying us to follow.

### 4. The preview is the feature, and it persists nothing

`PreviewSignalDefinitionAction` runs the draft's queries against the live provider, classifies the
first batch through **`signals.extract_event` v2** (the real classifier, with the draft as the only
offered type — a preview that used a different prompt would be previewing a search nobody will run),
and returns a verdict per article.

**No signals.** A definition nobody has saved has no business putting rows in a team's feed; the
results are shown once and discarded.

**Six verdicts, not a boolean**, because each one is a different thing to change: `ignored_domain`
(a sources problem), `not_an_event` (a templates problem), `wrong_location` (a places problem),
`below_confidence` and `below_relevance` (threshold problems), `kept`. An empty list says _something
is wrong_ and nothing about which thing — which is precisely the failure this phase exists to
prevent. The ordering is fixed so a preview is reproducible.

The one place the preview deliberately differs from a run: a run **drops** a skip-listed source
silently, and a preview **shows it, labelled**. "Your templates work and every result is on your
skip list" is a diagnosis nobody could reach from an empty list. Both read the same
`Signals\Support\IgnoredDomains`, because a preview 1% more lenient than the run would be answering
about a search that will never happen.

### 5. Three independent caps, because a preview is a user-triggered spend loop

| Cap                                   | Bounds       | Why a different cap would not do                                                         |
| ------------------------------------- | ------------ | ---------------------------------------------------------------------------------------- |
| `signals.preview.max_queries` (4)     | One press    | A preview is a **sample**; the answer does not get truer in five cities.                 |
| `throttle:signals-preview` (10/hour)  | One member   | Iterating on wording must stay affordable; a held-down key must not.                     |
| `signals.preview.daily_budget_micros` | The **team** | Twelve members each inside their own limit can still empty an allowance in an afternoon. |

The third is the one that lives **in the Action**, because it is a fact about a team rather than
about a request — and it is enforceable only because `signal_previews` exists. **A cost with no
ledger row is a cost nobody can cap**, which is the whole reason that table holds no results.

**The row is reserved before the spend and settled after it**, which the reviews forced and which is
the part worth remembering. Writing it at the end looked equivalent and was not, in three ways: an
unexpected provider failure left searches paid for and no row, so the next check passed as though
nothing had happened; the check-then-spend gap let every member of a team read "budget remaining"
before any of them had recorded a cost; and the AI's own failure branches reported a cost of **zero**
while `ClaudeAiClient` had already billed two attempts — which a member could _trigger on purpose_,
because their own exclude rules go into that prompt and a rule reading "ignore the schema" forces an
invalid answer every time. So: a lock around check-and-reserve, a `finally`-shaped guarantee that the
row survives any failure, and the AI cost **measured** against the team's `ai_requests` ledger rather
than taken from the response.

**The composer has no team-wide cap of its own, and that is an accepted gap rather than a decision.**
Its ceiling is the _monthly_ AI allowance, so several members looping it could spend a month of a
team's AI budget — including billable overage — in an afternoon, and starve insights, drafting and
scoring for the rest of it. The preview has a daily budget for exactly that argument. The per-member
rate limit is 10/hour rather than 20 to narrow it; a per-team daily composer budget belongs with the
other per-team ceilings in **S16**.

### 6. The capability is drawn around the AI, not the builder

`Capability::SignalComposer` gates **drafting with AI** and nothing else (plan decision #1). The
library, the hand-written form and the preview stay on every plan that has `Capability::Signals`.

Gating the builder would have made the cheaper plans _worse than they were before S9 existed_, which
is not what a new feature is for. And gating the preview specifically would have been the worst of
the options: it would have sold the cheaper plans the ability to write templates without the ability
to find out whether they work.

### 7. A pack is not a column

The six vertical packs live on `SignalPreset` in the catalogue, and `SignalPresetCatalogue::packFor()`
resolves one by key for the library screen. They are **not** stored on `signal_definitions`.

A pack is how the platform organises its own presets. It is not a property of a signal type, and
certainly not of a team's own — a hand-written type would need a pack it could never belong to, and
every tenant row would carry a merchandising label. A key the catalogue no longer knows resolves to
no pack and keeps working, which is the right failure: the row is the thing a team is paying to run.

### 8. Every preset template was run against the provider before it shipped

66 candidate templates across 21 new presets, spiked live for ~$0.08. **Eight returned zero** and
were replaced; one whole preset the plan asked for — a sports or entertainment contract for the
wealth pack — was **dropped**, because the news for it is transfer rumour rather than signed deals;
three ship marked `isThin` and say so on the shelf. The table is in `docs/modules/signals.md`.

A preset never run against the live provider is a guess with a label on it, and a library of guesses
is worse than no library: it teaches a team that the feature does not work, using our own content.

## Consequences

**Good**

- A member goes from a sentence to a previewed, active definition without leaving the screen.
- The S0 lesson is now a product feature rather than institutional memory.
- The library reads as a product: 30 presets, seven groupings, every template checked.
- The cost of a definition is on screen while it is being written, which is the only moment anybody
  is deciding about it.

**Costs, accepted**

- The composer is a reasoning-tier call per press. It is metered through the normal AI allowance and
  rate-limited per member; the cheap tier was tried in the design and writes templates that look
  right and find nothing, which is the expensive kind of cheap.
- A preview is a sample from one place, so a thin result is not proof a definition is bad. The panel
  says so in words rather than letting somebody read a sample as a verdict.
- `signal_previews` is a table that exists to be counted, not read. That is the point of it.
- The Arabic for the new copy was written in this phase and wants a native legal-register reader, as
  S8's did (`docs/brand/brand-tokens.md` §7).

**Found by review, after the first implementation**

Three agents read this phase (`laravel-module-reviewer`, `brand-reviewer`, `security-review`), and
what they found is worth recording as a pattern rather than a list. **Every serious finding was in
the accounting, not in the feature.** The composer and the preview did what they were designed to do
on the first attempt; what was wrong was the bookkeeping around them — when the ledger row was
written, what it contained when the AI misbehaved, and whether two people pressing at once could
both pass the same check. A spend loop is easy to design and easy to under-account for, and
under-accounting is invisible in every test that only checks the happy path.

Two others were behavioural and neither was in a money path. The preview flashed the submitted draft
back to the screen so the form would survive the redirect, and the client could not tell that echo
from a composer draft: previewing a **saved** type reopened the editor as a new one, so pressing Save
created a duplicate and the unsaved-changes guard had been silently cleared. The fix was to keep the
client's own state across the post instead, which is both simpler and correct. And the preview
labelled **our** outage as `not_an_event` — a verdict that tells a member to rewrite templates that
are working — which is now its own `Unclassified` case, because a verdict has to name what actually
happened.

One finding was mine before theirs: growing the library to thirty presets broke "add all of them",
a button that could no longer succeed (27 presets, a ceiling of 25) and should not have — thirty
types is a search per template per type on every run of every watch. It offers the **starter set**
now.

**Open (⚖️ / later phases)**

- A team cannot publish a type back to the library; S15 adds submission with admin curation (plan
  decision #3).
- `uk_psc_change`, `ma_shareholding_change` and the other filing-shaped types stay thin until S13
  reads the registries directly. A keyword search over news cannot find a filing reliably, and no
  amount of template tuning changes that.
- The preview searches one region and one place. Previewing across a whole watch is a bigger spend
  decision than this phase wanted to take.
- A **per-team daily composer budget**, deferred to S16 with the other per-team ceilings (see §5).
- A retried Serper call (a 429 or a connection error) is not separately accounted for in the ledger,
  which counts one request per query. Whether the provider bills the retry is unconfirmed; it is the
  same arrangement a real run has had since S2.
