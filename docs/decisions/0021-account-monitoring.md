# ADR 0021 — Account monitoring: a watch can be pointed at companies instead of places

- **Status:** Accepted
- **Date:** 2026-11-02
- **Extends:** ADR 0008 (lead providers, fill-gaps-never-overwrite), ADR 0011 (Signals module), ADR 0018 (signal definitions are data), ADR 0019 (search geography)
- **Phase:** S10 of `signals-v2-plan.md`

## Context

Signals has answered one question since S0: _who, in this place, just came into money?_ It is a cold
question. The answer is a stranger, and a stranger needs a reason to take the call.

S10 adds the warm version: _what just happened to the forty accounts I am already working?_ The
contact exists, the relationship exists, and the event is the reason to call today. It is also what
makes the module hard to switch off — a discovery feed is a nice-to-have, and going blind on your
own pipeline is not.

Mechanically that is a small change to the search and a large change to the product, and it brings
exactly one new way to be badly wrong: **an event pinned to the wrong company.** A member who sees
"Acme was sold" against the wrong lead, calls the wrong person and finds out, does not go back to
trusting the feature. Most of the decisions below are about that.

## Decision

### 1. A watch is either topical or entity-scoped, and the model says which

`signal_watches.signal_watchlist_id` is nullable. Null means the watch asks what happened **in a
place** — definitions × locations, as it always has. Set means it asks what happened **to these
companies** — definitions × entities, with no place in the query at all.

It is one column rather than a flag plus two half-filled column sets, and `SignalQuerySpec` carries
**either** `locations` or `entities`, never both. Conflating them would multiply one cost by the
other: forty accounts in three cities is a hundred and twenty paid searches for a question nobody
asked.

### 2. A watchlist's names are either an output or somebody's work, never both

`source_kind` is `pipeline`, `manual` or `segment`:

- **`pipeline`** mirrors the team's own leads, filtered by stage and owner, refreshed nightly. Its
  entries are an **output**, so the screen offers stages and a "sync now" rather than an add box,
  and `AddWatchlistEntriesAction` refuses a hand-typed name: a name the next sync would delete is a
  screen arguing with its member.
- **`manual`** is names somebody typed, for accounts they care about before they have a record for
  them. The screen takes them as **one per line in a textarea**, because the member's source for
  this is a spreadsheet column or an email.
- **`segment`** is declared and **not offered** (`isOffered()` is false, the save path refuses it).
  Following a saved view means replaying its filters on a schedule, which is a different job from
  "every lead in these stages". A case with no implementation is worse than no case, and saying so
  in the enum is how the next person finds that out.

The kind cannot change after the list exists, for the same reason: turning a manual list into a
synced one would adopt somebody's typing as a sync result, and the reverse would delete it.

### 3. Entity queries are bare names — a spike finding, not a preference

The plan asked for `"{entity}" {keyword}` and said a company name is precisely the case where
exact-phrase quoting is right, then asked for that to be checked rather than assumed. It was, live,
over six companies that were in the news that week, in four query shapes each — 24 calls, about
$0.024:

| Query shape                    | Results | On topic |
| ------------------------------ | ------- | -------- |
| `"Majid Al Futtaim"` (quoted)  | **0**   | —        |
| `Majid Al Futtaim` (bare)      | 9       | 9/9      |
| `Majid Al Futtaim acquisition` | **0**   | —        |
| `"Derwent London" acquisition` | **0**   | —        |
| `Derwent London` (bare)        | 10      | 7/10     |
| `Derwent London acquisition`   | 3       | 3/3      |

**Every quoted query returned zero — twelve of twelve**, including single-word names where quoting
cannot change the meaning. Serper's `/news` endpoint with a recency window appears not to serve
quoted queries at all, which is the same wall the S0 spike hit from the other side.

And **appending a keyword lost genuine results rather than sharpening them**: one subject went from
nine results to none, another from ten to three. So:

- A **company** is searched by name alone, with no keyword, and `signals.extract_event` decides
  whether what comes back is an event of a watched type. That is what the classifier is for, and it
  already receives the watch's definitions.
- A **person** is not searched by name alone — "Jane Smith" returns every Jane Smith alive — so each
  person entity gets one query per definition, qualified by that definition's first match keyword.

