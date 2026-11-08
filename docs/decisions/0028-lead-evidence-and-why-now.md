# ADR 0028 — A lead explains itself: evidence, confidence and "why now"

- **Status:** Accepted
- **Date:** 2026-11-13
- **Phase:** PL1 of `pre-launch.md`
- **Supersedes / amends:** nothing. It extends **ADR 0008** (every field is attributed) from
  per-field provenance to a dated history, and applies **ADR 0022**'s rules — stored explanations,
  unknown-is-not-zero, a system that proposes rather than applies — outside Signals for the first
  time. Constrained by **ADR 0026**.

## Context

A lead was a company record with a grade on it. The grade answered _should we want them_ and
`readiness` answered _can we reach them_, and between them they left the question a salesperson
actually asks unanswered: **why am I calling this company this morning rather than last month?**

The competitive gap analysis found this was the single most visible difference between the product
and the things it is sold against. Every comparable tool leads with a reason and a date; this one
led with a letter. Worse, a list of three hundred companies with no reasons is a list nobody opens
twice, and the thin leads — a web-search hit with a name and a town — were indistinguishable on
screen from a Companies House record with a filing behind it.

The raw material already existed. `signals` held dated trigger events, `leads.field_sources` held
per-field provenance, `lead_scores.breakdown` held the rules that produced a grade. What was missing
was a **history**: several reasons, from several places, each with the date the thing happened and
the date we found out, and one figure over them that a list could be sorted and filtered by.

## Decision

### 1. Evidence is a row, and a source is data

`lead_evidence` holds one fact: a kind, a source, a title, two dates, a weight and a link. A lead's
confidence is a **record over the set of them**, recomputed, never incremented — the rule ADR 0012
set for a derived figure and `usage_meters` states in as many words.

`EvidenceKind` is an **enum** and the row's `source` beside it is **not**, which looks inconsistent
until ADR 0018 §1's test is applied: _does the application branch on the value?_ The kind picks the
half-life and decides whether an item counts towards timing, so two code paths branch on it. Nothing
branches on which provider said a thing — the weight is a lookup in `config/evidence.php` and an
**unknown key is legal**, scoring `reliability.default`. That is what lets the L-phases add Land
Registry, the Gazette and Contracts Finder without redeploying this model.

The risk an unknown key creates is the quiet one: a source nobody weighted would under-count every
lead it ever touched, for ever, with nothing failing. `EvidenceReliabilityTest` asserts that every
shipped `LeadSource` and `SignalSourceKind` has a weight, which turns that into a failing suite the
day a source is added.

### 2. Four figures, four questions, and none of them collapses into another

| Figure               | Question             | May punish missing data?           |
| -------------------- | -------------------- | ---------------------------------- |
| `lead_scores.score`  | Should we want them? | **No** — blind to it by design     |
| `leads.readiness`    | Can we reach them?   | **Yes** — it measures what we hold |
| `leads.timing_score` | Is now the moment?   | n/a — null without an event        |
| `leads.confidence`   | How much do we know? | n/a — null without evidence        |

A lead can be strong on any three and weak on the fourth, which is why the list offers all four as
orderings and why **`fit_score` is not copied onto `leads`** despite `pre-launch.md` asking for it.
Fit is measured against an ICP; a second copy would drift from the one the grades, the board and the
exports are built on, and the drift would be invisible.

### 3. `max`, not a sum; distinct sources, not rows

```
item_weight = reliability × 0.5 ^ (age ÷ half_life)   // floored, and 0 once disputed
confidence  = clamp(max(item_weight) + corroboration, 0, 100)
```

Both choices are about a specific lie the alternative would tell:

- A **sum** would let a chatty provider out-argue a registry — forty web hits beating one filing.
  An **average** would mean that _adding_ a weak reason to a strong lead lowered its confidence, so
  a team would be marked down for finding out more.
- Corroboration counts **distinct sources**, so a provider returning forty pages about one company
  earns nothing extra. Independent agreement is the thing worth paying for; repetition is not.

Timing is the strongest decayed **trigger event** and nothing else, with no corroboration bonus: a
company's sector is as true next quarter as today, and two outlets covering one funding round is not
two reasons to call.

### 4. Unknown is not zero, and an unjudged lead is never hidden

`confidence`, `timing_score` and `readiness` are all nullable, and a null is left out of everything
rather than scored nought (ADR 0022). The consequence that matters is at the other end: a lead with
**no** confidence is **never suppressed**, because "the queue has not reached it" and "we judged it
and found little" are different states, and hiding the first would make a fresh discovery run look
like it found nothing.

### 5. Reliability is stored; the explanation is of the decision that was taken

`lead_evidence.reliability` is config's weight **at the moment of capture**, written onto the row.
Re-weighting a source tomorrow does not restate what the feed already explained today — ADR 0022's
rule for `signals.score_breakdown`, one level out. The panel shows each item's weight _today_
(reliability thinned by age), because the panel's job is to explain the number the list was ordered
by.

### 6. A dispute is one member's, and its effect is the team's

Four verdicts; **only `wrong` moves a number**, and it zeroes the item's contribution immediately.
The other three are kept and reported and change nothing: "out of date" is what decay already
measures, "not relevant" is about this team's interests rather than about the fact, and "useful" is
what the post-launch outcome loop will learn from. Four buttons each nudging the same weight would
mean nobody could say afterwards what a confidence of 61 had been told.

