# ADR 0024 — A playbook prepares an approach; it never sends one

- **Status:** Accepted
- **Date:** 2026-11-03
- **Phase:** Signals S14 (`signals-v2-plan.md`)
- **Supersedes / amends:** nothing. Extends ADR 0007 and `docs/compliance.md` §3.2–3.3 and §3.11 to
  cover work that runs with nobody at the keyboard.

## Context

Signals, up to S13, produced a feed. Somebody read it every morning and decided what to do with
each card. S14's goal is that a signal arriving becomes an event the team can **automate against**:
route it, stage it, assign it, draft the approach — without a human triaging a feed.

That is the first feature in the product that changes a team's own data, and could reach a prospect,
with no request behind it and nobody watching. Two questions had to be settled before any of it was
built: **what may an unattended rule do**, and **how does Signals reach the modules that own the
operations it needs** without becoming a second implementation of them.

## Decision

### 1. A playbook drafts an approach. It cannot send one.

`OutreachGuard` answers whether a prospect may be approached. Suppression, sender identification and
the opt-out are **conditions of sending** (`docs/compliance.md` §3.2–3.3), and a UAE-jurisdiction
signal carries `UaePriorConsent`, which nothing published can satisfy. An unattended rule that
transmitted would be the platform settling a legal question on a team's behalf, overnight, with
nobody reading it.

So the drafting step writes a draft and keeps it for review, and it is **refused outright** where
the guard says the prospect may not be approached. The refusal is recorded on the run with the
guard's own reason and shown in the log, because a compliance decision taken at three in the morning
that nobody can see is indistinguishable from one that was never taken.

**This is structural rather than a policy.** Signals reaches Outreach through
`Signals\Contracts\SignalOutreachDrafter`, which has `channels()`, `tones()` and `draftForReview()`
and **no `send`**. A test asserts that method list, so adding a send path fails the suite rather than
shipping. `docs/compliance.md` §3.12 records the rule and generalises it: anything added later that
runs without a person stops at the draft.

### 2. The contract is Signals' and the implementation is Outreach's.

The consumer declares the interface and ships a default; the provider binds the real one over it —
the arrangement `CLAUDE.md` §3 describes for Compliance's three seams and ADR 0023 for signal
sources. Nothing in `App\Modules\Signals` imports `App\Modules\Outreach`.

The default, `NoOutreachDrafting`, is **conservative rather than permissive**, and that is the one
way this seam differs from the ones Compliance and Admin decorate. Those default to "allow", because
a default that refused would block a module over a rule nobody had applied. Here the question is
"may this prospect be approached", and a default that answered yes would be Signals settling a
compliance question Outreach exists to settle. So with no drafting module bound, the step reports
`drafting_unavailable` and the builder offers no channels at all.

The contract also supplies the channel and register **vocabulary**, which is what lets the builder
offer Outreach's own options and the save path validate against them without this module learning
Outreach's enums.

### 3. A step handler calls the owning module's Action. It never reimplements one.

One `PlaybookStepHandler` per `PlaybookActionKind`, behind `PlaybookStepRegistry` — the deliberate
mirror of `SignalSourceRegistry`, so a new kind of step slots in by registering a handler rather
than by growing a `match` inside the executor.

Assignment is `SetLeadAssigneesAction`; a stage move is `MoveLeadStageAction`; a tag is
`SyncLeadTagsAction`; a task is `CreateLeadTaskAction`; a conversion is
`ConvertSignalToLeadAction`; a notification is `NotificationDispatcher`. Those Actions carry the
transaction, the timeline entry, the audit entry, the post-commit event and the tenancy check, and a
handler that wrote the row itself would be a second implementation of all five — silently diverging
the first time one of them changed. The tests assert the **Action's own side effects** rather than
only the row, which is what makes that checkable rather than aspirational.

Two of those Actions take the **whole** set (assignees, tags), so the handlers union with what the
lead already has. A rule that silently took a lead off the person working it is the opposite of what
automation is for.

### 4. Four independent brakes, and all four are load-bearing.

A rule that can convert every signal into a lead and draft a message for each is a rule that needs a
rehearsal. The brakes are deliberately of different kinds, because the ways this goes wrong are not
the same shape — mass conversion is a billing problem, mass drafting is an AI-spend problem, and a
backfill is both:

1. **`(signal_id, signal_playbook_id)` is unique.** A rule fires once per signal, ever — across two
   watch runs, across a retry, and across the `SignalConverted` event its own conversion step
   raised. The row is **claimed before any step executes**, so two workers racing have exactly one
   of them do the work; executing first would have the loser discover its duplicate after creating a
   second task and spending a second draft. The unique is deliberately **not** scoped by mode: a
   signal has been considered, and switching a rule to live is a statement about the signals that
   arrive next.
2. **Per-job spend ceilings** (`PlaybookBudget`), counted across every playbook rather than per
   rule, because three rules each converting twenty-five is seventy-five leads. Two counters, not
   one: a conversion costs a lead against the plan's allowance, a draft costs AI credit, and sharing
   one would let a rule that drafts nothing convert twice the configured number. The drafting one
   counts **attempts rather than successes**, or a hanging provider is unbounded: two hundred
   signals each waiting out a timeout until the job's own timeout kills the worker, which is the one
   thing that loses a claimed run's work.
3. **A ceiling on the batch** (`signals.playbooks.max_signals_per_run`).

    **A cap defers; it does not refuse** — and getting that backwards was the costliest mistake in
    the first cut of this phase. Checking the budget inside the handlers meant a signal arriving
    after the ceiling got a _claimed_ run row with a `*_cap_reached` refusal on it, and the claim is
    permanent: with the shipped defaults, 175 of 200 signals were refused for ever by design. The
    batch cap was worse — it sliced the tail off and dropped it.

    So both ceilings are asked **before the signal is claimed**
    (`PlaybookBudget::canAfford()`), the Action hands back a `deferred` list, and the job
    re-dispatches it after `signals.playbooks.defer_seconds`. The ceilings keep their meaning,
    because the next pass is a later job. It terminates: a pass either handles at least one signal
    or defers strictly fewer than it received.

4. **A dry-run mode** (`PlaybookMode::DryRun`) that evaluates, records what it _would_ have done,
   calls no model and reaches nobody. It is a separate column from `is_active` on purpose: paused
   means _do not evaluate me_, rehearsing means _evaluate me and tell me what you would have done_,
   and collapsing them would lose the rehearsal. A rule created with no mode rehearses.

    **A rehearsing rule does not stop the chain.** `stop_after_first_match` is honoured only for a
    rule that performs, because a dry-run rule holding the flag would swallow the signal for the live
    rule below it — so switching a rule to rehearse would silently change what the rest of the team's
    automation did. The one promise a rehearsal makes is that it changes nothing, and that has to
    include the other rules.

Keep all four.

### 4a. A rule may not override a member's decision.

A signal the member has **dismissed** is skipped before it is claimed and before it is counted: no
run row, no log line. The rule never saw it.

This is not the same question as the conditions, and that is why it is here rather than in the
evaluator. `ConvertSignalToLeadAction` deliberately permits converting a dismissed signal, because a
member may ask for exactly that — they changed their mind. A rule has not changed its mind about
anything. The same Action, permitted to a person and refused to an automation, is this whole ADR in
one line.

### 4b. An interrupted run says so.

The claim has to be written before any step executes (§4.1), so a worker dying in between leaves a
row with empty results — and the retry correctly finds the pair claimed and does nothing. That
cannot be designed away without giving up the once-per-signal guarantee, so it is made **visible**:
`completed_at` is stamped with the results, and an unstamped row reads as "started and did not
finish" rather than as "matched nothing". An audit trail that cannot show its own gaps is a success
log.

For the same reason, a step whose kind this build knows but has no handler for is recorded as a
refusal rather than left out of the results, and a tag the workspace's own twenty-label cap dropped
is reported rather than counted as applied. A log row that says something happened when it did not
is worse than no row.

### 4c. The listener may not fail the thing that raised the event.

`RunPlaybooks` runs synchronously inside `RunSignalWatchAction`'s after-commit callback, so an
exception there failed the watch-run job — and the retry re-ran the paid Serper search. The signals
are already committed by that point; automating them is worth nothing beside re-billing the search
that found them. It catches `Throwable`, logs ids, and returns.

### 5. The evaluator is deterministic and pure, and the preview spends nothing.