### 4. A long list is a ring, searched a window at a time

One entity is one query per region, so a 250-account list is 250+ searches and the per-run ceiling
(`signals.max_queries_per_run`, 40) is not negotiable: every search is money.

The topical trim keeps the first N, which is survivable when the loss is a whole place a member can
see named on the card. For entities it is **not** survivable: the eighty-ninth account would never
be searched at all, silently, forever.

So `signal_watches.entity_cursor` makes the list a ring. A run takes the next window from where the
last one stopped and wraps; a daily watch covers 250 accounts about every six days. The cursor
advances **only on a completed run**, so a failure does not skip the accounts it never reached, and
the accounts screen says the rotation in words ("checked every 7 days") — a quiet morning on a long
list otherwise reads as a broken feature.

### 5. Two confidences, and no third

`SignalMatchConfidence` has `Exact` and `Likely`. There is no `Low`, because a weak guess is **not a
match**: the matcher returns null and the signal behaves exactly as it did before this phase.

- **Exact** — the team said so themselves (a watchlist entry carrying a `lead_id`), or the
  normalised names are identical.
- **Likely** — the names agree once the legal form is set aside ("Acme Engineering" /
  "Acme Engineering Ltd"). A journalist writes a name the way a reader will recognise it and a
  registry writes it the way it was filed, so this is the common case rather than the suspicious
  one — but it is an inference, and the card says so in words.

A person's name never makes a match on its own; it only strengthens one the company name already
made. Substrings never match. And the counterparty is tried **only** when the subject matched
nothing, because an acquisition names two companies and the event is about the one being bought.

The confidence is **shown**, and **Convert anyway** is a real button beside **Add to Acme Ltd** —
not a buried escape hatch. The member is the one who knows whether two companies with the same name
are the same company.

### 6. One name rule, extracted rather than written

`SourcedLead::dedupeKey()` has slugged company names since Phase 7. The plan's instruction was
blunt and right: reuse it, do not write a third normaliser, because three normalisers is how two
leads become three. So the rule moved to `LeadSourcing\Support\CompanyNameKey` and `dedupeKey()`
calls it:

- `for()` — the strict slug, unchanged, which is what a dedupe key is built from.
- `bare()` — additionally drops a trailing **legal form**, which is what `Likely` is built from.

`bare()` strips legal forms and **only** legal forms. `holdings`, `group` and `the` were in that list
for one draft and came back out, because they are part of a company's name rather than a filing
convention: stripping them made "Acme" match "Acme Holdings Ltd", which is precisely the false
positive this phase was warned about. Setting aside "Ltd" is reading a filing; setting aside "Group"
is guessing.

### 7. Matching happens while the row is being built, and attaching creates nothing

`matched_lead_id` is a column of the signal, set by `RunSignalWatchAction` as it builds the row —
not a correction applied afterwards. A signal is therefore never stored unmatched and then fixed,
and the timeline entry on the lead is written **inside the same transaction as the signal**: a lead
whose history says an event happened, with no such signal in the feed, is a worse state than
neither. Scoped to the run, which makes it idempotent for free — a re-run inserts no signal
(`insertOrIgnore` on `(team_id, url_hash)`), so there is nothing to record or announce twice.

Every kept signal is matched, **whether or not the watch was entity-scoped**: a topical watch
finding an event about a company already in the pipeline is exactly as useful, and a member should
not have to have predicted it.

"Add to {Lead}" reuses `ConvertSignalToLeadAction` through a named target rather than a second
Action, so the fill-gaps-never-overwrite rule and the `field_sources` stamping (ADR 0008) hold
without a second copy. The target is re-read under a lock and re-scoped by team, and
`ConvertSignalData::fromSignalForLead()` keeps **the lead's** company name: the article's spelling
is not an improvement on the record the team has been working.

### 8. The notification goes to whoever is working the account

`NotificationType::SignalOnAccount` is raised by a listener on `AccountSignalsDetected`, through
`NotificationDispatcher` as everything is, and addressed to the **lead's owners** — not the team.
That is the difference from `AnnounceSignals`: a discovery signal is news for everybody because
nobody owns a stranger, and an event about an account somebody is working is news for that person.
Sending it to the team would make it everybody's to ignore.