The effect is team-wide on the first member's word, because the first person to notice that a filing
names the wrong company should not have to persuade their colleagues before the lead stops claiming
it. A disputed item is **dropped from the calculation**, not weighted zero — left in it would still
count towards corroboration, so flagging a fact as wrong would have _raised_ the confidence by
agreeing it exists.

`evidence:source-quality` reports the wrong-flag rate per source and **changes nothing**. A source's
weight is a position the business has taken about an institution, and a handful of flags is a reason
to go and look (ADR 0022: a system that learns proposes; it does not apply).

### 7. The summary may use the evidence and nothing else, and never appears without it

The why-now sentence is a registry prompt (`lead.why_now`), temperature 0, bounded by its schema,
with every item wrapped in `UntrustedContent`. It is told it may use only the supplied evidence and
the company's own profile, and a test asserts the model is handed exactly the items that were
selected.

**The real protection is the UI, not the prompt.** The panel always shows the raw evidence —
titles, outlets, dates, links — beside Alima's sentence, labelled as Alima's reading. A sentence
nobody can check is a sentence nobody should trust, and a reader cannot check a prompt but can check
a dated headline with an outlet's name on it. That is a layout decision this feature is not allowed
to undo.

Three failure modes cost the sentence and never the facts: credit exhaustion, an unusable response
and an over-long input all leave the lead with its evidence and its confidence, and the screen falls
back to the strongest evidence title. None burns a queue retry. The hash
(`leads.why_now_evidence_hash`) is written **with** the summary, not before the call — stamped
early, a failed call would mark the lead as done and the sentence would never arrive.

### 8. Every lead has at least one reason, structurally

`LeadProvenanceObserver` writes a lead's own provenance as its first item of evidence on `created`.
That is a deliberate, small exception to this codebase's "logic lives in Actions" rule, and the
argument for it is the acceptance criterion: leads enter from five paths today and PL10 adds a
sixth, and a sixth path that forgot to record provenance would not fail a test — it would quietly
ship leads with no confidence. Hung off the row, it cannot be forgotten.

A converted signal is skipped, because `ConvertSignalToLeadAction` writes the **article** instead,
with the outlet's name and the publication date, in the same transaction as the lead's timeline
entry. A lead whose history claims an event its evidence panel cannot show would explain itself two
different ways.

### 9. The dark launch has to be dark

`prelaunch.why-now` is the first Pennant flag in the application (ADR 0026 §4), and the feature
splits across it in two different places for two different reasons.

**The arithmetic runs for every team**, flag or no flag. It is a handful of multiplications over
rows we already hold, and running it is what makes the backfill and the first enabled team cheap —
so `leads.confidence` and `leads.suppressed_low_confidence` are filled in regardless.

**The summary does not.** It is an AI call against the team's own credit allowance, and buying one
for a team that cannot see the panel would be charging them for a feature they have not got. So
`RecalculateLeadConfidenceAction` asks the flag before dispatching, and with no team in context the
answer is no — the safe answer to "should we spend money" is always no. (This was found by the test
suite rather than by reading: eleven Signals tests started failing because a lead created in them
now ran a summary inline and consumed their faked AI response. The failure was pointing at a real
cost bug, not at the tests.)

**The suppression is gated inside `LeadQueryBuilder`**, not in each screen. Decided there rather than in each
screen so that the web list, `/api/v1`, a saved view, a bulk selection and the CSV export cannot
disagree about which leads exist. A list that honoured the column without asking the flag would
quietly hide a third of a team's leads, which is how a dark launch stops being dark.

### 10. What nobody may do

A team cannot create or delete an item of evidence. `LeadEvidencePolicy` has `view` and `flag` and
nothing else: a team that could delete an inconvenient fact about a prospect would have a confidence
figure measuring its own optimism. Retention removes evidence; a member disputes it.

`payload` never leaves the database through an API resource. It is the provider's raw output, kept
for audit and re-mapping, and on a signal-derived row it can name a person
(`docs/compliance.md` §3.9).

## Consequences

- The leads list answers a different question than it did. A member sorting by **Most urgent** gets
  the companies with something happening; sorting by **Best evidence** gets the ones worth trusting.
  Both are new, and neither is a re-skin of the grade.
- Low-confidence leads are hidden by default once the flag is on. That is a visible change to a
  team's lead count, which is why it is counted in words above the table with a one-click reveal,
  and why the threshold is on the Team settings screen with an explanation rather than buried.
- One AI call per lead per evidence change, and the hash is the whole cost control. A nightly
  re-score of a team's whole list costs nothing; adding a reason to one lead costs one sentence.
- The `useful` / `wrong` verdicts and the evidence-per-lead are the inputs the post-launch outcome
  loop will learn from. Nothing in PL1 learns from them automatically, by design.
- The observer means **every** lead in every test now has an evidence row and a confidence. That is
  the intended behaviour and it changes what a factory-made lead looks like; a test that wants a
  pre-PL1 lead deletes the row and nulls the figure, as `EvidenceBackfillTest` does.
- `config/prelaunch.php` is a new config file not in the plan's Appendix C. It holds the defaults
  for all nine flags, so the remaining eight are a line each.