`SignalPlaybookEvaluator` takes `SignalPlaybookConditions` and `SignalPlaybookFacts` — nine scalars
— and touches no database, no container and no clock. That is a requirement rather than a
preference: the preview and the dry run are both **predictions** about what the live rule will do,
and a classifier here would make both of them guesses. Every condition is a fixture row in
`SignalPlaybookEvaluatorTest`, which is the only way to be sure about a matcher with eight
independent clauses.

Two rules it is easy to get backwards, both inherited:

- **An empty condition list means "don't care"**, so a new rule with nothing filled in matches
  everything — which is what the builder has to show somebody before they start narrowing it.
- **Unknown is not zero** (ADR 0022). A signal with no `heat` does not clear a heat floor and one
  with no amount is not inside an amount band. An unplaceable story fails a jurisdiction condition,
  which is the conservative direction for a rule that may go on to draft (§3.11's reasoning).

`PreviewSignalPlaybookAction` is S9's preview idea applied to automation, with one difference that
matters: **it spends nothing.** It evaluates signals the team has already paid for, executes
nothing, and reports `matched / considered` — because the **ratio** is what separates a rule
somebody can switch on from the one that would convert the whole feed. No ledger row, because there
is nothing to cap; its bound is a window, not a budget. It takes conditions rather than a saved
playbook, so a rule can be previewed before it exists, which is when somebody wants the answer.

### 6. An unattended rule acts as a real member, and only while it could.

`PlaybookActor` resolves the rule's creator **while they still hold a role that could have done the
work by hand**, then the team's owner, then records a refusal. Membership alone is not enough: the
module Actions take an actor and do not consult a policy, so a creator demoted to Read-only would
otherwise have gone on converting, assigning and drafting for ever. A synthetic system user was rejected deliberately: every entry it wrote
would be unattributable, which is the opposite of what an audit trail is for, and it would need a
row in `users` that can sign in to nothing.

### 7. The log outlives the rule and dies with the signal.

`signal_playbook_runs.signal_playbook_id` is `nullOnDelete` with the rule's name **copied** onto the
row, because deleting a rule must not erase the record of what it did to somebody's pipeline
(ADR 0018 §4's reasoning). `signal_id` is `cascadeOnDelete`, which is the opposite choice, because
the retention sweep minimising a prospect's event away should take the record of how we handled it
with it.

A **step the build does not recognise is dropped and the rest of the rule honoured**, with
`has_unknown_steps` telling the screen to say so — a rolled-back deploy must not fail every signal a
team's rules touch.

## Consequences

- A team can automate the first few steps of their morning, and the one step with a legal
  consequence always stops at a draft a person reads.
- Nothing in Signals knows Outreach exists, and nothing in Outreach knows about playbooks beyond
  implementing one interface.
- A new kind of step is a handler plus an enum case plus a registry line. A new **sending** step is
  not available at all, and asking for one is a §5 question for counsel before it is an engineering
  one.
- The run log is the feature's real deliverable as much as the automation is: the first question
  anybody asks of a rule is why it did not do something.

## Alternatives considered

- **Let a rule send, behind a per-team switch.** Rejected. The switch would be a tenant's own
  statement that they had satisfied a consent requirement the platform cannot verify, and the
  platform would be the transmitter. `BILLING_STRIPE_ENABLED`-style switches work for our own
  spending; they are not a mechanism for delegating a legal position.
- **A `send_approach` step gated on the guard alone.** Rejected: the guard would permit it for a UK
  lead under legitimate interests, which is exactly the case where an unreviewed, AI-written cold
  email reaches a real person at scale with nobody having read it.
- **One `match` in the executor instead of a handler registry.** Rejected for ADR 0023 §1's reason:
  the `match` becomes the place a new step added to the enum silently does nothing.
- **A counter for round-robin assignment.** Rejected. A contended write per team, and a retry that
  re-read it would hand the same person two leads. The signal's own id picks the position, so a
  retry lands on the same member — which is what the once-per-signal guarantee wants anyway.
- **An "apply to last week's signals" button beside the preview.** Rejected for now: it would be a
  one-press mass conversion, which is the exact failure the four brakes exist to prevent. The
  rehearsal plus the preview answer the question it was for.