It is aggregated per run and per recipient — a run that matched six accounts to one member's leads
sends that member one notification saying six. A watch runs daily; the alternative is a feature
somebody switches off inside a week. A lead nobody owns falls back to the team, deliberately: an
unassigned lead with an acquisition against it is exactly the lead somebody should pick up.

### 9. The feed gains a scope, not a fourth status

`SignalScope` is `all` or `accounts`, and it is orthogonal to `SignalStatus`. "New events about my
accounts" and "converted events about my accounts" are both questions somebody asks, so the two
sets of tabs cross rather than concatenate; folding the scope into the status would have produced
six cases that were really two questions crossed. The status counts are scoped with the feed, or the
numbers on the tabs would answer a different question from the tabs.

### 10. Erasure reaches the watchlist, in Compliance's own transaction

A watchlist entry holds a company name, and a matched signal points at a lead. So `LeadAnonymiser`
deletes both — the entries naming the lead and the signals matched to it — in the same transaction
as the rest of the erasure, rather than leaving the next sync to notice. The sync drops an
anonymised lead as well, as a second line of defence for an anonymisation that happened by some
other route, and `TeamDataArchiver` exports `signal_watchlists.csv` so an export says what the team
asked us to watch.

## Consequences

**Good**

- A team points one list at their pipeline and Signals is answering a warm question the next
  morning, with nothing typed.
- A lead's page becomes a history of what has happened to that company, which is a small change with
  a large effect on whether the page is worth opening.
- The cold feed is unchanged. A team that wants discovery only sees exactly what it saw before.
- The name rule has one definition, in one file, read by the deduplicator and the matcher.

**Costs, accepted**

- A long list is searched slowly. Six days for 250 accounts is a real limitation of a per-run
  ceiling that exists because searches cost money; the screen says the number rather than hiding it.
- `Likely` will be wrong sometimes. It is labelled, explained and reversible, which is the most that
  can be promised about an inference over company names.
- A pipeline watchlist makes the cost of Signals grow with the pipeline. The cap (250) and the stage
  filter are the controls, and both are on the screen with the reason beside them.
- The Arabic for this phase's copy was written here and wants a native reader, as S8's and S9's did
  (`docs/brand/brand-tokens.md` §7).

**Found by review, after the first implementation**

The backend review found that **nothing outside the tests could set `signal_watches.signal_watchlist_id`**:
the column, the model and the whole run path were right, and the DTO, the request, the Action, the
resource and the form had never learned about it. Every entity test reached the state through a
factory, so a green suite coexisted with an unreachable feature. It is wired end to end now, and the
lesson is recorded in `docs/modules/signals.md` rather than here, because it is about how the tests
were written rather than about what was decided.

Two decisions above were wrong as first implemented, and both failed **toward** the thing this ADR
says to avoid:

- §4's ring advanced by entities _taken_ rather than entities _searched_, so a multi-region watch
  stepped past the accounts the per-run trim had dropped — the same silent, permanent loss the ring
  exists to prevent, one level down. The window is filled by **cost** now.
- §5's watchlist path labelled a legal-form-stripped hit `Exact`, which suppressed the confirmation
  prompt that is the entire point of having a weaker confidence. The precedence is now every
  _certain_ source before any _inferred_ one, and an ambiguous looser match returns null rather than
  the first row back.

The brand review changed §7's panel: the matched card used a `status.info` tint, which spent a
status colour on something that is not a status and put a third hue on a card whose one accent is
the type chip. It is neutral, which also keeps this phase off a token that is still pending
brand-owner sign-off.

**Open (⚖️ / later phases)**

- **Clustering** (S11): one acquisition still produces one card per article, and an account watch
  makes that more visible rather than less, because a name search returns every outlet that covered
  it.
- **Filings** (S13): the strongest account signals — a PSC change, a shareholding change — are
  filings, and a news search cannot find a filing reliably. A watchlist is the natural thing to poll
  a registry for, and S13 is where that happens.
- **Playbooks** (S14): "an acquisition at an account I own ⇒ draft an email to the owner" is a rule,
  and `AttachToMatchedLead` is one of its actions.
- `segment` as a source kind, per §2.
- A person entity is searched per definition, so a watchlist of people costs more per head than one
  of companies. Nothing in the screen currently says so, because the screen only offers companies.
