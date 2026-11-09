# Changelog

All notable changes to AlimaProspects are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); dates are ISO (UTC).

## [Unreleased]

### Changed — 2026-11-09 — The lead record reads as one screen

The page a member spends their day on had grown five different section shells and two different
places to put an "Add…" button: the contacts panel put its add action **below** the list at the
start edge, the tasks panel put its **above** the list at the end edge, four panels drew a cream
card by hand, four more had no surface at all, and "Why now" — the panel that answers why anybody
opened the lead — was the only one still on the stock shadcn card, so the most important thing on
the screen was also the one that looked like it came from a different product. None of that is a
bug any test would catch. It is just a screen that teaches you its layout again in every panel.

There is now one shell, `components/ui/panel/Panel.vue`, and the ten panels are all it. Its
`#actions` slot is in the header at the end edge, and **that is the reason the component exists**
rather than a style it happens to have: a slot in one place is the only version of this fix that
survives the next panel somebody writes. A `#description` slot took the standing hints that had
been sharing a row with the buttons and losing. Each panel names its own region through `useId`, so
a screen reader can move between ten panels the way a sighted reader moves between ten cards.

Three things fell out of it that are worth their own line. **"Why now" is the one emphasised
panel** — a heading-weight title where the other nine carry a DM Mono label — because a screen of
ten identical panels has no lead at all. **A surface nested inside a panel is `bg-background`**:
the forms and rows were `bg-card` on a `bg-card` panel, which is invisible, and the canvas colour
also puts the input border back over WCAG 1.4.11's 3:1 in light mode. And **completed tasks fold
away** behind "Show :count done", so a lead worked for a month is not a panel of struck-through
text — except for a task ticked on this visit, which stays put, because hiding it at the moment of
the tick took away the row focus was sitting on and turned undoing a mis-click into a hunt.

Reviewed for brand and for UX before landing. The UX review overturned one of my calls: an empty
state carries the action that resolves it **even when the panel header already has that action**,
because on a phone the header can be two wrapped rows above where the eye lands — so the contacts
empty state has its own "Find decision-makers" and "Add a contact". Three items are recorded in
`brand-tokens.md` §7o for brand-owner approval: the one-emphasised-panel rule, the hairline under
every panel header, and the product reading of §3's "max two weights per layout", which this
screen already stretched before any of this.

### Added — 2026-11-09 — A contact can be added by hand

A lead whose Contacts panel was empty got a sentence explaining why — Alima looks for role-based
addresses on a company's own pages — and nothing to do about it. For a company that publishes no
address anywhere Alima can read, that was the end of the road on the one screen whose job is to
answer "how do I reach these people". A member who had a card in their pocket had to put the whole
lead in again through the add-a-lead form to record it.

`POST /leads/{lead}/contacts` now takes a name, a job title, an email address and a phone number,
authorised by `LeadPolicy::work` like every other control on that screen, and the form sits in the
Contacts panel whether or not Alima found anybody — collapsed until it is asked for, because the
panel's first answer should still be _let Alima look_.

Three things about the row it writes. It is **not a reveal**: nobody paid a vendor for a number off
a business card, so the vendor-source columns stay null and there is nothing to charge to see it
(ADR 0029). It **still asks `ContactApprovalGate`** — storing is the first of that gate's two
moments, so an address on the team's do-not-contact list is refused before the row exists rather
than stored and filtered at send time, and refused _with a message_, because the member typed it and
is owed the reason (ADR 0032). And it is written **unverified**, with `email_verification_status`
left null for the normal verification to judge. The identity hashes go in through the model's own
setters, so a hand-typed address matches a suppression entry — which the add-a-lead form's own
contact field still does not do.

### Added — 2026-11-09 — The dashboard knows who is looking

The dashboard was built in Phase 13 and has been accurate ever since. It was also, from end to end,
about the **team** — and eighteen phases of new things to measure later, three of the questions a
person actually opens it with had no answer on it at all.

**What is waiting on me.** On a ten-person team "48 signals waiting" tells a member nothing about
whether any of them are theirs, and there was no way to find your own four companies in a list of
four hundred. The personal panel answers it: your open companies, your overdue tasks, what is due
today, trigger events at companies you own, and the next five tasks by date — each count a link to
the list it came from, because a figure on a dashboard that is not a way in is just a number.

**Two scopes on one card, said out loud.** The queues ignore the date filter and the activity line
does not. A task that went overdue last month is still overdue today, and a filter that hid it
would be a date control quietly deciding what somebody has to do; the panel prints which figures
the period moves rather than leaving a reader to assume one rule covers both.

**Can you reach them?** Fit answers _should we want them_ and is deliberately blind to missing data,
which is why an A-grade lead can sit on the list with no website and no contact. `leads.readiness`
has answered the other question since the lead-quality work, and had never reached a chart. Now it
sits directly under the fit mix in four bands, so a team whose A-grades are all in the bottom band
can see that it has a data problem rather than a targeting one. "Not assessed yet" is its own band
and not a zero, and the bar is drawn in a neutral tone with no status colour anywhere near it — a
low band is a gap in Alima's data, never a verdict about the company.

**What is left this month.** Allowance burn lived only on the billing screen, which is the wrong
place to find out that discovery stopped at three o'clock. It is now the last block on the
dashboard, outside every branch above it: an allowance runs on the calendar month in UTC, so it
says so rather than sitting silently under a filter that does not reach it, and it matters most to
the team whose selected window is empty — "nothing happened this week" and "we ran out on Tuesday"
are easy to confuse and only one of them is fixable.

**It reaches the plan through a seam, not an import.** `Reporting\Contracts\UsageSummarySource` is
the eighth of the seams Billing fills in (ADR 0012) and the first that is purely a read: it gates
nothing, so there is no permissive-versus-restrictive argument to have, and it is bound **outside**
`billing.enforce_plans`, because that flag decides what is refused. Reporting's own default answers
an empty list and the panel then draws nothing, which is the honest rendering of an installation
that meters nothing. The line DTO is deliberately the billing screen's own wire shape, so the
dashboard reuses `UsageBar` rather than growing a second bar that could come to disagree with it
about where a plan stops — minus the top-up buttons, because buying belongs on the screen with the
prices.

`my_work` is nullable, and that is the whole guard on the one way this could go wrong: a CSV export
and the AI Insights snapshot have a team but no person behind them, and inventing one would have put
somebody else's queue in a downloaded file. A null member also skips the two screen-only panels,
because no caller without a person behind it renders a dashboard — running a nine-metric allowance
summary for a nightly job that discards it is a recurring cost per team for nothing.

Four things the module and brand reviews changed before it shipped. The cross-tenant case here is
one user in **two teams**, not two users — every per-member filter still matches, so only the
`team_id` on each query stops the other team's work appearing, and nothing pinned that until a test
did. The three task counts no longer link anywhere: the leads list has no task filter, so pointing
them all at the same unfiltered list gave a screen-reader user three differently-named links to one
place. The window-scoped figures became three label-and-number pairs rather than one sentence
interpolating three counts, which Arabic's six plural forms cannot inflect. And the top readiness
band is "Plenty to go on" rather than "ready to approach", because whether a prospect may be
approached is `OutreachGuard`'s answer and a UAE signal carries a prior-consent restriction no
amount of contact data satisfies.

### Added — 2026-11-09 — PL7: leaving is a contract

Everything Alima has built so far ends on a screen. PL6 let a team take their list out as a file,
which is the crudest possible answer to "put this where I work" — it is a file somebody has to
remember to re-export. PL7 answers it properly, in two halves that are one module.

**Outbound webhooks.** A team registers an https endpoint, ticks the events they want, and Alima
posts each one as it happens: `lead.created`, `lead.updated`, `signal.detected`, `contact.enriched`,
`lead.sent_to_crm`. Signed, retried on a 1m → 12h schedule, switched off after twenty consecutive
failed deliveries with the admins told, and logged attempt by attempt with a redeliver button. That
is the whole Zapier / Make / n8n surface for the price of one HTTP POST, which is why it came first.

**The signature is the inbound one, pointed outwards.** `X-Alima-Signature: t=<ts>,v1=<hmac>` over
`timestamp.body` — deliberately the same construction `SignalWebhookSecret` has used since S13b. The
timestamp is **inside** the signature's scope, so a captured request cannot be refreshed by editing
it, and the body is the **raw bytes sent**, because `json_encode(json_decode($body))` is not `$body`
and signing a re-encoded payload would let anybody who can reorder keys keep a valid signature over
different content. The secret is **encrypted, not hashed** — producing a signature means recomputing
an HMAC, so a hash was never an option — and it is shown in the clear exactly once, by the call that
mints it. Rotation replaces it rather than adding a second valid one. The screen explains how to
verify it, because somebody building a receiver has that page open and a signature they cannot check
is a signature they will skip.

**The SSRF guard runs twice, and the second time it pins.** On save it is a usability control, and
stricter than the shared guard: **https only**, where `SafeUrl` allows plain http for a lead's own
website — a body carrying revealed contact details must not travel in the clear. At send time it is
the real protection, because DNS changes after a form is submitted and that is the whole of
rebinding: the addresses are approved and then **pinned** for the request, redirects are refused
because a `Location` is an address nobody checked, and a host that will not resolve is refused
outright. `FeedSignalSource` made that last call first, for the same reason — an endpoint we cannot
pin is one we will not post a customer's lead data to. The guard is shared, never copied.

**The delivery row is written before the request is made**, which makes the log honest (a worker
killed mid-POST leaves a `pending` row rather than no evidence) and makes redelivery a job dispatch
rather than a rebuild: the body it sends is what happened, not what the lead looks like today. A
redelivery is a **new row**, because the log must keep saying the first attempt failed. The payload
is built with the app's **own API resources**, so masking is automatic rather than remembered — a
value nobody has paid to reveal cannot reach a customer's Zapier because somebody forgot to think
about it.

**A CRM framework, with no connector yet.** `CrmConnector` behind a registry keyed by provider —
ADR 0008's pattern for the fourth time, so the push contains no `match` over providers — plus the
three tables, a single-use OAuth `state`, token refresh, the note template and the push itself.
Pipedrive is PL8 and HubSpot is PL9; both cards say "not available yet" rather than offering a
Connect button that 404s, and the whole framework is exercised by a fake connector in the tests.

**The export's argument applies again, one level out.** A CRM is a place a record goes in order to be
contacted from, and once an organisation is in somebody's Pipedrive no later opt-out can reach it —
exactly as a row in a spreadsheet is beyond every later check. So `ContactApprovalGate::allowsDomain()`
is asked **before any connector is reached**, a value nobody paid to reveal is omitted rather than
masked, and a suppressed address is dropped although the company is fine. A refusal is a **logged
row** and not a silence, because "why is this company not in my CRM" has to have an answer.

**Three brakes on the push, and all three are before the connector.** The suppression check; the
`synced_hash` skip, over the fields a CRM can actually see rather than over the lead row, without
which an auto-sync rule firing on every re-score would re-push every organisation a team owns,
nightly; and one unit of `BillingMetric::CrmSync`, held when the push starts and settled against
what moved. A skip and a refusal both give the hold back, because charging for the framework being
careful would make being careful cost a team money. `CrmRowAllowance` is the **seventh** ADR 0012
seam and the second bound inside `billing.enforce_plans`.

**The company link is written twice, and that is the design.** Immediately after the company upsert
with an **empty** `synced_hash`, so a retry updates the organisation we just created rather than
making a second one; then again with the real fingerprint once the note and the people are through.
An empty hash can never equal a `sha256`, so a push that died halfway is a real attempt next time.
Writing the fingerprint with the link — which is what this did first — meant a note endpoint having
a bad minute left the company in the customer's CRM with no note and no contacts, and every later
push answering "already up to date", silently, for ever.

**A dead credential and a refused one are different statuses.** `needs_reauth` is the customer's to
fix and stops the sync with the admins told; `error` is ours or the provider's and is pushable,
because the next attempt is how it recovers. The refresher retries exactly once on a refusal — the
second one means something other than a stale token, and a loop there is a loop against somebody
else's rate limit — and the Action is forbidden from downgrading `needs_reauth` to `error`, which was
a real defect a test caught: the sync would have retried a credential only a person can replace, for
ever, with nobody told.

**Reconnecting checks the account.** `integration_links` is a memory of "their id for our row", so
disconnecting does **not** delete it — a team who disconnects on Friday and reconnects on Monday
should update what they already have rather than create every organisation again inside somebody
else's CRM. Which is why a changed `external_account_id` clears the links, and why a provider that
reports no account is treated as a change: "we do not know" and "it is the same" must not be the same
branch.

**Auto-sync is off by default**, hangs off `LeadScored` rather than a sweep, and treats an unjudged
confidence as failing a floor rather than passing it — null is not zero (ADR 0028), and failing
toward _not_ pushing is the safe direction when the thing that cannot be undone is a record arriving
in somebody else's CRM.

The two reviews this phase runs changed five things worth naming, because each was a defect rather
than a preference: the partial-push rule above; `connect` becoming its own Owner/Admin ability, since
authorising the OAuth routes on `viewAny` let any member replace the team's CRM and the callback had
no check at all; `WebhookDispatcher` bound `scoped()` rather than `singleton()`, because as a
singleton an endpoint added after a worker booted received nothing until a restart; the failure count
`increment()`ed rather than read-modify-written, so twenty consecutive failures is reachable by an
endpoint failing in parallel; and a host that fails to resolve now retrying instead of being treated
as a permanent SSRF refusal, which had spent a delivery and a strike on every DNS blip. On the
screen: the one-time secret gained a copy button and an announced live region, rotating and removing
gained confirmations, a pending delivery stopped being rendered in amber (it is in progress, not a
problem), and Latin values keep DM Mono under RTL, where `0`/`O` and `l`/`1` in a hand-transcribed
secret otherwise become ambiguous.

Also: two notifications that both say Alima has **stopped** doing something a team believes is
running (`integration_needs_reauth`, `webhook_disabled`), neither configurable away from email
because the only screen that would show it is one nobody opens once an integration works;
`integrations:prune-deliveries` nightly, which never sweeps a pending row whatever its age, since a
twelve-hour backoff means one can legitimately sit pending most of a day; no `/api/v1` routes, so a
credential cannot decide where a team's whole lead flow is sent; and the whole screen behind
`prelaunch.webhooks` — **including the rail item**, which shipped ungated and so offered every
team not yet switched on a link that answered 404. The shell learned which pre-launch flags are
on from a shared prop for that, a **list** rather than the capability map beside it, because an
unlisted flag reads as off and an unlisted capability reads as available. ADR 0034 and
`docs/modules/integrations.md` carry the reasoning. 53 tests.

### Added — 2026-11-08 — PL6: leads leave in a file

The export has existed since Phase 10b: streamed, BOM'd, injection-proofed, and with the one column
set the importer reads so a team can round-trip their list through a spreadsheet. All of that stays.
What it could not do was everything a customer actually asks for — one column set means a file for a
CRM and a file for Alima are the same file and suit neither; it wrote whatever was in the database,
which after PL2 includes addresses a vendor was paid for and nobody has revealed, and after PL5
includes people who have asked us to stop; and it was free, while `BillingMetric::ExportRows` has had
an allowance on every plan since PL4 that nothing ever spent.

**A preset is a list of column keys, and that is the whole of it.** Every row is built as a keyed map
of every column the exporter can produce and then projected onto the preset's keys. Three things fall
out of that: "choose your own columns" is a preset rather than a feature (the custom list is the same
array, validated against the catalogue); a column cannot come to mean two things in two files,
because only one piece of code produces it; and `LeadCsvExporter::COLUMNS` could stay **frozen in its
original order**, so the round-trip test that ends in two merges and zero new leads still passes
untouched. CRM-ready is the only preset that changes the shape rather than the columns — one row per
contact with the company repeated — and a company nobody has been found at yet is still one row.

**Three things do not reach the file, and only one of them is the suppression list.** A value nobody
has paid to reveal is written as an **empty cell and never as the mask**: `j***@acme.com` in a
spreadsheet is worse than a blank, because something downstream will eventually try to send to it. A
company whose domain is on the do-not-contact list is **left out altogether, and counted** — this is
the one place in the product where honouring the list at the point of sending is not good enough,
since a row in a spreadsheet on somebody's laptop is beyond every later check, including an opt-out
recorded five minutes afterwards. And a suppressed address at a company that is otherwise fine is
**blanked while its row stays**, because the company is not the address, and that case is
deliberately not counted, so the figure a member reconciles a row count against means one thing.

Both of those rules reach the **Phase 10b export as well**, deliberately: they live in the shared
exporter, so a team without the flag also stops exporting unrevealed addresses and suppressed
companies. A flag is for hiding an unfinished feature, not for keeping a defect alive. What the old
path does not gain is the receipt, because it streams and has no row to carry the count.

The company-level question needed a third method on PL5's seam (`ContactApprovalGate::allowsDomain()`),
reading the same `outreach_suppressions` as the other two. The test that proves the export asks it is
an assertion over the **seam** — the exporter's constructor — in the list PL5 left open for "PL6's
export and PL7's sync". A behaviour test per screen only covers the screens somebody remembered to
write one for.

**Up to 500 rows the member waits; past that a worker builds it and a notification carries the link.**
One generator, one Action, called from both paths, because two things writing CSV would be two places
the escaping, the BOM or the suppression rule could differ — and the one that differed would be the
one nobody tested. The allowance is held **when the member asks** rather than in the worker: a
refusal read while they are still looking at the screen is worth far more than one that arrives as a
failed notification ten minutes later, and two large exports asked for at once must not both be told
there is room. It is settled against the rows actually written, so a suppressed company gives its row
back.

`ExportRowAllowance` is the sixth ADR 0012 seam and the cheapest yet, which is the argument for the
pattern. It is bound **inside** `billing.enforce_plans`, unlike PL4's `CreditReserver`, and the
asymmetry is the decision: a reveal is money leaving the building per unit, so its reserve stands
whatever the switch says; a row of a team's own data leaving costs us nothing.

**The file has a deletion deadline.** It lives on the private disk for seven days and
`leads:prune-exports` deletes it nightly — the file first, the row second, because a row pointing at
nothing answers "that export has expired" honestly while a file nothing knows about is never deleted
at all. An export is a team's whole lead list sitting outside the database's own retention sweeps,
and the one still there in two years is the one still there when a bucket is misconfigured.

**The download is addressed by a row id and the link is not signed.** The path is never a route
parameter; the download checks the row's team, the same policy that produced it, and the row's own
expiry. The plan asked for a signed URL good for 24 hours — what shipped needs the member's session
_as well as_ the expiry, which is narrower, and a signature is for a door with no login behind it
(PL5's verification link is the example). The download is deliberately not gated on the export
capability although creating one is: refusing somebody their own finished file because a plan changed
on Tuesday is punishing them for our timing.

Behind `prelaunch.export`, so every team keeps the Phase 10b export until the flag is turned on.
ADR 0033; 18 new tests.

### Added — 2026-11-17 — PL5: the compliance core

Two of this codebase's seams have shipped since PL3 with a permissive default and a comment saying
PL5 would fill them in. This is that. A person on the do-not-contact list is no longer stored as a
contact and nobody is charged to reveal an address they may not use — and both of those are bindings
rather than changes to an Action, which is why the seams were built where they were.

**The second seam is the interesting one, because it would have been wrong to make it the first.**
`ContactApprovalGate` answers _may we hold and show this_ before any money moves. TPS/CTPS screening
is not that question: a registration stops an unsolicited marketing **call**, not possession, so a
screener folded into the gate would have to refuse, and refusing would enforce a rule that does not
exist. `ContactNumberScreener` therefore returns nothing, stamps a verdict on the contact, and the
product withdraws the one-tap call button and shows a badge. The member's decision stays theirs. It
also runs _after_ the charge and swallows its own failures — they have already paid, and a slow
register must not cost them the credit.

**There is no licensed TPS adapter, and every number reads `unchecked`.** The registers are licensed
datasets and automated screening inside a product needs a licence that permits it; that is an open
item, not code. What matters is that `unchecked` is **not** `clear`: a provider that is unavailable,
fails, or answers something the code does not recognise cannot produce a pass, a stale verdict stands
rather than being dropped when nobody can replace it, and a number we cannot place at all is left
alone rather than declared outside the registers. The temptation in an unlicensed build is to default
to clear so nothing looks broken; that would put a green light on every number in the product.
`tps_checks` is a platform cache of hashes — the register charges per lookup and its answer is a fact
about the number, so keying it per tenant would bill two customers twice for the same answer.

**PECR's corporate/individual distinction is derived and never stored**, and only one path reaches
"corporate": a UK lead with a registration number _and_ a company type naming a body corporate.
Everything else is `unknown`, which warns exactly as `individual` does. The temptation is to read "no
company number" as "sole trader" — that is not the same claim, and most of what a maps provider
returns has no number. It warns rather than blocking, because whether to email a sole trader is the
customer's call as controller, and it answers **null** for a non-UK prospect rather than `unknown`:
PECR is a UK instrument, and a subscriber type on a Dubai lead would read as "marketing email is
allowed", which is the opposite of the truth there.

**And the product grew a public door.** `/privacy/request` takes a request from somebody with no
account — stop, tell me, delete it, correct it — and **nothing happens until they click a link in
their own inbox**, because an unverified form is a way to erase a competitor's prospects by typing
their address into it. On verification the platform-scope suppression is written _first_, before
anything is deleted: the person erased and re-contactable is the one outcome worse than doing
nothing. Only an erasure purges, and the purge walks every tenant by calling the existing per-team
erasure rather than reimplementing it — a second implementation would be a second answer to what
erasure means. No response says whether we hold the person. The operator queue can **close** a
request and has no route that suppresses or purges, because an operator who could choose not to
suppress would be a compliance hole with a permission on it.

Also: `SuppressionType::CompanyNumber`, and a bulk load that splits on separators rather than parsing
a CSV — a member says what the values _are_ and every cell is then read as one, so a one-column
export and a pasted spreadsheet column both work with no mapping screen. The cap is on values read
per request and a request that hits it **says so**; an import that silently added 1,412 of 5,000
would leave a team believing a list is covered when most of it is not.

Review changed four things worth naming, because each was a hole rather than a tidy-up. The
suppression and the purge act on the **email only**: the click authenticates a mailbox, so acting on
a phone number anybody could have typed into the form would let somebody have a stranger's — or a
competitor's switchboard — blocked across every tenant, which is a denial rather than a leak and
the half nobody tests for. A repeat submission now **rewrites nothing** and sits behind a
per-address mail cooldown. The purge is queued **after the commit and re-queued when `verified_at`
is set with no `purged_at`**, because a queue that was down at the moment of verification left the
row verified, the person told it was done, and every later click returning early — an erasure lost
for good. And `purged_at` is stamped only after a pass with **no failed teams**, the job throwing
otherwise, since it is what the queue reads as "done" and reporting success with a failure counted
beside it both misled an operator and took the job out of reach of its own retries.

See **ADR 0032**, `docs/compliance.md` §3.15–§3.17 and `docs/modules/compliance-privacy.md`.

### Added — 2026-11-08 — PL4: one ledger for the things that cost money per unit

PL3 built a waterfall that can spend real money per lead, behind a reserve/commit seam whose default
was a no-op. This is the thing that makes the seam real — and the shape of it was decided by a rule
this codebase has had since Phase 16: **nothing increments a counter at the point of use.** An
increment the app forgot is an allowance a team silently overspends; one made twice is an allowance
they are wrongly refused. So the three meters Billing already had each read a table somebody else
owns, written by the work itself.

A contact reveal has no such table. It is a discrete chargeable act with nothing behind it but the
fact that somebody clicked, so `usage_ledger` is where that fact goes — and it is deliberately the
**same shape** as the three it joins: append-only, read by aggregate, never incremented. Only the
source differs, which is the whole point of `BillingMetric`.

**The plan's second table was not built.** It asked for a `team_allowances` projection carrying
`included`, `topped_up`, `used` and `reserved`. Every one of those is derivable from the rows plus
the plan, and a second table holding the same answers is a second answer — the one that drifts being
the one a customer reads. What a reserve genuinely needs is a **mutex**, and that already existed:
the `usage_meters` row for the same `(team, metric, period)`, uniquely keyed on precisely the thing
being serialised. The reserve locks it and then **recounts inside the lock**, because the lock alone
would have serialised two requests that both read "one left".

**A hold is settled by writing beside it, never by updating it.** "We held three and spent one" is a
different fact from "we held one", and a disputed month has to stay readable. Settling is idempotent
on the handle, which is the failure that actually happens: a worker dies between its last provider
call and its commit, gets retried, and a double commit is a team charged twice for one run.

**An absent allowance means zero for the six new metrics, and unlimited for the three old ones.**
That asymmetry is the argument `StubSignalQuotaSource` already makes. Leads and AI credits are
things the product does, so a plan that does not mention them has no ceiling. A reveal costs us
money per unit, so a plan that does not mention it includes **none** — a config that forgot one must
fail closed rather than hand out an unmetered vendor bill. For the same reason there is no unlimited
contacts plan at all: Enterprise gets large numbers somebody chose, not nulls.

**The fifth seam is bound whatever `BILLING_ENFORCE_PLANS` says** — the first of them to be. A
reveal is money leaving the building per unit, so the reserve path is a cost guard rather than a
plan feature, exactly as the AI credit allowance is. What still follows the flag is the _size_ of
the allowance.

**Contact metrics never meter; they top up.** A metered reveal is an uncapped vendor bill, so
`overageAllowed` is false for all six whatever the plan says. The commercial answer is extra
allowance bought outright, before the spend — three metrics only (leads and signal searches are
standing instructions to spend, and charging for export rows is charging for a team's own data on
its way out). The client sends an **option index**, never a Stripe price: a request that could name
a price could name a cheaper one. Nothing is granted at purchase, because a grant written then is an
allowance given to somebody who closed the tab at the card form; it arrives on the paid webhook,
idempotent on the Stripe event id.

That listener is on **`WebhookReceived`**, not `WebhookHandled`, and the difference is not cosmetic:
Cashier fires `WebhookHandled` only for the event types it has a handler method for, and
`checkout.session.completed` is not one of them. A listener on the other event would have granted
nothing, silently, for ever, with a 200 going back to Stripe. (Which turned up a defect that is
**not** fixed here and is written down in `docs/prelaunch/progress.md`: `invoice.payment_failed` is
in the subscription listener's allow-list and Cashier has no handler for it either, so the
"your card was declined" notification added in Phase 19b has never fired.)

**The margin guard stops one thing.** Vendor spend over the period against what the team pays; above
0.35 it refuses **phone reveals** and nothing else, because a guard that stopped everything would
turn a pricing problem into an outage for a customer who is paying us. A team with no price is
exempt — the ratio has no denominator, and reading "any cost at all" as a breach would refuse the
first phone reveal of every trial. The check runs before the hold, so a refusal leaves nothing
unsettled.

And `enrichment_attempts` joined the platform cost rollup, which is ADR 0016's own risk note
arriving: without it the margin screen would have flattered every tenant by the one cost that scales
with use — authoritative-looking and short.

Three things the plan asked for and did not need code: **rollover** (an allowance is derived per
period, so a new month starts fresh on its own), the **low-balance notices** (`UsageAlerter` is
generic over whatever the summary returns, so the 80% and 100% messages covered the six for free),
and **`can-*` gates** (`remaining()` is the one answer, asked at the point of spend).

See `docs/decisions/0031-the-usage-ledger-and-top-ups.md` and
`docs/modules/billing-plans.md` → Key flows §6–§7.

### Added — 2026-11-15 — PL3: the waterfall, and the one judgement it makes

PL2 gave the module contracts and no orchestration. This is the thing that uses them: ask vendors in
order, record every call, and **stop at the first good-enough answer**. Everything expensive about
enrichment is in that last clause, and it is the only judgement this phase makes — the vendors,
their order and their prices are all config.

**`enrichment_attempts` is append-only and it is the money.** Every paid call lands there with its
cost whether it found anything or not, because the questions that matter commercially are all about
the misses: which vendor is being paid to say "no", what a hit really costs once the failures are
counted, whether a team's reveals are worth their plan. Nothing updates a row, and everything
downstream reads the attempts rather than the run's summary — if the two disagree, the attempts are
right.

**Three kinds of nothing, kept apart.** A miss is a vendor doing its job and not knowing; an error
is a vendor letting us down; a skip is a call we never made, and it says why — breaker open, rate
limited, or a question with nothing to go on. Folding them together would make an outage look like
a vendor that is bad at its job, and would quietly fold the days it was down into the hit rate a
renewal conversation turns on.

**`accept_all` is not good enough by default.** A catch-all domain cannot _disprove_ an address,
which reads as a pass and is not one, so the verify waterfall keeps going — a team that stopped
there would be paying for confidence it had not bought. A team that would rather stop early can set
it.

**The reservation brackets the spending, in the job and not the request.** Taken at request time it
would be held for as long as the queue is deep and released by nobody if the worker died; released
in a `finally` for the same reason. The commit reads what the **attempts** say was spent, because a
run whose worker died between its last call and its summary still owes for the calls it made. And
`$tries = 1`: a retry would re-pay for a waterfall that has already run.

**A refusal is not a failure.** A plan ceiling, a flag that is off and a lead with nothing to
enrich are recorded as refusals carrying a code — otherwise every team at its allowance would raise
the platform's failure rate on the provider panel. Two refusals leave no run at all: a capability
refusal says the feature is not theirs, which is a sales fact rather than a cost event, while a
spent allowance is a cost event a team must be able to see.

**Phone numbers go through libphonenumber**, because a national number is not a country code glued
to the digits — `020 7946 0000` is `+442079460000` and the leading zero disappears. Getting that
wrong produces a valid number belonging to **somebody else**, so a number it cannot read with
confidence keeps its raw form and gets no tap-to-call. (Found while testing: `07700 900xxx` is
Ofcom's reserved-for-drama range and is correctly rejected as invalid — which is exactly the number
documentation tells you to use.)

On screen, behind `prelaunch.enrichment`: **Find decision-makers** and **Find their details** on the
lead, a live spinner over the team's Reverb channel, masked values with a **Show (1 credit)** button
where a vendor was paid, tap-to-call, tap-to-email and a WhatsApp link for a mobile only, a bulk
**Find contacts** on the leads list, and per-vendor hit rate plus **cost per hit** on the admin
provider panel — per hit rather than per call, because a finder that misses two times in three costs
three times its sticker price for every address it does find.

Two bugs fixed in the same phase that made them reachable: the mobile draft screen would have
offered a **masked** address as a recipient, and an autowired vendor adapter shared Lead Sourcing's
circuit breaker. See **ADR 0030**.

### Added — 2026-11-14 — PL2: the shape a paid data vendor fits into

The module that reads contacts off a company's public pages can now be _told_ things by a vendor
instead. No vendor is connected — ADR 0027 §2 is still open — so this is the shape, built so PL3's
waterfall can be written against something real.

**Four narrow capabilities, four contracts, one registry.** `PersonFinder`, `EmailFinder`,
`EmailVerificationProvider` and `PhoneFinder` over a shared `EnrichmentProvider`, resolved in the
order `config/enrichment.php` names — the third instance of the pattern ADR 0008 set and ADR 0023
repeated: a class per vendor, and never a branch in the orchestrator. Narrow because vendors sell
all four as one call, and buying it that way means paying for three answers to get the one that was
wanted. A provider claiming a capability whose contract it does not implement is refused **at
registration**, not inside a queued job after the vendor has been paid.

**A miss is an answer; a failure is an exception.** Nothing found is an `EnrichmentResult` with a
null value — the ordinary outcome, and the reason a waterfall exists. A provider that actually
failed throws, with three named causes because they are three different decisions: a
misconfiguration fails identically for ever, an outage is worth the next provider, and an
unreadable response means our mapping is wrong and every result stored from that vendor is suspect.

**Only one real adapter ships, and the missing one is the finding.** ADR 0027 asked for a reference
finder _and_ verifier. ZeroBounce's request and response could be pinned from the vendor's own
published docs; no email finder's response fields could, so **no finder adapter ships at all**. A
reference adapter built on a guessed shape is worse than none: it looks finished, PL3 gets written
against it, and the first live call proves the mapping wrong with a bill attached. The verifier is
disabled by default, tested against `Http::fake()` fixtures only, and has never made a live call.

**Masking follows who was paid, not whether a value exists.** The obvious implementation — mask
every address — would have hidden the contacts the free-tier extraction has been finding since
Phase 8 and that Outreach has been sending to since Phase 11, a regression shipped inside a
feature, and charged a member to see something printed on the prospect's own contact page. So
`requiresEmailReveal()` asks whether a vendor was paid. A **switchboard** number is never masked
even from a vendor: it is the company's published number, and charging to reveal it is a toll. The
resource sends one already-masked field per value — a `masked_email` beside a plain `email` is a
mask any client can step over.

**One definition of an identity, and the suppression list now shares it.**
`Core\Support\ContactIdentity` holds the normalisation, hashing and masking, and
`Outreach\Enums\SuppressionType` delegates to it with no change in behaviour. Two tables hash an
identity now, and if they normalised differently the failure would not be a missed index — it would
be an email sent to somebody who opted out, with nothing to show it had happened.

**`EmailVerificationStatus` grew, which fixed a bug nobody had hit yet.** It gained the five
outcomes a paid verifier can reach (`verified`, `accept_all`, `risky`, `undeliverable`,
`unverifiable`) as cases on the same enum rather than a second vocabulary beside it. Four places
compared `=== MxFound`, correct only while that was the best value that existed: the moment a vendor
could return something better, `LeadReadiness` would have marked a lead **down** for having better
data and `LeadDeduplicator` would have skipped the strongest evidence two leads are one company.
All four now ask `isRoutable()`.

Also found the hard way, by a test that passed vacuously: `CircuitBreaker::class` is _Lead
Sourcing's_ singleton, so an autowired vendor adapter shared a breaker with Companies House.
Enrichment binds its own, and each adapter explicitly against it.

`lead_contacts` gains the person's parts, per-value identity and provenance, the reveal stamps and
its own `enrichment_state` (per contact — a lead can be fully enriched while one of its four
contacts is still having its mobile looked for). Nothing changes for an existing contact; with no
vendor configured, nothing is masked, because there is nothing yet that masking applies to.
See **ADR 0029**.

### Added — 2026-11-13 — PL1: every lead now says why it is worth a call, and when

A lead was a company record with a grade on it, which answered _should we want them_ and left the
question a salesperson actually asks — _why this morning rather than last month?_ — unanswered.

**`lead_evidence`** is now a lead's history: one row per fact, each with the source's own name, the
date the thing **happened** and the date **we found it**, and the source's weight stamped on at
capture. `leads` gained `confidence` (how much the evidence is worth), `timing_score` (the strongest
trigger event) and a `why_now_summary` of one or two AI sentences that may use the supplied evidence
and nothing else. `teams` gained `min_lead_confidence`, its own floor for "too thin to show me".

**Every lead has at least one reason, structurally.** `LeadProvenanceObserver` hangs off the row
rather than being called from each creating Action, so discovery, a CSV import, a hand-added company
and PL10's sample report all record where the company came from — and so will the next path somebody
writes. A converted signal is skipped because `ConvertSignalToLeadAction` now writes the **article**
instead, with the outlet and the publication date, in the same transaction as the lead's timeline
entry.

**The arithmetic is deliberately boring, and four of its decisions are about lies the alternatives
would tell.** Confidence is the **strongest** item plus a corroboration bonus for **distinct
sources** — a sum would let forty web hits out-argue one Companies House filing, and an average
would mean adding a weak reason to a strong lead lowered its confidence, so a team would be marked
down for finding out more. Only a trigger event feeds the timing score, because a company's sector
is as true next quarter as today. Reliability is **stored, never looked up again**, so re-weighting
a source tomorrow does not restate what the feed already explained today. And **null is not zero**:
a lead nothing has been judged on is never hidden, or a fresh discovery run would look like it found
nothing.

**Flagging a fact as wrong lowers the number immediately** — the whole calculation is arithmetic
over rows the request has just written, so "within a queue cycle" was never good enough. Only
`wrong` moves anything; "out of date" is what decay already measures, "not relevant" is about the
team rather than the fact, and "useful" is what the post-launch outcome loop will learn from. A
disputed item is dropped from the calculation rather than weighted zero, because left in it would
still have counted towards corroboration — so disputing a fact would have _raised_ the confidence by
agreeing it exists.

**The summary never appears without the evidence it was written from.** The prompt (`lead.why_now`,
temperature 0) is told it may use only the supplied items, each wrapped in `UntrustedContent`, and a
test asserts the model is handed exactly those. But the real protection is the panel: the dated,
attributed, linked facts are always beside Alima's sentence, because a reader cannot check a prompt
and can check a headline with an outlet's name on it. Credit exhaustion, an unusable answer and an
over-long input each cost the sentence and never the facts.

On screen: a why-now line and a colourless confidence pill on each list row, a **Why now** panel
at the top of the lead with the evidence timeline and a four-item flag menu, two new orderings
(**Most urgent**, **Best evidence**), filters by evidence source and kind, a "N hidden — show them"
line above the table, and the threshold on Team settings. The mobile app gets the line and the panel
read-only: the evidence is useful in the field, and disputing a fact changes the figure for the whole
team. No status colour anywhere — confidence is a statement about our data, not a verdict on the
company.

`evidence:backfill` gives pre-PL1 leads the evidence they would have been created with, including
the events that were only ever **matched** to them (S10) and so were invisible on the lead.
Idempotent, and resumable: a lead with reasons but no figure is still picked up.
`evidence:source-quality` reports the wrong-flag rate per source and deliberately changes nothing.

Behind `prelaunch.why-now`, the application's **first Pennant flag**. The calculation runs for every
team while the flag is off; the _suppression_ is gated inside `LeadQueryBuilder`, so a team without
the feature sees exactly the list it saw yesterday — a list that honoured the column without asking
the flag is how a dark launch stops being dark. See **ADR 0028**.

### Added — 2026-11-08 — Pre-launch recon (PL0): the plan mapped onto the codebase

`pre-launch.md` (PL0–PL12) is now readable against what actually exists. `docs/prelaunch/recon.md`
inventories the lead and its neighbours, the signal→lead conversion, scoring, AI, billing,
compliance, export, the frontend and mobile, queues and the test setup, and carries a **gap table**
plus **binding per-phase adapt notes**. The headline finding is that roughly a third of the plan is
already built under other names — `outreach_suppressions` is its `suppression_entries`,
`lead_contacts` is its `contacts`, `lead_scores.score` is its `fit_score`, and the streamed CSV
export, circuit breaker, SSRF guard, AI cost ledger and usage meter all exist — so PL5 and PL6 are
extensions rather than new modules. **ADR 0026** records the scope, the phase order and the four
places the plan loses to this repository (one `default` queue until PL12 has depth numbers; ADRs in
`docs/decisions/`; plan limits in `config/billing.php`; no second suppression or contact table),
plus three rules the later phases inherit: monetary amounts are **micros**, a Pennant flag and a
`Capability` answer different questions and a flag is deleted once its feature is live, and
enforcement stays in the guards. **ADR 0027** amends ADR 0008 to admit paid enrichment while
leaving the vendor slate `DECISION PENDING` — a vendor is viable only with written confirmation that
embedding its data in a SaaS product is permitted — and fixes the architecture that does not depend
on it. `docs/prelaunch/progress.md` tracks the phases, the nine planned flags and the ten open items
only Alex can close. No application code changed.

### Changed — 2026-11-08 — The hero card now opens every screen

The eleven screens still using a plain header carry the Ink `PageHero` (compact) instead:
`leads/Create`, `leads/Import`, `leads/Show`, `icp/Edit`, `lead-sourcing/Show`, `scoring/Show`,
`outreach/Show`, `outreach/Suppressions`, `notifications/Index`, `compliance/Lia` and
`compliance/Dsar`. This answers the open brand question in `docs/brand/brand-tokens.md` §7a — the
hero belongs to every screen, not only the numbered sidebar destinations — and the four rules that
follow from it (which number a sub-page borrows, a company name as the Lime `accent`, the hero
button variants, and a record keeping its cream card underneath) are recorded there as §7m. The
settings pane, the auth screens, the public pages and the admin panel keep their own headers.

### Fixed — 2026-11-08 — Enrichment stored malformed field values

A real lead came back with `employee_range` = `11-50','locality':'London'}]}<br>Skip<br>` — the
extraction model leaked a fragment of its own JSON structure and some page markup into the field,
and nothing sanitised it, so it rendered as garbage on the screen (and the scorer reads this field
as a numeric band). `ExtractedFacts` now strips HTML and decodes entities on **every** extracted
text field, and normalises `employee_range` to a size band: a value carrying structural junk
(quotes, braces, brackets, tags) is reduced to its leading size figure (`11-50`) or dropped. A data
cleanup re-runs the stored values through the same normaliser.

### Fixed — 2026-11-08 — ICP composer refused valid briefs

"Draft my profile" came back with "Alima drafted something it could not turn into a profile" for
perfectly good descriptions like _"a design and marketing agency looking for small businesses and
startups that need websites, branding, or social media content"_. The `icp.compose_profile` prompt's
schema made the `profile` object **optional**, so the model satisfied the single required field and
returned `{"is_viable": true}` with no profile at all — which then failed the save-path validation.
Prompt **v2** makes `profile` required (still nullable, so a genuine refusal is just
`is_viable: false` + a reason), and teaches the model to handle **need-based / horizontal** customers
— an agency whose buyers are defined by a need rather than an industry now drafts from company size,
geography and decision-maker titles instead of stalling. A viable-but-empty draft now refuses with a
nudge rather than filling the editor with nothing. v1 stays seeded for traceability.

### Changed — 2026-11-08 — Scoring: match by meaning, defer when unsure, and show the team why

Leads kept coming back C/D even after lead discovery improved. Root cause: the deterministic scorer
compared heterogeneous vocabularies with a literal `str_contains`, so a genuinely-good lead missed on
its heaviest criterion (usually industry) the moment enrichment filled an industry in — better
enrichment made scores _worse_, because it flipped free-pass `Unknown`s into hard-zero `NotMatched`es.

- **Defer, don't fail.** A new `CriterionMatch::Weak` is for data the lead _has_ but the rules can't
  confirm in a different vocabulary. It abstains from the rule score (like `Unknown`) and is **handed
  to the AI half** to adjudicate — `scoring.qualitative_fit` **v2** takes a `{{deferred}}` block of
  exactly those gaps. A confident `NotMatched` is kept for a non-overlapping size band, a wrong
  location, a different _known_ sector, a seniority below the floor.
- **Match by meaning.** `SectorTaxonomy` places an industry on a canonical sector, so "SaaS" and
  "cloud software" match and "logistics" still doesn't; `SeniorityLadder` ranks titles, so "Director
  or above" accepts a CEO. Both live in `config/scoring.php` and a team can grow them.
- **The blend follows the evidence.** The deterministic half is now weighted by its **coverage**
  (`assessed / (assessed + deferred)`), so when the rules punt the heavy question to the AI half, the
  AI half carries the score instead of a light matched criterion pinning it high.
- **A calibration preview.** The ICP screen now shows how the active profile is actually grading the
  team's leads — the grade mix and each criterion's match rate — so a team can see which criterion is
  sending leads to C/D and tune it. Built over stored scores via a new `IcpCalibrationSource` seam
  (ICP owns it, Scoring fills it); read-only and AI-free.

See `docs/modules/scoring-matching.md` and `docs/modules/icp.md`.

### Added — 2026-11-07 — Enrichment: reach the leads that used to come back empty

Discovery returns a lot of thin records — a Companies House lead has a registered name and a company
number but no website and no contacts, and enrichment hung entirely off a website, so those leads
enriched to nothing. Four config-gated additions widen what a lead actually yields:

- **Website resolution.** When a lead has no website, `EnrichLeadAction` asks Lead Sourcing's new
  `CompanyWebsiteResolver` (Serper, ADR 0010) for a candidate homepage, then **confirms** it is the
  company's own site — the fetched page's name and the domain must overlap the lead's distinctive
  name tokens — before trusting it. A search returns competitors and directories for the words that
  name a business, so an unconfirmed site is **discarded, never pinned** (the direction S10 warned to
  fail in). This alone unlocks the whole pipeline for every registry lead. Best-effort and never
  fatal; off with `ENRICHMENT_RESOLVE_WEBSITES=false`.
- **Multi-page gather.** `WebsiteTextGatherer` now reads the homepage **and** the conventional
  contact/about pages — where emails actually sit — and extracts over the combined, capped text in
  **one** AI call (the fetches are free; the model call is not).
- **The Places phone.** Google Places' field mask now requests the switchboard number (and rating /
  status), stored on `leads.phone` at discovery and used as a contact when the page states none.
- **Inferred contacts.** When a page names people (`lead.enrich_extract` **v2** adds `people`) but
  states no routable email, `EmailPatternInferrer` guesses one address per person from the company
  domain, keeps the ones the domain can route to, and stores them flagged `is_inferred` with a capped
  confidence. An inferred address is a **guess** — the MVP has no SMTP probe (ADR 0008) — so it earns
  **nothing** toward a lead's readiness and is flagged personal; whether it may be approached stays
  `OutreachGuard`'s call. Off with `ENRICHMENT_INFER_EMAILS=false`.

See `docs/modules/enrichment-data-quality.md` and `docs/modules/lead-sourcing.md`.

### Added — 2026-11-06 — ICP: draft a whole profile from a sentence

The ideal-customer editor started from a blank page: a member had to know what each criterion type
means and what weight to give it before the product could grade anything. It now takes a description
— _"we are a design agency looking for startup food businesses that need branding on their
packaging"_ — and fills itself in. `ComposeIcpAction` runs the new `icp.compose_profile` **reasoning**
prompt and returns a `DraftIcp`: a name, a description and 5–10 weighted criteria plus exclusions,
flashed back to the editor for the member to change and save. **The call persists nothing** — ADR
0020's arrangement from the Signals builder, for the same reason: the active profile grades every
lead in the account, so a prompt that wrote one directly would be a prompt with write access to the
team's grading.

The prompt's actual job is an **inversion**. A brief describes the seller as much as the buyer, and
returning the seller's own trade is the failure this codebase has already had twice
(`business_intel.background_check` v2, `lead_sourcing.shape_query`) — so the model is given the
team's own `BusinessProfile`, told never to return their trade as an industry signal, and told to put
it in an **exclusion**, where a competitor belongs. `IcpData::rules()` was extracted so the draft is
re-validated against exactly the rules the editor's save posts through, because a prompt describes a
constraint and does not enforce one — though a _bad row is now dropped rather than sinking the draft_,
because refusing a whole profile over one unusable criterion throws away nine good ones and tells the
member their clear description was not understood. Two things the first live call taught, both fixed
here: **`profile` must be `required` in the schema** (it came back as literally `{"is_viable": true}` —
schema-valid, provider-valid and useless, because `SchemaConverter` passes `required` to the model and
an optional field is one a model may decline to write), and the drop count is logged, since silent
repair is otherwise invisible. `is_viable: false` with the model's own reason is a first-class
answer, and it fills nothing in. No plan capability — the hand-written editor is the same screen, so
gating the shortcut would only make the cheaper plans worse than they were the day before it existed;
the ceilings are the AI credit allowance and a per-member `throttle:10,60`.

### Added — 2026-11-06 — Phase 7c: the AI lead source, and why a discovered lead could not reach an A

A fourth source in the discovery picker, behind the same `LeadSourceProvider` contract as the other
three and the first with **no index behind it**: `AiLeadSourceProvider` asks the model to name the
companies itself (`lead_sourcing.ai_discovery`, reasoning tier), with a trade, an approximate
headcount band, a place, an ISO-2 country, a homepage and one line of why each fits.

The reason it exists is a scoring fact, not an AI one. Companies House, Places and web search return
a **name and a website and little else** — a register publishes an SIC code, a listing a category,
neither a trade or a headcount — so an ICP's Industry and Company size criteria were `Unknown` on
every freshly discovered lead, and `DeterministicScorer`'s rule 2 keeps an Unknown row out of _both_
sides of its ratio. A lead was therefore being graded on its geography alone, which is why nothing
came back above a C before enrichment had run. `SourcedLead` now carries `industry` and
`employeeRange` for that reason, flowing through the existing merge rules untouched: a null never
overwrites, a weaker source only fills gaps.

Four decisions in it are the interesting part, and three generalise.

**It ranks last on purpose** (`LeadSource::rank()` = 0, below web search and a converted signal).
Every other source reports what a register or a crawler holds; this one reports what a model
recalls, and it is the only source in the product that can be _fluently wrong about a company that
does not exist_. Ranking it below every index means any other source that finds the same company
corrects its fields on the merge, it may only ever fill a gap, and — at or below rank 1 — Enrichment
already treats the name it stored as **provisional** and replaces it from the company's own site.
The claim itself is kept on the row (`leads.raw.claim`, with the model and prompt version), because
a lead whose only provenance is "a model said so" has to say which model, and when.

**`costMicros()` returns 0, and not because the call is free.** The spend is already recorded, per
team and per model, on `ai_requests` — which is exactly where `PlatformRollup` reads Claude cost
from. Recording it again on `discovery_runs.cost_micros` would count one request **twice** in the
platform margin, under two provider dimensions. A second ledger for one event is not redundancy, it
is a disagreement waiting to be discovered by whoever trusts the wrong one.

**An AI failure fails the run, where query shaping degrades.** `DiscoveryQueryShaper` falls back to
a deterministic query on any AI error, including exhausted credits, because discovery still works
without it. Here the AI _is_ the source: there is no degraded answer, and an empty list would read
to the member as "no companies match you" when nothing was ever asked. An exhausted allowance gets
its own `lead_sourcing.ai_allowance_exhausted` code rather than reusing `quota_exhausted`, because
the AI allowance and the lead allowance are fixed by doing different things and the wrong message
sends somebody to the wrong screen.

**The schema has no person on it at all** (ADR 0025) — not a founder, not a contact — so there is
nothing to filter out afterwards. And a country that is not two letters, or a website that is not
shaped like a host, is **dropped rather than stored**: `leads.country` is a two-character column, so
"United Kingdom" asked for would have been kept as `UN` and read as a country by the next person to
look at the row.

What it cannot do is verify. A fabricated company is a fabricated prospect and looks exactly like a
real lead; the prompt's repeated instruction to name fewer rather than pad, the rank, the stored
claim and the dropped fields bound the damage without checking anything. Enrichment fetching the
site is the de facto check, and a lead whose site cannot be fetched is the signal worth watching. A
web-search-grounded variant is the obvious next step and is not built.

No migration, and no frontend work beyond the shared type and a label: the discovery picker has
always been rendered from the enum.

### Added — 2026-11-06 — Phase Q4: a lead has two numbers, and they answer two questions

`lead_scores.score` answers **should we want them**, and it is deliberately blind to missing data — a
thin lead must not be marked down for what we have not found out yet (`scoring-matching.md`, rule 2).
That is correct, and the price of it is that an **A-grade lead can have no website, no contact and no
routable email**, with nothing on the list to tell it apart from one you could phone this afternoon.

`leads.readiness` answers the other question — **can we actually reach them** — so it is precisely a
measure of what we hold, which makes it the one figure _allowed_ to punish a gap.
`Enrichment\Services\LeadReadiness` scores seven things we already store (a website, an industry, a
size, a location, a contact, a contact at `mx_found`, a non-role mailbox) with the weights in
`config/enrichment.php`, normalised by the ones in play so adding a factor cannot inflate a lead and
a weight of **0** switches one off. No AI, no network, no new query: a **record over the lead's own
columns**, recomputed, never incremented (ADR 0012's rule).

Three things about it that are load-bearing. **Null is not zero**: a lead we hold nothing about
beyond its name gets _no figure_, because "not computed" and "nothing to reach them by" are different
states and a zero reads as the second — so the list orders nulls **last explicitly**, since MySQL
and SQLite disagree about where one sorts. It is computed **after de-duplication, on the survivor**,
because a merge moves contacts across and fills gaps from the loser, and a figure taken before it
describes a row that no longer exists. And it is **not a grade**: no letter, no `status.*` colour, a
neutral meter — a readiness of 20 is a gap in our data, not a verdict about the prospect, and
dressing it as one would turn "we have not enriched this yet" into "this company is poor".

Surfaced in the leads list **inside the existing "Data" column** rather than a tenth one — that
column already asks how complete a lead's data is — with its header now sortable (`LeadSort::
Readiness`, "Ready to contact"). Exposed on `LeadResource` and the shared TS lead type; EN/AR strings
at parity. Nothing was wired into the workspace's own create paths: each of them already queues an
`EnrichLeadJob`, and the stamp sits outside `applyFacts` so it runs whether or not the AI was called.
Reviewed by the brand agent — no violations; its one finding, the screen-reader label using raw
digits where the visible figure was locale-formatted, is fixed.

| Files | `app/Modules/Enrichment/{Services/LeadReadiness,Actions/EnrichLeadAction}.php`, `app/Modules/LeadSourcing/{Models/Lead,Http/Resources/LeadResource}.php`, `app/Modules/LeadsWorkspace/{Enums/LeadSort,Services/LeadQueryBuilder}.php`, `config/enrichment.php`, `database/migrations/2026_11_11_100002_leads_record_how_ready_they_are_to_contact.php`, `resources/js/pages/leads/Index.vue`, `resources/js/shared/types/{lead-sourcing,leads-workspace}.ts`, `lang/{en,ar}.json`, `docs/api/openapi.json`, `tests/Feature/{Enrichment/LeadReadinessTest,LeadsWorkspace/LeadsIndexTest}.php` |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |

### Added — 2026-11-06 — Phase Q3: the scorer reads the buying signals we already hold

`DeterministicScorer` answered **Unknown** for every `buying_signal` ICP criterion, on the stated
grounds that funding, hiring and expansion "aren't in any column we hold". Signals has held them
since S10 — `signals.matched_lead_id` is _the lead an event is about_ — so a team could write
"recently raised funding" into their profile, watch the feed fill up with exactly that, and have the
rules decline to count it.

`Scoring\Contracts\LeadSignalSource` is how Scoring asks without depending on Signals, the pattern
Billing uses for `CreditSource`. **Its default is permissive** (`NoLeadSignals` → no labels →
`Unknown`), which is the behaviour it replaced and a true statement about a team with Signals off —
the opposite choice to `SignalOutreachDrafter`, whose default refuses, because that one answers
whether a prospect may be approached and this one answers whether we happen to know something.
`SignalsServiceProvider` binds the real `MatchedLeadSignals` over it; it registers after Scoring's,
which is what makes the override work.

`MatchedLeadSignals` follows three of the feed's own rules. **A dismissed signal says nothing**, so
an automation never overrules a member's judgement (ADR 0024 §4c, one level out). **One event, one
entry** — a clustered story is read through `signal_clusters.primary_signal_id`, or a round six
outlets carried would outweigh a quiet one for no reason but coverage. And **recent only**
(`signals.scoring.lead_signal_days`, a year): a criterion phrased "recently raised funding" means
recently, and a round from three years ago is history.

The buying-signal special case in the scorer was **deleted** rather than extended: the criterion now
goes through `haystackFor()` like every other one, so the matcher, the missing-data rule and the
evidence string are the existing ones, and the breakdown names the signal that matched for free. A
lead with no signals is still `Unknown`, not a miss — its weight leaves **both** sides of the ratio,
so nothing is marked down for what we do not know.

| Files | `app/Modules/Scoring/{Contracts/LeadSignalSource,Services/NoLeadSignals,Services/DeterministicScorer,Providers/ScoringServiceProvider}.php`, `app/Modules/Signals/{Services/MatchedLeadSignals,Providers/SignalsServiceProvider}.php`, `config/signals.php`, `.env.example`, `tests/Feature/Scoring/BuyingSignalScoringTest.php` |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |

### Fixed — 2026-11-06 — Phase Q1: enrichment keeps the facts it already extracts

The first phase of **`lead-quality-plan.md`**, a new plan whose subject is the **precision** of the
leads we already find (`lead-sources-plan.md` adds breadth; these two do not overlap).

`lead.enrich_extract` has returned a `company_name` and a `locations` list since Phase 8, and
`EnrichLeadAction::applyFacts()` filled four fields and **dropped both on the floor**. The cost of
that was not subtle: a `web_search` lead's `company_name` is whatever the result's **page title**
said ("Best Coffee Roasters in London | Reviews"), so the real name was extracted, validated, paid
for and discarded on every run — while de-duplication compared page titles and the scorer's
Geography criterion abstained on every web-sourced lead, because such a lead has no postal address
at all. Both are now kept.

**`company_name` is the one field enrichment may overwrite rather than fill**, and only for the
bottom tier of `LeadSource::rank()` — `web_search` and `signal`, the two sources whose "name" was
never a name but a page title or an article's subject. Companies House gives the registered name,
Places the business's own listing, and a member who typed one outranks everything; none of them is
touched, and nothing here can blank a name. The overwrite records its own `SourcedValue` provenance,
because an overwrite is not a fill and the lead has to say where its corrected name came from.

**The dedupe key moves with the name when the key was derived from it.** A `name:`-keyed lead whose
name changed would otherwise keep a key pointing at a name it no longer has and never de-duplicate
again; number- and domain-keyed leads, which is nearly all of them, are untouched. The key formula
moved to `LeadSourcing\Support\CompanyNameKey::dedupeKeyFor()`, beside `for()` and for the same
stated reason — two copies of a key formula is how one lead becomes two.

**The extracted locations are stored as themselves** in a new `leads.locations`, not squeezed into
`locality`/`region`/`country`. Those three describe one postal address a provider gave us; an
extracted location is free text a company wrote about itself, and parsing one into the other needs a
guess that would be worse than the honest null. `DeterministicScorer` now reads them alongside the
address for a Geography criterion (no locations at all is still **Unknown**, not a miss), and
`ScoringPayloadBuilder` states them to the AI half as "Operates in", kept apart from "Location" so
the explanation cannot merge two different claims. Exposed on `LeadResource` (additive) and in the
shared TS lead type; the specification was regenerated.

Also fixed in passing: `LeadWorkApiTest`'s "adds a task" case hard-coded a `due_at` of 2026-11-05
and asserted the task was not overdue, so it began failing the day that date passed. It is relative
to `now()` now.

| Files | `app/Modules/Enrichment/Actions/EnrichLeadAction.php`, `app/Modules/LeadSourcing/{Support/CompanyNameKey,Data/SourcedLead,Models/Lead,Http/Resources/LeadResource}.php`, `app/Modules/Scoring/Services/{DeterministicScorer,ScoringPayloadBuilder}.php`, `database/migrations/2026_11_11_100001_leads_keep_the_locations_enrichment_extracts.php`, `resources/js/shared/types/lead-sourcing.ts`, `docs/api/openapi.json`, `lead-quality-plan.md`, `tests/Feature/{Enrichment/EnrichLeadActionTest,Scoring/DeterministicScorerTest,LeadsWorkspace/LeadWorkApiTest}.php` |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |

### Added — 2026-11-06 — Phase L0: free lead sources, the licence and terms spike (ADR 0025)

The first phase of `lead-sources-plan.md`, and a docs-only one: seventeen candidate **free** data
sources — UK sector registers, HM Land Registry, the UAE free zones, OpenStreetMap, job boards,
public contracts, planning applications, The Gazette, Dubai Pulse — read for their terms, limits and
licences before a line of code was written. Live calls were made to every source that needs no key.
The findings, with the responses quoted, are in `docs/modules/lead-sourcing.md` → _Free sources —
spike findings (L0)_; the decisions are **ADR 0025** (the plan calls it "0018", which Signals v2 had
already taken).

**Five sources are dropped on their own terms, and two of them would have worked perfectly.**
Adzuna's terms permit personal job search, publishing its listings and research, then say any other
use "including commercial analysis, resale of data, and sales prospecting or lead generation"
requires a written agreement — which names this product. Reed publishes no API terms at all, and
silence is not permission to store a third party's content commercially. PlanIt's `robots.txt`
disallows the JSON API we would have called, to every agent. DMCC's member directory is a Salesforce
site whose `robots.txt` is `Disallow: /` for everyone but googlebot, and DIFC's public register is a
search under `Disallow: /search/`. ADGM publishes no machine-readable register at all. So plan phases
**L4 (UAE free zones), L8 (hiring signals) and L10 (planning applications) are dropped** — and the
"no free UAE registry" gap ADR 0007 recorded is still open, with Dubai Pulse's free-zone licensing
dataset the one lawful machine route found.

**A sixth phase went away for a happier reason.** L6 was to extract a `SignalSource` seam from
Signals with Serper news as its first implementation. S13a already built it (ADR 0023), complete with
the per-source circuit-breaker key, the three addressing shapes and the 4xx trichotomy the plan had
not anticipated. L6 is superseded. Fourteen phases become ten.

What the live calls turned up, beyond the terms: the **FSA** register answers with no key at all and
holds 141,464 restaurants and cafés in one business type; **Contracts Finder** and **Find a Tender**
publish open OCDS in which an award names its supplier by **Companies House number**
(`"id":"GB-COH-6835794"`), so a contract-award signal will match a lead by identifier rather than by
a name comparison — the first source in the product where ADR 0021's weak-match failure mode cannot
arise; and the public **Overpass** endpoint answered a fourth consecutive query with "This public
endpoint is intended for browser-based and human-operated tools", which is why `OSM_OVERPASS_URL` is
configurable from the first commit. Overpass also produced the finding that decides L5's design: the
same café query returns **79** results for `area["name"="London"]["admin_level"="6"]` and **4,717**
for `area["name"="Greater London"]`, and **0** for a Dubai area name that needs `name:en` — a wrong
area name returns an empty 200, indistinguishable from "nothing there". Areas get resolved to OSM
relation ids, not composed from name strings.

Three decisions worth reading in the ADR rather than here: a **platform reference dataset** has no
`team_id`, is read-only to tenants and is replaced by a version flip rather than aged (the second
documented exception to ADR 0006); a `LeadSourceProvider` stores **no named individual, ever**, which
the Charity Commission's terms and the OGL's exclusion of personal data turn from a preference into a
licence condition (`docs/compliance.md` §3.14); and there is **no plan gating** — every new source on
every plan, with quotas as the only limit, because each one is free to us and gating would make the
cheaper plans worse than they were the day before. Two new ⚖️ questions were added for counsel: the
Land Registry licence's commercial terms, and the FCA's silence on commercial reuse beside the
extracts it sells.

### Fixed — 2026-11-04 — Signals: an audit, and the things it found

A pass over the whole of Signals rather than over one change, looking for the places where
something true when it was written stopped being true as the module grew.

**The convert dialog was telling some people the wrong law.** It said "not cold email — UAE data law
needs their say-so first" on _every_ conversion. That was right when Signals only watched the UAE,
and wrong from the moment a watch could cover London: a UK prospect may be approached by email, and
a team was being told otherwise on the one screen where they decide how to make contact. The dialog
now shows the restriction that actually applies to that prospect, and nothing at all where there is
none — resolved by the same code the save uses, so the two cannot disagree.

**The watch screen now tells you when you are at your plan's limit**, before you fill in the form
rather than after. The signal-types, accounts and automations screens already did.

**Deleting a watch no longer leaves miscounted cards behind.** Where two watches covered the same
story, removing one left the card claiming more outlets than it could show, and sometimes split one
event into several. It is now put right as the watch goes, rather than overnight.

**A feed's web address can no longer reach our logs.** Some addresses carry a private token, and a
failed fetch was recording the whole address. It records what kind of failure it was and nothing
else.

**Two things that looked broken and were not.** Previewing a signal type at the same moment as a
colleague showed an error page; it now says "someone else on your team is previewing right now, try
again in a moment". And an automation that could never finish a batch now says so in the log instead
of stopping quietly.

**Faster housekeeping.** The nightly tidy-up and the monthly allowance figures had no database
indexes behind them, which costs nothing at small scale and a great deal at large. Added.

**Smaller things.** The "running" badge and some notice text failed contrast guidelines; three
dropdowns on the watch form were missing their focus outline and their disabled state; every card in
the feed offered a screen reader three buttons called "Dismiss", "Convert to lead" and "Bring it
back" with no indication of which story they belonged to, and now names the story. In Arabic, one
misspelled word, our own name spelled two different ways, and one phrase reworded to match how the
rest of the product already says it. Some Arabic wording still needs a native speaker's eye and is
listed in the module notes.

**For developers:** the test suite could not run in parallel at all — it exited having run zero
tests — for two separate reasons, both now fixed. It runs in 21 seconds instead of 122.

### Added — 2026-11-04 — Signals: an allowance that means something, and nothing kept longer than it should be

Signals has grown a lot in the last week, and every one of those additions was a new way to spend
money on your behalf: a signal type is multiplied by every watch and every place it covers, a
watchlist is a search for each company on it, a preview is a button that pays for searches, an
automation is evaluated against every signal, and a feed is a request we make on your behalf to an
address you chose. None of it was bounded by anything your plan could set. This is the release that
fixes that, and it is deliberately boring — the best outcome here is that nobody notices.

**Your plan now says what Signals may do, in eight numbers.** How many signal types and watches you
may keep; how many companies on one watchlist; how many searches and how many previews a month; how
much reading of full articles; and whether automations and the events API are included. The numbers
for each plan are on the pricing page and your own are on **Plan & billing**, beside companies found
and AI credits, as a bar with a figure on it.

**And when a limit bites, you are told.** A watch that stops because the month's searches are gone
says so, on the run, in a sentence you can act on — not an empty feed you have to work out for
yourself. Near the ceiling a run gets **smaller rather than refused**, and says its coverage was
trimmed: a team with twelve searches left gets a twelve-search run rather than nothing. Pressing
"Run now" when there is nothing left tells you immediately and does not cost you your next press.
Reading of full articles stops at its ceiling without stopping the signals, which keep arriving from
the headline and the summary. On a paid plan, work continues past your included allowance and the
extra is billed — up to twice the allowance, and no further, because an uncapped bill is not
something anybody agreed to.

**Nothing is kept longer than it needs to be.** Alima used to prune four of the tables behind
Signals and now prunes all ten. Records that are about a person get the shortest window the product
can work with: a dismissed signal after ninety days, an unactioned one after a hundred and eighty,
and — new in this release — the record of what an automation did about somebody's event after a
hundred and eighty, including for a signal that became a lead and is therefore never pruned itself.
Figures that are about **cost** rather than people are kept far longer, because "what did Signals
cost us last March" is a question asked in arrears. Everything else goes when the thing it was about
goes: a preview's accounting row after a month, the tally for a news outlet nobody has seen for six
months, and a watched company whose lead you deleted.

**A data-subject request now reaches everywhere Signals holds a name.** It used to search the feed.
It also searches the people on your watchlists — a name on a watchlist is a standing instruction to
look for them, and erasing the stories while leaving the instruction would just find them again
— and the body of any event one of your systems pushed to us, which we did not write and therefore
have to read. The three are reported as three different kinds of record, because what somebody needs
to be told is the difference between something we found, something we are looking for and something
somebody else told us about them. Erasing a story that was one of several covering the same event
now tidies that card up straight away, rather than at the following night's sweep.

**You are not charged for the searches that cost us nothing.** Three of the five places Signals
looks are free to us — Companies House, a feed you point us at, and events your own systems push —
and the allowance counts only the ones we are billed for. A watchlist watched through the company
register does not eat your month's searches.

**Four smaller fixes you might have hit.** The signal-types and watchlist screens were showing the
product's own maximum rather than your plan's, so a team allowed fifteen types was told
twenty-five and found out by being refused. The "allowance is running low" message could have
described your signal allowance as AI credit. The pricing line under your usage bars listed two
things when there are now three. And if your plan stopped including automations, the only way to
stop a rule being listed as live was to delete it — you can pause it now.

### Fixed — 2026-11-03 — Signals: the library and the round-up, hardened

Review of the three features below found several things worth knowing about, and all of them are
fixed.

**Two of them were about seeing somebody else's numbers, or your own in the wrong place.** On the
admin side, the figures shown beside a signal type a business had offered us were the totals for
every business running a type with that name, rather than that one business's — which is the only
thing that decision is supposed to be made on. And a signal type added to the shared library while a
business happened to be open in the same session was quietly saved as _their_ type instead of
Alima's: it worked, it looked right, and nobody could find it afterwards. Both are closed, with
tests that reproduce the conditions they needed.

**The cost figure was in the wrong currency.** Each signal type's "what it cost" was shown with a
pound sign over a dollar amount. The searches are billed in dollars and now say so.

**Withdrawing a type from the library used to break its name for good** — adding anything under the
same name afterwards failed outright, and withdrawn types stayed on the operator's screen. Both
fixed; naming a withdrawn type's name when accepting a new submission now brings it back with the
new content, which is what somebody typing it means.

**An offered type kept more of your work.** Accepting a submission into the library was carrying its
searches but not its include and exclude rules, its keywords or its suggested approach — which, for
most types, is the part that does the work. It carries all of it now.

**And if you deleted a type while your offer was waiting, you could not take the offer back.** You
can now.

Also: a signal check that failed and retried could end up billing twice for searches it had already
recorded once; a problem writing the cost figures can no longer fail a check that has already paid
for its searches.

On the screens: in the "the library version of this has changed" panel, each change is now a
properly labelled group, so a screen reader reads out what you are agreeing to rather than just the
name of the part; the round-up email no longer runs the headline and the event together, and its
"before you reach out" line now says so in words rather than relying on italics; buttons that repeat
down the page carry the type's name; a read-only member is no longer invited to press a button they
do not have; and the Arabic reads properly throughout — "pause" now says pause, "take it back" and
"withdrawn" use one verb between them, and the round-up's count is grammatical for every number.

### Added — 2026-11-03 — Signals: the round-up, the receipts, and a library that grows

Three things at once, and they all answer the same complaint: Signals asked you to come and look at
a screen, and never told you whether looking was worth it.

**Your round-up now names the news.** The daily or weekly digest used to say "11 new signals
waiting". It now names the ones worth your morning — the headline itself, the kind of event, how hot
it is, and **how you may approach that prospect**, which matters because you are reading this in your
inbox where nothing can warn you. One story is one line however many papers covered it, and it is
capped at three, because a round-up you skim is a round-up you stop opening. It can come as a phone
notification as well as an email; your existing round-up settings decide, and switching signals off
switches them off here too.

**Every signal type now shows what it found and what it cost.** On the signal types screen, each
type you run carries its own figures: how much of what it brought you you kept, how many became
leads, what it has cost, and what each lead cost. Where it has produced no leads, it says so in
words rather than showing a nought — a nought would read as "free", and the truth is the money
bought nothing. Types with too little history to judge stay quiet rather than showing you a panel of
zeros.

**And where a type is plainly not working, Alima says so.** A type that has found plenty, that you
have kept almost none of and that has produced no leads gets a "pause this one?" card with the
numbers behind it. As with every suggestion on that panel: it proposes, you decide, and nothing
changes until you say so.

**The library is now curated rather than shipped.** If you have built a signal type that works, you
can **offer it to Alima's library** for other businesses to start from — with a note, and you can
take the offer back. Nothing about your own type changes whatever we decide: it keeps its name, its
history and its schedule, and it keeps running. We answer in writing either way.

**And when the library improves something you took a copy of, you are asked rather than updated.**
Your copy keeps running exactly as you set it up. You see what changed, side by side with what you
have, and you take the parts you want — or keep yours, which is a real answer and stops us asking
about that version again.

Finally, if you are new, the library now leads with the pack written for **your** market rather than
the general shelf.

### Fixed — 2026-11-03 — Signals: rules, hardened

Review of the rules feature below found three things worth knowing about, and all of them are fixed.

**A rule could act on a signal you had already dismissed** — making the lead and writing a draft for
something you had said no to. It now leaves a dismissed signal completely alone. Your decision
stands.

**A busy morning quietly lost its tail.** There is a limit on how many leads one check may create
and how many drafts it may write, and signals past that limit were being refused permanently rather
than waiting their turn. The same went for a very large check: anything past its ceiling was dropped
and never handled. Both now come back on the next pass, so a long run finishes the job over a few
passes instead of doing part of it once.

**A problem with a rule could cost you a search.** If anything went wrong while queuing a rule, the
signal check that had just finished was marked failed — and retried, re-running the paid search it
had already done. A rule's problems are now its own.

Also: the activity log now says when a rule started on a signal and did not finish, rather than
showing it as having done nothing; a rule that is only rehearsing can no longer change what your
live rules do; a rule stops acting as somebody whose access has since been reduced; and the log no
longer reports a label as added when your lead was already at its limit.

On the screens: the "something needs fixing" links at the top of the rule builder now take you to
the field that is wrong, reordering a step keeps your place on the keyboard, a rehearsed step is no
longer shown with the same tick as one that really happened, and the Arabic reads properly in the
summary rows. You can now also narrow a rule to particular account lists, which was previously
possible only through the API.

### Added — 2026-11-03 — Signals: rules that handle the morning for you

A signal arriving can now become work Alima does for you. A **rule** says _when a signal looks like
this, do these things_ — make the lead, hand it to someone, move it to a stage, label it, put a task
on somebody's list, tell the right person, and write an approach for you to read.

**It never sends anything.** A rule can write a message and keep it as a draft for a person to read,
and it will refuse even that where the prospect may not be approached — a UAE prospect who has not
given consent, or anybody on your do-not-contact list. When it refuses, the log says so, says which
rule said no, and says what to do instead. That is a decision about how the product works rather
than a limitation we will lift later.

**A new rule rehearses.** It watches for a week, records what it _would_ have done, and changes
nothing. Reading that log is how you decide whether to switch it on, and switching it on is one
button. Before you even save it, "check the last few days" tells you how many of the signals you
already have it would have picked — "4 of 120" is a rule you can switch on; "118 of 120" is one to
narrow first.

**Everything a rule does is written down**, including the things it declined to do and the ones that
did not apply. Removing a rule keeps its history: the explanation for why a lead is where it is does
not disappear with the rule that put it there.

Four limits keep it safe without anybody configuring them: a rule handles any one signal once and
only once; there is a ceiling on how many leads one check may create and how many drafts it may
write; and a rehearsal spends nothing.

On the phone you get the half that matters in the field: **what fired, and what needs you** — the
refusals first. Rules are built on the web.

### Fixed — 2026-11-03 — Signals: the three new sources, hardened

Review found a set of problems in the three sources added above, and all of them are fixed. The ones
worth knowing about:

**Your own events would not have worked at all.** Pushed events were accepted and stored correctly
and then failed to be read back, every time, on a type mismatch no test covered. Found by writing
the missing test. Nothing had been lost — the events were on disk — but nothing had been classified
either.

**One dead address could stop a whole watch.** A company that had been struck off the register, or a
feed whose publisher had moved it, made the entire run fail — taking the news results on the same
watch with it, and doing it again on every run. A missing address is now a skipped query: the rest of
the run proceeds and the skip is counted where support can see it.

**Feeds are no longer shared between customers.** Two companies watching the same public newsletter
were, in effect, taking turns: the first to poll it got the articles and the second got nothing.
Each team now has its own reading of every feed. Previewing a feed type no longer leaves its first
real run empty, either.

**A burst of pushed events is no longer truncated.** If your system sent two hundred events at once
— a backfill, or a retry loop — only the newest fifty were ever looked at, because the fifty Alima
reads per run were spent on events it had already dealt with. Each run now reads fifty it has not
seen, so a burst is worked through over the following runs instead of being lost.

**The subject you send is now used.** `company_name` and `person_name` were accepted and ignored.
Alima now reads them when deciding what an event is about and which of your leads it concerns.

**A signal type that names a feed now requires its address**, instead of saving quietly and finding
nothing.

Security hardening, for the technically minded: requests to `POST /api/v1/signal-events` must put
every field in the signed body — anything in the URL is refused, because it is not covered by the
signature. Feed fetches pin the address they checked, so a DNS record cannot change between the
check and the request, and a feed address that does not resolve is refused rather than attempted.
The read limit on a feed is now enforced as it was always documented to be.

### Added — 2026-11-03 — Signals: the registry, a feed, and your own events

Three more places a signal type can watch, and each one answers a different complaint.

**Companies House.** For the companies on a watchlist, Alima now reads what they file: a director
appointed or resigned, a change of ownership, a charge registered, an insolvency notice. This is the
cheapest and most certain thing in the product — the registry has already parsed the form, so there
is nothing to guess and no AI to pay for, and the event arrives with the officer's name and the date
attached. It is free.

What it does **not** do is watch a whole sector: filings can only be read one company at a time, so
this follows the accounts you have told us you care about. We looked at the alternative — every new
company registered in a sector and a city — and it came to 2,422 in a month. That is a list, not a
reason to pick up the phone.

**A feed you choose.** Paste the address of an industry newsletter, a regulator's notices or a trade
portal's press releases, and everything it publishes is read. For a niche sector that often beats
any search, because the publication nobody else reads is the one your competitors are not watching.

**Your own events.** `POST /api/v1/signal-events` lets your systems push events to Alima — a renewal
closed, a usage threshold crossed, whatever your business treats as a moment worth acting on. Each
signal type gets its own signing secret, shown once, and every request is signed, time-limited and
safe to retry. Pushed events are read and judged exactly like news: you tell us what happened, and
Alima still decides whether it is worth your morning. That is deliberate — an event that could set
its own score could put itself at the top of somebody's day.

Together these finish what a signal type can be: the press, the wider web, the public record, a
publication you trust, and your own data.

### Added — 2026-11-03 — Signals: a signal type can watch more than the news

A signal type meant one thing — a news search — and three of the events teams most want to hear
about never reach a news desk. A planning decision appears in a council's register, an award in the
organiser's own press release, a new office on the company's blog. So **where to look** is now part
of a signal type, and the type form offers it with each source's trade-off written down rather than
sold: web search finds events the press missed, and finds more noise with them.

Behind it, a source is a registry entry rather than a hard-wired client, mirroring how lead
providers already work. Each source gets its own circuit breaker, so a team running both does not
lose their news when web search is unwell; each is priced separately, and the cost line on the type
form moves as a source is ticked, because one template on two sources is two paid searches on every
run. A card names its source when that is not the news — most cards are news, and badging all of
them would distinguish nothing — and the "why you're seeing this" panel now also names **the query
that found it**, which it could not do before because no row stored it.

Operations gained the matching half: `/admin/signal-runs` shows what each source cost, found and had
refused, with each source's breaker beside it.

**Two things the live spike changed.** Serper refuses `site:` and quoted-phrase queries outright on
this account tier, and the old client treated that as the provider being unreachable — and
_retryable_. So one template a member had typed with a quotation mark in it failed their whole
watch, was retried three times (re-paying for every other search each time), and told them the news
service was down. A refused query is now skipped and recorded, the run finishes, and the breaker
stays shut: a provider's health and a query's validity are different questions.

And the planned job-postings source was **dropped on the evidence**, not built. A job advert is not a
trigger event — the events it was meant to find (a hiring surge, a new office, a new function) are
patterns _over_ postings, which need counting per company over time, and the hiring-surge signal has
been shipping on news since the vertical packs landed. The spike table and the reasoning are in the
module doc so nobody pays to learn it twice.

Companies House filings, RSS feeds and an inbound events API are the next step; the decision record
covers all three, including why a pushed event is never trusted to set its own score or its own
jurisdiction.

### Added — 2026-11-03 — Signals: a ranked feed that can explain itself

The feed was in date order, which answered "what is newest" and never "what is worth my morning".
Every signal now carries a **heat** out of 100, the feed is sorted by it, and **every card can show
its working** — eight components, what each one was worth, and the evidence behind it. A ranking
nobody can interrogate is worse than date order, so the breakdown is stored with the signal and the
panel reads it back: it explains the score the feed was actually ordered by, not what today's
weights would say about yesterday's order.

The eight are the AI's relevance and confidence, how recent the event is, the amount **banded
against the team's own history** (a £2m exit matters to one team and not another), how much Alima has
learned to trust the outlet, the subject's seniority, how many outlets carried the story, and fit
against the active ICP — reusing Scoring's criteria rather than inventing a second fit score. The
scorer is deterministic and has no AI in it. **A component with nothing to judge is left out of the
average rather than scored zero**, because otherwise the number would measure how much we know about
an event instead of how much the event is worth; a signal nothing could be judged about says so
rather than showing a cold score.

**The product now learns from dismissals, and proposes rather than acts.** `signal_source_stats`
records what each team does with each outlet's stories, per signal type. When the evidence is
one-sided a weekly job raises a suggestion — silence this outlet, raise this type's confidence floor,
retire this query — each with its numbers, each accepted or declined on the watch settings screen.
Nothing auto-applies: a feature that silently retunes itself is a feature nobody can debug.
Accepting one is an ordinary audited edit to the columns a member could have typed themselves, and a
declined suggestion is kept so it is never raised again.

**Behind a flag that is off**, Alima can read the whole article for the few signals at the top of a
feed and correct the amount, the role and the company name a search snippet could only guess at —
the oldest limitation in the module. Five things bound it: the flag, a heat floor, five pages a run,
a per-team monthly ceiling, and `robots.txt`. The article is read, used, and **dropped**: nothing of
the body is stored, because retention and subject-access requests both get harder the moment a
product keeps prose.

**And a report that says which trigger events are worth paying for** — signals, kept, became leads,
picked up and worked, per type and per outlet, over the window, exportable like every other table.

Operators: `signals:rescore` applies a retune to history, `signals:advise` is the weekly suggestion
job, and `SIGNALS_SCORING_*`, `SIGNALS_TUNING_*` and `SIGNALS_ARTICLE_FETCH_*` are in
`.env.example`. The full-article fetch is off by default and has a compliance note in
`docs/compliance.md` §5a worth reading before it is turned on for a customer.

### Added — 2026-11-03 — Signals: one event, one card

A Dubai acquisition reported by Gulf News, Khaleej Times, Zawya, Arabian Business and three
aggregators was five to eight cards in the feed, each one costing an AI classification and each one
needing its own dismiss. The feed's perceived quality was dominated by it.

**It is now one card that says "Reported by 8 sources"**, opening to the list. Every article is
still a real article with a real link worth reading; what changed is that the feed lists _events_
and the tab counts count them the same way. Dismissing clears the event rather than one of its
seven duplicates.

**The run also stopped paying per article.** It groups the coverage of a story before it asks the
model anything, classifies one representative, and applies the verdict to the rest — so a story that
travels well costs one classification instead of eight. `SIGNALS_CLUSTER_BEFORE_AI=false` restores
the old behaviour exactly, and the cards stay consolidated either way, because that half is done
after classification by the event's own subject rather than by comparing headlines.

**The counts agree now, everywhere.** The daily digest, the dashboard's "signals waiting" figure
and the per-type breakdown all count events rather than articles, as the feed does — a dashboard
saying eight over a screen showing one was the thing the clustering was supposed to fix, and
teaching only the feed about it would have moved the problem rather than removed it. The watch
card's "signals created" counts events too; the number of articles is still beside it. A lead the
event is about gets one timeline entry, not one per outlet.

**And it remembers being told no.** A "ten biggest deals of the year" round-up sitting inside the
week-long recency window used to cost an AI batch every single day it stayed there. A rejection is
now remembered for 30 days, keyed by the article _and_ the set of signal types that were offered —
so adding a signal type asks about every article again, and changing nothing costs nothing twice. It
stores hashes, a verdict and a count: no headline, no URL, no name.

### Fixed — 2026-11-03 — Signals: the same article stopped arriving as a new one every day

Our news provider sometimes hands back a Google redirect instead of the publisher's link, with the
publisher's name alongside it. The redirect carries an opaque token that cannot be unwrapped, and
when it changed between requests the article's identity changed with it — so the same story
re-entered the feed on every run, at the cost of a fresh search and a fresh AI classification each
time.

A redirected article is now identified by its outlet and its headline rather than by the token, and
that identity is what the database enforces. The team's own "never show me this source" list learned
to match the **outlet's name** as well as the domain for the same reason: behind a redirect every
item's domain is `google.com`, so a domain-only list could not express "not Khaleej Times" about
precisely the items that needed it.

`signals:cluster-backfill` groups the last 90 days of an existing feed so it reads the same on the
day this ships as it will the day after. It leaves a converted signal alone: that row is the record
of where a real lead came from.

### Added — 2026-11-02 — two switches that turn billing off

"Not charging anybody yet" is a state the product should support, so it is two lines of
configuration rather than code to remove and put back later.

**`BILLING_STRIPE_ENABLED=false`** stops every Stripe call: checkout, the customer portal, plan
swaps, cancellation, resumption and metered overage all refuse, and the webhook is rejected. It
deliberately changes nobody's entitlement — a team on Scale stays on Scale — because "we will not
take a card today" and "you may not use this feature" are different statements.

**`BILLING_ENFORCE_PLANS=false`** is the second one: nothing is gated on a plan any more. Every
feature is available to every team, lead discovery is unquotaed and seats are unlimited. It is not
"everybody is on Free"; it is "a plan decides nothing". Suspension still works, an operator can
still turn a feature off for one tenant or for everybody, and the AI credit allowance still applies
— that one is a cost guard every tenant has had since the AI platform shipped, not a plan feature.

Both default to on, so a deployment that does not ask for them sees no change.

### Fixed — 2026-11-03 — the test suite stopped depending on your `.env`

Turning Stripe or plan enforcement off in your own `.env` — which is what those switches are for —
used to make about forty tests fail, in Billing, Admin, AI Platform, Notifications and Signals. The
switches are deployment settings read from the environment, and the suite was quietly inheriting
them, so it stopped asserting the behaviour it exists to assert and blamed whatever you were working
on. The test environment pins them on now, and the one file that deliberately turns them off puts
them back properly afterwards.

Two more of the same shape, found while fixing it. A running `npm run dev` suppresses the content
security policy on purpose, which made two tests _about_ the policy fail on any machine with a dev
server up; they now describe the built environment they are about, and a new test proves the dev
server skip still happens. And the AI response cache made a couple of new Signals tests pass for
the wrong reason — a second run over the same article was a cache hit, which looks exactly like the
de-duplication having worked — so the tests that count AI calls now turn it off.

### Changed — 2026-11-02 — the operations panel got a front page, a way to navigate it, and the brand

The verdict was "every page is boring, navigation is terrible, there are no graphs, there's no
branding", and it was fair on all four counts.

**Navigation.** The rail was fifty-five links in one flat column, so finding a screen was a scan
rather than a search. It now opens with a filter box, puts the eight screens an operator actually
uses daily at the top in two short groups, and files the forty-four record browsers in collapsible
groups that remember what you left open. **⌘K** searches every destination by name, by group and by
the words of the problem — "dsar" finds Compliance, "kill switch" finds Platform settings, "tenant"
finds Teams.

**A front page worth opening.** It was eight live counts, which tell you the size of a thing and
nothing about its direction. It now says first whether anything is on fire — failed jobs, providers
we have stopped calling, and whether last night's figures were actually computed — then shows five
headline numbers _with their trend_, and a chart of the last thirty days you can flick between
series on without the page reloading. The live counts are still there, demoted to a strip where
facts without a direction belong.

**The same page, eighteen times.** Every screen had grown its own header, and they had drifted into
eighteen type scales and spacings. They share one now, with the product's own editorial device: a
mono eyebrow, a statement whose last phrase takes the accent, and one "all times UTC" line per page
instead of a label on every cell.

**It looks like Alima.** Deep Teal is the panel's single accent throughout — never the app's lime,
because an operator must never mistake the panel for a customer's screen. Two stronger brand moves,
a panda peek on the header and the spectrum gradient as a chrome band, are written up for the brand
owner to approve rather than taken quietly.

### Fixed — 2026-11-02 — the admin panel was unusable on a fresh operator account

Reported as "nothing works, every link in the sidebar 404s, and it sends me to a password screen".
None of it was the panel: with a second factor set up, every screen in it — including all 44 record
browsers — already answered correctly. The way **in** was broken, in three compounding ways.

Signing in went to the product's dashboard, which an account with no team cannot render. The panel's
two-factor requirement then sent the operator to the _product's_ security settings — so they were
standing in the customer shell, whose navigation is entirely team-scoped: every link 404ed, and the
password confirmation Laravel asks for before arming a second factor read as a password reset.

The panel owns enrolment now, on its own screen, in its own shell, where every link works: scan,
confirm, and the panel opens. The product's navigation offers an account with no team only the
things that exist for it. And the panel's own links are built from the path it is actually served
on, so moving it with `ADMIN_PATH` no longer breaks every link inside it.

### Fixed — 2026-11-02 — signing in as the platform operator landed on a 404

The operator account belongs to no team, on purpose — that is what makes it an operator rather than
a customer. But a login goes to one configured page for everybody, and that page is the product's
dashboard, which needs a team. So signing in as `super@admin.com` showed a 404, as did opening the
sign-in page again afterwards, as did the panel's own **Leave the panel** button.

All three now ask the same narrow question — no team, but a platform role — and open the panel
instead; the panel's header offers **Sign out**, because for an account with no team there is
nowhere to leave to. Everybody who has a team is unaffected, including an operator who also belongs
to one.

### Added — 2026-11-02 — Signals S10: account monitoring

**Signals can now watch the accounts you already have, not only the strangers who just came into
money.**

A watch used to ask one question: _what happened in Dubai?_ It can now ask the warm version —
_what happened to the forty companies I am working?_ The obvious list is the team's own pipeline, so
Alima mirrors it every night and nobody types anything: point a list at a stage or two, and an
acquisition of a company somebody is mid-deal with lands on that lead's page the morning it is
published, in that person's notifications.

**The event goes on the lead, not beside it.** Every signal any watch finds is matched against the
team's own leads. Where it matches, the card stops offering _Convert to lead_ — which would make a
second copy of a company somebody is already working — and offers **Add to Acme Ltd**, which creates
nothing, fills only the gaps in the record and writes the event onto the lead's history. The lead
page's "The event" panel became a **list** of events in date order, which is a small change with a
large effect on whether the page is worth opening in the morning.

**It errs toward saying nothing.** The way account monitoring fails is an event pinned to the wrong
company, so there are exactly two confidences and no third: the team's own statement or an identical
name, and a name that agrees once the legal form is set aside. Anything weaker is **not a match** and
the signal behaves exactly as it did before. The confidence is named and explained in words on the
card, and **Convert anyway** is a real button beside the primary one — the member is the one who
knows whether two companies with the same name are the same company.

**The spike contradicted the plan, and the plan asked it to.** S10 was written expecting a company
name to be the one case where exact-phrase quoting is right. Twenty-four live calls later: **every
quoted query returned zero, twelve of twelve**, and adding a keyword to a bare name _lost_ genuine
results rather than sharpening them — one subject went from nine results to none. So an entity query
is a bare name and the classifier decides what it found. That changed the cost shape too: one
account is one search per region, so a 250-account list cannot be covered in one run. It is searched
as a **ring** — as many as one check can afford, continuing where the last stopped, round again about every six days — and the
screen says that number out loud, because a long list that seems quiet otherwise reads as a broken
feature.

A watch is pointed at a list from the watch editor itself, and the two reviews this phase went through are worth a line of their own: between them they found that **nothing outside the tests could point a watch at a list at all** — a green suite sitting on top of an unreachable feature, because every test reached the state through a factory. What was decided was right; what was missing was the wiring. Both are now tested from the form in.

Also in this phase: the one company-name rule was extracted so the deduplicator and the matcher read
the same normalisation (three normalisers is how two leads become three); erasure reaches the
watchlists, in Compliance's own transaction; and nine web and nine API routes, with the counts of a
bulk add travelling beside the list rather than inside it. ADR 0021 records the decisions.

### Added — 2026-11-02 — Signals S9: the builder

**You can describe a signal in a sentence, and see what it finds before you keep it.**

S7 made a signal type a row a team owns. That was the right change, and it left the team holding a
blank page — because "a good template" is not a matter of taste. The S0 spike exists for one
reason: **nobody can tell whether a query template works by reading it.** Seven of eight
hand-written ones returned zero results. That was a developer losing an afternoon before shipping.
Handing the same problem to customers without the afternoon means a team writes three plausible
templates, saves them, and finds out in a week — except what they see is an empty feed, which reads
as _Signals does not work_ rather than _that template is too specific_, and the templates have been
running daily in the meantime.

So this phase is not a nicer form. It is the two things that make a type written by a customer as
good as one written by us.

**A draft they did not have to invent.** The composer takes a sentence — "tell me when a UK
manufacturer changes hands or the owner sells a controlling stake" — and returns a complete draft:
the searches, the rules that tell a real event from the round-up that reads like one, the thresholds,
the approach hint. It runs on the **reasoning** tier, because the cheap tier writes templates that
look right and find nothing. And it **never saves**: the member reads the draft, changes what they
like, and commits. An AI that wrote directly to the library would be a prompt with write access to a
standing instruction to spend money every day. "Find me rich people in Dubai" gets a **refusal with
a reason**, which is a better answer than a plausible search that cannot work.

**An answer before they commit.** Press "show me what this finds" and the draft runs against live
news — classified by the _real_ extraction prompt, not a stand-in — and every article comes back
with one of six verdicts: kept, not an event, wrong place, not sure enough, not relevant enough, or
a skipped source. Six rather than a boolean, because each one is a **different thing to change**, and
an empty list tells somebody that something is wrong without telling them which thing. A run drops a
skip-listed source silently; the preview shows it labelled, because "your templates work and every
result is on your skip list" is a diagnosis nobody reaches from an empty list.

It persists **no signals** — a definition nobody has saved has no business putting rows in a feed —
and exactly one ledger row, which is the new `signal_previews` table. That table is why the per-team
daily preview budget can be enforced at all: **a cost with no ledger row is a cost nobody can cap.**
Three independent caps bound the spend, because each fails differently — four queries per press, ten
presses per member per hour, and a team-wide daily budget that a rate limiter could never express.

**The library grew into something that reads as a product**: 30 presets in seven groupings —
general, luxury real estate, recruitment, B2B software, wealth management, construction, M&A — with
the audience line for each, so the chip says who it is for rather than what it contains. Every one of
the 66 candidate templates was run against live news first, for about eight cents. Eight returned
**zero** and were replaced; one preset the plan asked for was **dropped**, because the news for a
sports contract is transfer gossip rather than signed deals; three ship marked **Rare**, because the
event is real and the news for it is sparse, and shipping those silently beside the strong ones would
teach a team the feature is unreliable using our own content. The table is in
`docs/modules/signals.md`, and the lesson it repeats is the shape of the failures rather than their
topic: every zero was a four-word noun phrase a press officer would write and a journalist would not.
That finding is now in the composer's system prompt, with its reason.

The AI shortcut is the one part behind a plan (`Capability::SignalComposer`). The library, the
hand-written form and the preview stay on every plan that has Signals — gating the builder would
have made the cheaper plans worse than they were before it existed, and gating the preview would have
sold them the ability to write templates without the ability to find out whether they work.

**The review pass is worth a paragraph of its own, because of what it says about the shape of the
work.** Three agents read the finished phase, and every serious finding was in the **accounting**
rather than in the feature: the ledger row was written at the end, so a provider failure left
searches paid for and nothing recorded; the budget check was a read-then-write, so everyone pressing
at once read the same "remaining"; and the AI's failure branches booked a cost of zero while two
attempts had been billed — which a member could trigger on purpose, because their own exclusion
rules go into that prompt. The row is reserved before the spend and settled after it now. A spend
loop is easy to design and easy to under-account for, and under-accounting is invisible to every
test that only checks the happy path.

Two behavioural defects were caught before anybody met them. Previewing a **saved** signal type
turned it into a new one — the preview sent the submitted draft back so the form would survive the
redirect, and the screen could not tell that echo from a composer draft, so Save created a duplicate
and the unsaved-changes guard had quietly stopped firing. And the preview reported _our_ AI outage as
"not an event", which tells somebody to rewrite templates that are working; that has its own verdict
now. The preview panel was also regrouped by verdict, because one card per article carrying its own
copy of the same advice is wallpaper rather than guidance.

Three smaller things worth knowing. **`SignalQuerySpec`** was extracted so building queries no longer
needs a saved watch, which is what made the preview possible at all — a builder that needs a
persisted row is the reason previews are hard. **`IgnoredDomains`** was extracted so the run and the
preview cannot disagree by a hair of host normalisation about what "skipped" means. And the four list
boxes in the type editor **could not be typed into**: a two-way computed that filtered blank lines
and wrote the result back ate the Enter key, so the only way to add a second template was to split a
line or paste one in. That is the same WCAG 2.1.1 defect S8's review found in the watch form, and the
note to check wherever else the pattern appeared is what found it here.

### Added — 2026-11-02 — Signals S8: a watch covers places, plural — and a restriction follows the person

**One watch can now cover Dubai and London.** v1 gave a watch a single `location` and a `market`,
and the market picked both the country Serper searched and the compliance rule stamped on every lead
the watch produced. A watch now carries a list of **places** (up to ten) and a list of **regions**
(up to five), the UK is a first-class market with its own six-type preset pack, and `market` is
demoted to what it should always have been: the team's declared regime for that watch, a default and
a backstop.

**The reason those two jobs had to be separated is a compliance defect, and it is the important part
of this phase.** While every watch was UAE-only, "where we looked" and "whose law applies" had the
same answer, so one column could serve both and nobody had to notice. The moment a watch spans
places the conflation is wrong in both directions — and the serious direction is the permissive
one: a UK-market watch catching a Dubai relocation would have produced a lead with **no restriction
at all**, and Outreach would have seen no reason to refuse a cold email to somebody whose law
requires their prior consent. The restriction now comes from the signal's **own** jurisdiction,
resolved from the article's place at extraction, with the watch's market as a backstop and its
regions able only to add. Unknown fails _toward_ the restriction, never away from it. The fix
shipped in the same phase that made the defect reachable rather than in a later one, which is the
whole point of doing it this way round. ADR 0019 records the reasoning; `docs/compliance.md` §3.11
states it as a rule every future source is held to.

Five of the seven regions the code knows about are **switched off**, and that is deliberate: what
they lack is a compliance position, not code. `Core\Enums\Market` was not grown to cover them,
because every case on it asserts a position the business has actually taken, and a `Market` case
with nothing behind it is a legal claim made by a programmer. Turning one on is a config line _and_
a question for counsel (⚖️ `docs/compliance.md` §5).

Two smaller things worth knowing. The query count is now
`regions × places × types × templates`, which is twelve hundred paid searches for one run of one
watch at the limits where v1 could not exceed eight — so the ceiling added in S7's review now bites
in a second dimension, the trim drops **whole places** rather than a slice of everything (a gap a
member can see), and the run records what it _wanted_ beside what it ran so the card can say
coverage was reduced. A silent trim is a lie about what was searched. And `hl` became a property of
the region rather than one setting for the whole product, with definitions able to carry translated
templates — the plumbing for Arabic search, shipped without the content, because a
machine-translated search query finds nothing while looking like it works.

**The review pass found five backend defects and four frontend ones, and they are worth naming**
because they share a shape: every backend one was a place where a second reading of an existing
concept had been adopted without anybody deciding to. The switch that says a region may no longer
be searched was also deciding whether leads it had already produced were restricted. The order a
member ticked region boxes in was deciding an ambiguous jurisdiction, so "UK founder relocates to
Dubai" could resolve to the UK and come out unrestricted. A watch's compliance regime was
re-applied from the team row on every save, so renaming a watch moved it — and `teams.market`
defaults to `uk`. A rollback deleting "its own" rows read that as including a team's tuned clone.
Three of the five failed permissively, and none would have failed a test that existed. The fifth was
arithmetic: the query plan was nested region-major, so a tight ceiling spent the whole budget on the
first region and gave the second nothing — asking for two jurisdictions and silently getting one.

On the frontend the sharpest one was simpler: the places textarea could not be typed into. A
two-way computed filtered blank lines and wrote the result straight back, so pressing Enter at the
end of the text removed the line break it had just inserted, and the only way to add a second place
was to split an existing line or paste one in. The skip list had had the same defect since S6. The
Arabic for the new copy also needed rewriting rather than correcting: it said "sea" where it meant
"search", softened "never" to "and not", and called prospects customers on a line about what the
law permits.

Every UK template was spiked against live Serper before shipping, the same way S0 spiked the UAE
ones, and the same lesson held: two of fourteen returned **zero** results and no amount of reading
them would have told us which. `uk_psc_change` ships in the library but **not** as a starter,
because a change in people with significant control is a registry filing rather than a story a
journalist files — S13's Companies House source is what will make it work. The table is in
`docs/modules/signals.md`.

### Added — 2026-11-02 — Signals S7: signal types became data

**You can write your own signal types.** v1 shipped three — acquisition, funding round, relocation —
in a PHP enum, chosen for one customer. Adding a fourth meant a code change in nine files, a new
case in an AI schema's `enum`, a release and a deploy, which made "the feature is generic" a claim
rather than a fact. It is now a fact: a signal type is a `SignalDefinition` row, created and tuned
on **`/signals/types`** — its name, the plain-keyword searches that find it, the rules that tell it
from the round-ups and trackers that read just like it, and its own confidence and relevance floors.

v1's three types are the first three entries in a **platform preset library** teams clone from.
They clone rather than reference, and that is the decision rather than an implementation detail: a
definition is a standing instruction to spend money on searches, so a change we make must not
silently alter what a team is paying to run, and a team's own tuning must not leak back upward.
ADR 0018 records all of it, including the test for when a "type" should be an enum and when it
should be a row — **does the application branch on the value?** — which is the only reason
`CLAUDE.md` §2 is set aside here.

The one real safety change is worth stating plainly. v1 constrained the AI's `event_type` with a
JSON-schema `enum`, so a hijacked or hallucinated response naming an unknown type was refused before
our code saw it: the schema was the guard. Per-team keys make that enum impossible, so
`signals.extract_event` **v2** injects the watch's own keys and rules into the prompt body and
`RunSignalWatchAction::resolveDefinition()` rejects any key the run did not offer. A prompt
describes a constraint; it does not enforce one. There is a test that feeds a hostile key back.

Three bugs found while building it, each worth remembering:

- **A stored generated column refuses a cascading foreign key on its base column in MySQL.**
  `scope_id` (which makes `unique(scope_id, key)` mean "one key per scope", something
  `unique(team_id, key)` cannot because MySQL treats NULLs as distinct) was `STORED`. SQLite
  accepted it and the whole suite passed; `php artisan migrate` against MySQL failed on the first
  statement with error 1215. It is `VIRTUAL` now.
- **Laravel's generated index name for the new pivot is 67 characters** against MySQL's 64-character
  identifier limit. Both pivot indexes are named explicitly.
- **`Event::fake()` suppresses Eloquent model events.** `scope_id` had been maintained by a model
  `creating` hook, so every row written by a test that fakes events landed in the platform scope and
  collided. A column carrying a uniqueness invariant cannot depend on anything a caller might skip —
  bulk `insert()` fires no model events either — so the database computes it.

The migrations were run round-trip against the local MySQL (apply, verify, roll back, verify,
re-apply) because the data migration touches every existing team's live watches. Nine watches and
eight signals repointed with no cross-tenant rows and no losses either way.

**The review round found three more**, all of them in this phase's own new code:

- **Deleting a signal type and re-adding it from the library was a 500.** `unique(scope_id, key)`
  knows nothing about `deleted_at`, so the soft-deleted row still held its key while the clone path
  looked for an existing one with the default scope and could not see what it was about to collide
  with. The same collision arrived from a team writing their own type called "Acquisition". Both go
  through one place now, which restores a deleted clone rather than re-creating it and gives a clone
  a suffixed key when somebody else took its own.
- **A deleted type stopped naming the signals it had found.** `Signal::definition()` was a plain
  `belongsTo`, and the soft-delete scope applies to a relation read — so every card, report row and
  lead-origin panel for those signals quietly degraded from the team's own words to a humanised
  slug, which is the opposite of what ADR 0018 §4 promises.
- **Filtering the feed by a deleted type's key returned everything.** An unknown key was _dropped_,
  which was right when a key could only be a removed enum case and meaningless — and wrong now that
  the signals of a deleted type are still in the feed carrying that key. A filtered request was
  being answered with the unfiltered page, and those signals could not be isolated at all. Keys are
  honoured as given; a key matching nothing gives an empty page, which the screen already explains.

Plus a cost ceiling this phase needed and did not have. v1 could not exceed eight paid searches per
run, because the three event types had three, three and two templates between them. A team can now
hold 25 types of up to ten templates, so the same arithmetic reaches ~250 — and neither the
per-watch 15-minute throttle nor the per-member rate limit helps, because both bound how _often_ a
run happens rather than how big one is. `signals.max_queries_per_run` (default 40) is that bound,
and a run that trims says so rather than quietly covering less.

On the frontend the editor could not scroll — `DialogContent` is a fixed, centred panel with no
overflow, and a ten-field form puts Save out of reach on a laptop and at 200% zoom (WCAG 1.4.10) —
and closing it discarded a long hand-written form with no prompt. It is now the scrolling variant,
guarded by the same unsaved-changes dialog as the product's other long forms, and rebuilt on the
shared `FormField` set so it has `aria-invalid`, an error summary and required-or-optional in words
rather than an asterisk.

### Fixed — 2026-11-06 — Team switching had no UI

The `teams.switch` route, `SwitchTeamAction`, the audit entry and the `auth.teams` shared prop have
all existed since Phase 1, but the Phase 2 rebuild of the sidebar dropped the component that called
them — so a user who belonged to two teams could not change which one the portal showed. Switching
is now an entry in the user menu, beside Language, listed only when there is more than one team; the
menu is shared by the desktop rail, the mobile drawer and the header layout, so one mount covers
every shell. `TeamSwitchController` now redirects to the dashboard instead of `back()`, because the
screen somebody switched from may be a record belonging to the team they just left.

### Fixed — 2026-11-01 — Phase 20, the review round

`security-review`, `laravel-module-reviewer` and `brand-reviewer` on the finished phase. Three real
holes, all of them in this phase's own new code, plus the corrections. ADR 0017 has the full list;
these are the three worth reading.

**Sentry was scrubbing the request bag by key only**, and `url`, `query_string`, `cookies` and
`data` are four fields whose _keys_ do not look sensitive while three of the values are. So a 500 on
a signed invitation link would have shipped `GET /invitations/<secret-token>?expires=…&signature=…`
— a replayable credential — to a third-party error tracker readable by more people than the admin
panel is, and a failed save would have shipped the prospect record that was being saved, because
`name`, `company_name` and `notes` are not credential-shaped words and were never going to be on a
deny-list. Those four are now handled **by name** in `SentryOptions::scrubRequest()`.

The premise behind the omission was also simply wrong, and it was written into three comments and
the ADR: `send_default_pii => false` gates the cookies, the request headers and the IP address, and
**not** the URL, the query string, or the body. The body is governed by `max_request_body_size`,
whose SDK default is `medium` — ten kilobytes attached to every error event. It is now `none`, with
its own `security:posture` row, and the claim in the docs now matches what the code does. The
general lesson is kept in the ADR: a deny-list works where the key names the value's sensitivity and
fails where it does not. `password` says what it holds; `data` does not.

**`/horizon` had the gate but not the door.** `config/horizon.php` was `['web']` alone, which was
safe only because the published `viewHorizon` gate read `in_array($user->email, [])` and therefore
denied everybody — and this phase made that gate _pass_. For that window, `/horizon` and every
`/horizon/api/*` route (each tenant's serialised job payloads, plus retrying and deleting queued
work) was reachable with **a password alone**, from any network, by an operator who had not finished
two-factor enrolment — while `/admin` and `/pulse` refused the same session. It now carries the same
three admin middlewares as Pulse, asserted over Horizon's **whole route table**. That last detail is
its own small lesson: Horizon attaches its stack as a middleware _group_, so a test reading the
route's unexpanded middleware list would have passed before the fix and after it.

**And the corrections.** The CSP's `connect-src` derived the realtime origin from
`broadcasting.connections.reverb` — where _Laravel publishes to_ — rather than the `VITE_REVERB_*`
values the browser connects to; identical on one host, different behind a proxy, and wrong in the
direction that silently blocks the socket. The CSP exemption hard-coded `pulse` while `PULSE_PATH`
is configurable. `ignore_transactions` mixed path-shaped and method-prefixed patterns, so `/up`
would not have matched `GET /up` and the health check would have been traced every ten seconds for
ever. The `request_id` tag was gated behind "not console", which is false for a queue worker _and_
for the test asserting it. `security:posture` never reset its results between runs in one process.
An oversize-prompt refusal was being reported as a fault. The five Pulse entry types were string
literals in two files, which is a contract where a rename does not error — the column comes back
null and a dashboard figure quietly becomes zero — and are now an enum. The AI usage card's
aggregation moved into `AiUsageSummary`, because all three of its sums go wrong quietly and each
wanted a test. `Observability/` and `Support/` are now in ADR 0002's directory list, with why a
Pulse recorder is not a `Listeners/` listener. And the card's failure count was `text-red-500`,
about 3.8:1 on Pulse's white card and below WCAG AA.

**One limitation is now written down rather than fixed.** An exception that escapes a **queue job**
carries no `team_id`: every job activates its tenant with `TeamContext::run()`, which restores the
previous value in a `finally`, so the context has unwound by the time the worker reports. That fails
in the safe direction — a missing tag, never a false one, which is exactly why `run()` is right —
and a test pins the direction so nobody "fixes" it into a stale tag. Giving the queue layer a
durable notion of which tenant a job belongs to is the real answer and is not Phase 20's.

The missing tests the reviewers asked for are in too: the Pulse recorder round-tripped through real
storage, the latency mean (two calls at 300ms and 500ms plus three cache hits must read 400, not
160), the divide-by-zero guard, the card through Livewire with lazy loading off, truncation
exercised through `ClaudeAiClient` rather than only the interpolator, `DB::afterCommit` proven by
rolling the ledger write back, and a "never throws while annotating" test that now actually forces
a failure instead of passing whether or not the guard is there.

### Added — 2026-11-01 — Phase 20: observability and security hardening (ADR 0017)

Nineteen phases produced eighteen modules, a queue, five providers, an operator panel, a mobile app
and an `/api/v1` surface — and almost none of it was watched. `sentry/sentry-laravel` and
`laravel/pulse` had been in `composer.json` since Phase 0 and neither was wired: Sentry had a
published config file and no `Integration::handles()`, so an unhandled exception went to a log file
on one box; Pulse had a published config and a gate reading `in_array(optional($user)->email, [])`.

**Error reporting that sends an id, a tenant and a stack trace, and nothing about a person.** Three
Sentry projects — backend, browser, mobile — because a 500 in a worker, a `TypeError` in a browser
and a crash on a handset are triaged by different people with different questions. `send_default_pii`
is off, and `Core\Observability\SentryOptions` (the callables `config/sentry.php` points
`before_send`, `before_send_transaction` and `traces_sampler` at — static methods, because a closure
in config breaks `config:cache` and the first symptom is a deploy that will not boot) redacts every
sensitive key **at any depth** across request data, extra context, contexts, tags, breadcrumb
metadata and the user bag, and nulls the user's name, email and IP whatever set them. That second
layer matters more than the first: the realistic way a prospect's email address reaches an error
tracker is not the SDK, it is a `Log::error` payload three modules away becoming a breadcrumb.
**The scrubber is aimed at our own code.** It also tags `team_id`, `request_id` and `impersonated`
from `TeamContext` and `AuditContext`, which both hold the answer in a **queue job** as well as in a
request — and queue jobs are where the interesting failures are. Expected refusals (a wrong
password, a stale CSRF token, a 404, a throttle, a plan refusal, an exhausted AI allowance) are
filtered declaratively by `ignore_exceptions`, because an error tracker full of the application
working is one an ops team learns to ignore — which is the failure mode that makes every other alert
useless. Session replay is off and stays off: it records the screen of somebody working a list of
named prospects, and a debugging convenience is not a lawful basis.

**The browser DSN is served, not built in.** `HandleInertiaRequests` shares it as a prop, so one
`.env` value configures staging and production with no rebuild — and the CSP derives the Sentry
ingest origin from the same value, so the policy cannot allow a host the SDK does not post to. The
browser SDK is **dynamically imported**: no DSN, no 90 kB. Its `BrowserSession` and `Console`
integrations are removed, because a session envelope is the one thing the browser SDK sends with an
inferred IP address (and `before_send` never sees it) and a console breadcrumb is free text no
key-based scrubber can inspect.

**A nonce-based Content-Security-Policy, and the rest of the headers, on every response.**
`Core\Http\Middleware\SecurityHeaders` is **global** middleware rather than on the web or API
group: the headers belong to the HTTP response, so `/up`, a CSV download, a 500 page, the Pulse
dashboard and an `/api/v1` error envelope all carry `nosniff`, `X-Frame-Options: DENY`, a referrer
policy, a feature-denying `Permissions-Policy` and the two cross-origin policies. Registering it on
the groups is how the one route nobody thought about becomes the one route without a header. On HTML
it adds a CSP with `script-src 'self'` plus a per-request nonce and **no `unsafe-inline`** — which is
only affordable because this app has no third-party script: exactly two inline tags in
`app.blade.php`, both ours, both now nonce'd. `connect-src` is **derived** from the Reverb and
Sentry configuration rather than listed again, because a second copy of a hostname is a second place
to forget. Two skips, both deliberate and both written down: under the Vite dev server (Vue injects
component styles from JavaScript with no nonce, so a policy that held locally would hold _by
breaking the page_, which teaches a developer to switch it off) and on `pulse`/`horizon`, whose
inline Livewire bootstrapping we cannot nonce. HSTS follows the **connection**, not the environment —
sent from a `.test` domain it would pin a developer's browser to HTTPS for a year.

**Pulse and Horizon went behind the admin panel's own door.** `/pulse` shows every tenant's slow
queries, every tenant's exceptions and the shape of every tenant's queue; that is not a lesser
surface than `/admin`. `config/pulse.php` now runs `EnsurePlatformAdmin` (**404**, not 403),
`EnsureAdminTwoFactor` and `AdminIpAllowlist` ahead of Pulse's own `Authorize`, and
`ObservabilityServiceProvider` defines `viewPulse` **and** `viewHorizon` as platform admin plus
`AdminAbility::ManageOperations` — the 2am incident ability, not the Stripe switch.
`HorizonServiceProvider::gate()` is emptied so there is one definition rather than two. The
dashboard leads with the three cards Phase 20 asked for: queues, slow queries and an **AI usage**
card of ours, fed by a new `AiRequestRecorded` event so the billing ledger's write path never learns
that Pulse exists. It is keyed by **prompt key, not team**, and says on its face that it is not the
billing figure — it samples and trims after seven days, and the `ai_requests` ledger is
authoritative. Pulse is a clock, the panel is a tenant.

**The AI surface is bounded on input.** Phase 8 capped the _fetcher_; nothing capped the _assembled
prompt_, which is built from several fetched inputs plus free text a member typed. Now an oversized
untrusted block is **truncated** (`mb_substr`, because this text is Arabic as often as English, with
the notice _inside_ the data block after the content, where anything that tried to end with its own
instructions no longer is — and the nonce scrub still runs first, so the injection defence and the
truncation compose), and a whole prompt over the limit is **refused** with a 413
`AiInputTooLargeException` before the transport, so nothing is sent and nothing is charged. In
characters rather than tokens, because a token count is only knowable by making the call the bound
exists to avoid.

**A posture check, and a scheduled security workflow.** `php artisan security:posture [--strict]`
asserts what has to be true of a production _machine_ rather than of the repository: debug off, an
HTTPS `APP_URL`, a secure session cookie, CSP and HSTS on, admin 2FA required, a DSN present,
`send_default_pii` off. Every one of those is correct in `.env.example` and silently wrong on a box
somebody configured in a hurry, and several are switched off for an afternoon and left off. Warnings
(a report-only policy, an empty IP allowlist) do not fail a deploy, because a check that cries wolf
gets a `|| true` appended to it. `.github/workflows/security.yml` runs `composer audit`, `npm audit`
for both JavaScript projects and gitleaks over the **full history** — on pull requests and **every
Monday**, because a dependency that was clean when a pull request merged is not clean for ever.
Gitleaks runs as the release binary verified against the release's own checksums file rather than
through its action, whose v2 asks organisations for a licence key: a dependency on a vendor's
billing state inside the job that tells us about leaked keys. Dependabot now covers Composer and
both npm projects, not just Actions.

**And the token-abilities audit.** `RouteContractTest` already asserted that every `/api/v1` route
has a scope; `TokenAbilityAuditTest` asks whether it is the right _kind_. `EnsureTokenAbility` passes
a token carrying **any one** of the listed abilities, so `ability:leads:read,leads:write` on a `POST`
is a read-only token that can write — and it passes every Phase 17 assertion. No violations were
found; the test is there so the next one fails a build.

One real vulnerability was fixed in passing: a **critical** advisory in Vitest's worker pool, closed
by upgrading the mobile app's test runner to Vitest 5.

### Changed — 2026-11-01 — Lists and create/edit forms: one shared shell (UI pass, not an mvp phase)

The screens a team actually lives in — every list, and the forms behind them — were rebuilt onto two
shared component sets instead of being hand-rolled per screen. Seven list screens meant seven
answers to "is the header sticky", "can I order by this column", "what happens while the server is
thinking" and "how do I see what is being filtered out", and the answers were mostly _no_, _no_,
_nothing_ and _you don't_. The new pieces are catalogued on `/design` and the brand decisions behind
them are recorded in `docs/brand/brand-tokens.md` §7h.

- **`components/list/`** — `ListToolbar` (search, ordering, folded-away filters, the record count,
  and the summary of what is in force via `ActiveFilters`), `ListTable`/`ListHead`/`ListRow`/
  `ListCell`/`ListRowLink`, plus `ListSearch`, `ListSort`, `FilterGroup`, `FilterToggle` and
  `DensityToggle`. Ordering, filtering and paging all stay **server-side**: the URL remains the
  truth about what is on screen, so a list can be shared, bookmarked and walked back through with
  the browser's own buttons. Nothing in the set holds list state.
- **The filters fold away, and what they are doing is said in words.** The leads list used to open
  with every filter it has permanently expanded — six chip groups, about 300 pixels, above the first
  row — so a laptop showed four leads, and a member who scrolled past the panel had no way of
  telling whether eleven rows was the answer or the leftovers of a filter set last Tuesday. The
  panel is behind a disclosure that counts what is on, and the filters in force are removable chips
  that name their group. The same chips went onto the **signals** feed, where a shared link could
  show four signals out of two hundred with nothing on screen to say why.
- **Columns order from the column.** Company, Fit and Updated carry `aria-sort` and an arrow and
  re-request the list; the ordering select stays, because "newest first" has no column to live on.
  The header stays put as the rows scroll, a row is clickable across its whole width while still
  holding exactly one link, and row density is the member's own setting, remembered across every
  list.
- **A list says when it is thinking.** New `useNavigating()` — true while a GET to the same screen
  is in flight — dims the rows and announces the new count when it lands, instead of leaving stale
  rows looking like an answer.
- **`components/forms/`** — `FormSection`, `FormField`, `FormErrorSummary`, `FormActions` and
  `UnsavedChangesDialog`, plus `useUnsavedGuard()`. These are five fixes the long forms all shared:
  **not one of the forty-odd hand-wired fields in the product set `aria-invalid`**, so a field the
  server had rejected looked wrong and read fine; a rejected form showed a red line in the third
  section with nothing at the top of the page to say so and nothing moving focus; the save button
  sat below the fold; and nothing at all stopped a mis-clicked breadcrumb throwing away a
  half-filled lead. `FormField` owns the `for`/`id`/`aria-describedby`/`aria-invalid` wiring by
  construction; `FormErrorSummary` announces itself and links each failure into its field;
  `FormActions` is sticky and says whether anything is unsaved; the guard holds back a `get` to
  another path and asks in a real `Dialog` — never `window.confirm`, which cannot be translated and
  says "Leave site?" about a navigation that never leaves the site.
- **Leads list.** The hero carried five buttons of equal weight against the brand's one-CTA rule;
  discovery is now the primary, the two ways into the list are outlines, and import/export moved
  into a `More` menu. Each row carries a one-line summary of the six columns a phone cannot show.
  The **location filter** finally has a control: it was in `LeadFilterState`, the export honoured it
  and a saved view could carry it, but no element on the screen could set it.
- **Ideal customers.** The cards listed a profile's signals as equal-looking chips with a number on
  the end, which is backwards for a screen whose whole subject is the weighting — each signal now
  has a bar, heaviest first, and the **active** profile is hoisted to the front at full width
  because it is the one deciding how every lead in the account is graded. The editor says what a
  weight _means_ ("Important · 50", on `lib/importance`'s three bands, shared with the list so the
  two cannot drift) and carries a live summary of what the profile will do — this is the one form in
  the product whose output is a behaviour rather than a record.
- **Score screen.** The score is a dial (`ScoreDial`, coloured by grade from the same status tokens
  `GradeBadge` uses, D neutral rather than `error`). The breakdown finally shows `contribution` —
  the points each criterion was actually worth, which the server has sent since Phase 9 and this
  screen silently dropped, printing a bare "Weight 70" instead — ordered heaviest first, with
  `MeterBar` for the weight. **Bug:** the "Graded" timestamp was
  `new Date(...).toLocaleString()`, which formats in the browser's zone and locale, so the same
  score read differently depending on the laptop and in English inside an Arabic page; it goes
  through `useFormat().dateTime()` (team timezone) like everything else.
- **Do not contact.** Lifting an entry now asks first. Everywhere else in the product a single click
  undoes something a member can see; here it re-opens a person to contact, and "Lift" sat one
  mis-click from the row above it.
- **Outreach briefs.** Deleting a brief asks (it had no confirmation and no undo, next to "Edit"),
  pressing "Edit" on the sixth card now takes focus and the viewport to the form instead of opening
  it silently off-screen, and a long brief folds rather than making its card four screens tall.
- **Notifications.** Days are grouped as "Today"/"Yesterday"/date in the **team's** timezone, times
  are relative with the exact instant on hover, and a notification with somewhere to go is clickable
  across its whole width. New `useFormat().relative()` and `dayKey()`.
- **Discovery.** The runs list said what had run and how many it found and **nothing about when**, so
  three runs of one source were three identical rows; each row now carries its own time, how many
  leads were new, and — while it is still going — the live step and percentage that were already
  arriving over Reverb and only being used to flip a badge. A discovered company's name is now a
  link to the lead; it was plain text, which left the one thing a reader wants to open as the one
  thing they could not click.
- **The lead record page.** It opened by answering none of the five questions somebody opens it
  with: the grade was in a card down the right-hand column, the stage and the owner were inside the
  work panel below that, and how stale the data was lived at the very bottom of the page — so a
  member deciding whether to pick up the phone had to scroll to assemble it. There is now a header
  card with the name and a rail of five figures: fit, stage, who's on it, contacts, and the state of
  the data. The two full-width warning cards became one list that also carries **the notice that was
  missing — enrichment having failed**: a lead with three empty facts and no explanation reads as a
  bug in Alima, when it is usually a company with no website. The fit score is the `ScoreDial`
  instead of a number in a grey square. A contact's email or phone can be **copied** (new
  `CopyButton`), which previously meant selecting text inside a table cell — close to impossible on
  a phone — and role-based addresses now sort to the top, because the compliance note tells members
  to prefer them and the table was listing them in arbitrary order. The side column is placed by
  grid rather than DOM order, so on a phone the things a member _does_ to a lead are not below five
  sections of reading matter. In the work panel, the assignees were shown **twice** — as removable
  chips and again as the whole team in pressed toggles, two ways to take the same person off — and
  are now one row of toggles; its `<select>` was the last one flagged in `signals.md`.
- **Reviewed again** for the record page. Two more AA failures came out of it, and both were fixed
  **product-wide**, because both were rules this product had written down and then not followed:
  body copy on a status tint was `text-muted-foreground` (about **4.4:1** on a 12% warning or info
  tint, under AA) where §7f.3 says the words on a tint take the foreground colour — fixed here and
  in the add-a-lead form's compliance note; and `enriching` was an `info` **badge** in both the
  leads list and the lead's rail, which §7g.2 forbids until `status.info` is approved, so it is an
  outline badge with a spinner instead. Also fixed: the grade chip in the rail took its accessible
  name from an `aria-label` that replaced the badge's own, so a screen reader was told "see the full
  breakdown" and never the score; the restriction notice wore a `ShieldCheck`, which reads as "you
  are fine to go", and now wears scales; the website URL forced `dir="ltr"` on a full-width
  paragraph, which left-aligns a URL under a right-aligned Arabic company name; the contacts figure
  in the rail became an anchor into the contacts section rather than a count repeating it; and the
  copy button's 24px glyph got a 40px hit area.
- **A compliance record can be opened, edited and saved — including the market you are not based
  in.** The compliance centre lists one assessment card per market, and **none of them opened
  anything**: the single button above them carries no market, so it always opened the team's own
  record, and a UK team looking at the UAE card read "lead discovery is closed for this market"
  with nothing to click. The editor's own dropdown could reach it, but only once you were already
  inside the editor. Each card is now its own record — openable across the whole card, with the act
  it leads to named on it ("Write it" / "Open and edit it" / "Read it", by state and by what the
  reader may do) — and the button above names the team's market explicitly. Nothing changed on the
  server: `LiaController` already read `?market=`, `ResolvesCompliance` already authorised a
  transient per-market record, and drafts were already savable. **Three tests now cover the path
  the UI had been hiding**: opening another market's record, saving it as an independent second
  record without touching the team's own or moving its gate, and a plain member being refused the
  write on _that_ market rather than only on the one the policy was first exercised against.
- **The assessment editor got the form treatment the other two long forms got**, and it needed it
  most: this is the one screen in the product that is a legal document, and twenty minutes of
  careful wording could be lost to a mis-clicked breadcrumb. It now has a sticky save bar that says
  whether anything is unsaved, an announced summary of what the server rejected, `aria-describedby`
  and `aria-invalid` on every question (written out rather than taken from `FormField`, because
  here the prompt _is_ the regulator's question and `FormField`'s hint would shrink it to small
  print), and a guard on leaving. **The market picker is guarded separately**: switching market
  loads a different record from the same URL, and a same-path visit is exactly what
  `useUnsavedGuard` lets through by design — so the draft is defended there by hand, and backing
  out of the prompt puts the picker back to the market you are actually looking at.
- **A member who may not edit the assessment now gets it to read.** Any member may _view_ this
  record by design (`LiaRecordPolicy`: "a member working a list is the one who will be asked"), and
  they were being shown five **disabled textareas and no buttons, with nothing saying why** — which
  is also the worst way to read a document, since a disabled field is greyed, often clipped, and in
  some browsers cannot be scrolled. They get the record as prose and a line naming who can change
  it.
- **Everything else:** `useListDensity()`, `MeterBar`, `ActiveFilterChip` in `types/ui.ts`, and
  71 new EN/AR strings at parity (1 724 keys each). The remaining hand-rolled `<select>` call sites
  recorded in `signals.md` are gone from the leads filter bar, the bulk-action bar, the ICP editor,
  the discovery form and the do-not-contact form.

**Reviewed** by the `brand-reviewer` agent. No brand violation: Signal Orange is on no state, there
is no raw hex in the new components, and the 12px type floor holds. Nine findings were actioned:

- **A real bug** — a `raised` cell (the checkbox column, a row's tag list) and the sticky header
  were both `z-10`, and body cells come later in the DOM, so the checkbox column showed _through_
  the header band as the rows scrolled under it. Header to `z-20`, veil to `z-30`.
- **Four contrast failures**, all under AA at 12px and all now fixed: column headers were
  `text-muted-foreground` on the newly opaque header band (**4.47:1** — the band had to become
  opaque for the header to be sticky, so the text moved to `text-foreground/80`); the active-filter
  chip set its group name in `text-brand/70` (**3.4:1**) and its value in `text-brand` on a 10%
  tint of itself (**4.3:1** on the dark card, where `--brand` is still PENDING) — the brand now
  carries the tint and the words are `text-foreground`, which is §7f.3's own rule; the form
  section's step square had the same pair and became solid Teal with cream type; and `MeterBar`'s
  neutral fill was Ink 60 on the border token (**2.3:1** dark, under the 3:1 non-text minimum) —
  §7.7's Ink 60 figures are measured against the _card_, which is not where a bar sits.
- **The `/` shortcut was withdrawn.** A single-character shortcut bound to the document fails
  **WCAG 2.1.4 (Level A)** unless it can be turned off, remapped, or is active only on focus, and
  guarding it against fields and dialogs is none of the three. Recorded in brand-tokens.md §7h
  with how it comes back: as a preference, like GitHub's.
- **Two toggles contradicted their own state.** The density button and the filters disclosure
  flipped their labels while also setting `aria-pressed`/`aria-expanded`, so one read as
  "Comfortable rows, pressed" while the rows were compact. The name is now the thing being
  toggled; the sentence about the next click is the tooltip.
- **The error summary would not re-announce a repeat rejection.** It watched the _number_ of
  failures, so a second submit rejected for the same two reasons moved no focus and said nothing.
  It now takes an `attempt` counter and fires immediately on arrival as well.
- **Sortable columns were invisible on touch** (the handle was `opacity-0` until hover). Shown at
  rest at 60%.
- **Smaller ones:** `MeterBar` gained a `live` form (`role="progressbar"`) for the discovery run's
  climbing percentage, which a one-off `sr-only` phrase never announced; the notification rows'
  twenty identical "Mark read" buttons now name their notification; the timestamp is raised clear
  of the row's stretched link so its exact-time tooltip still works; Escape on an empty search box
  no longer fires a request that changes nothing; and `ScoreDial`'s figure gained `rtl:font-bold`
  (Tajawal has no 600).

Four findings were left as judgements and are flagged for the brand owner in **§7h.1**: the busy
veil lets the rows it covers fall below AA while it is up; the full-row click target costs text
selection in non-raised cells; the Orange full stop after a company name on the score screen
stretches §3's statement rule (pre-existing, and consistent with its sibling screens); and grade B
still depends on the PENDING `--info`.

### Added — 2026-11-01 — Admin panel: platform reporting & operations (Phase 19c)

- **The business's own numbers, on their own builders.** Thirteen platform reports, each also a CSV
  download: growth, the onboarding funnel, revenue by plan, MRR movement, retention by signup
  cohort, allowance consumption, provider health and spend, AI spend, cost per prospect, outreach
  deliverability, and **gross margin per tenant, per plan and per signup cohort**. Reporting's own
  Actions are deliberately **not** reused: they are scoped by design and each figure's cohort rule
  is written for one team's window, so running them unscoped would not produce a cross-tenant
  version of the same report — it would silently answer a different question, with no customer to
  notice. What is shared is the discipline and the plumbing: `DateRange` and its UTC rule,
  `Ratio::of()`'s refusal to divide by nothing, the `ReportColumn`/`ReportRow`/`Kpi`/`FunnelStep`/
  `BreakdownSlice` shapes, the validated chart set, and `ReportCsvExporter` — which grew `rowsFor()`
  and `filenameFor()` for this rather than being copied, because the thing that would have drifted
  between two writers is the formula hardening. `ReportKey` is **not** shared: a cross-tenant figure
  must not be reachable through a tenant's enum, where it would have leaked into the dashboard's
  tabs and `/api/v1/reports`' validation.
- **Gross margin per team — the number the business lives on.** Both halves have been recorded since
  Phase 5 (`ai_requests.cost_micros`, `discovery_runs.cost_micros`, and the plan's price) and
  nothing had put them next to each other: **a tenant can be on Scale and still be unprofitable.**
  Three rules keep it honest. Revenue is **prorated from the daily snapshots**, not taken as MRR, so
  a window of any length and a plan change mid-window are both accounted for day by day. Cost that
  cannot be converted — a provider currency with no configured rate — is **reported** rather than
  absorbed, which makes every margin on the page a stated _ceiling_ rather than a quiet lie. And
  spend with no tenant behind it is its own `unattributed` row, never divided across customers,
  because that would flatter every margin equally and be impossible to notice. A margin _ratio_ is
  null, not zero, where there is no revenue: a free-plan tenant costs us money and earns none, and
  "0%" reads as break-even.
- **`platform_daily_metrics`: a record over the ledgers, not a counter.** One table,
  `(date, metric, dimension) → value`, written nightly by `platform:roll-up` from the ledgers and
  recomputable with `--days`. It exists because **a cross-tenant aggregate over `ai_requests` is the
  one query in this product with no `team_id` to narrow it** — every tenant-facing index is scoped
  by the leading column of its own index, and the platform's figures are scoped by nothing. Nothing
  in the application increments it, so a crashed worker leaves the numbers unwritten rather than
  wrong (ADR 0012's rule for `usage_meters`, applied again). Three properties are enforced by test:
  running it twice changes nothing, a day's metric is replaced atomically so a dimension that went
  quiet is overwritten with a zero rather than left behind at yesterday's figure, and a **snapshot
  is never summed** — thirty days of MRR summed is thirty months of revenue, so the store reads a
  snapshot from the window's last known day and the decision lives there where no builder can get
  it wrong.
- **The rollup is scheduled at 02:40, and the time is load-bearing.** Compliance's retention sweep
  (03:40), the Signals prune (03:20) and the insights prune (03:50) all delete the rows these
  figures are computed from, and a period that has been swept cannot be recomputed — the same
  constraint `billing:sync-usage` runs hourly for. A test asserts the ordering against the **real**
  schedule, so a later phase moving a sweep earlier fails there instead of silently losing the
  business's numbers.
- **Two shapes of date, because the funnel is a cohort.** Most figures are event-shaped: yesterday's
  signups are yesterday's signups for ever. The onboarding funnel's rows are dated by the day a
  cohort _signed up_, and a team that signed up a fortnight ago and runs its first discovery tonight
  changes a row dated a fortnight ago — so those six metrics are **recomputed** over a trailing
  thirty days every night. Treated as event-shaped, every step after the first would have
  permanently under-reported. A third group — dunning, seats, allowance consumption, reported
  overage — is **point-in-time**: nothing historises it, so a backfill neither writes nor deletes
  those days and the command says so rather than leaving an operator to wonder.
- **Failed jobs, with the thing Horizon cannot tell you: whose job it was.** Every queued job in
  this product belongs to a tenant and the answer is in the serialised payload, so the panel digs it
  out — **by pattern-matching the string, never by unserialising it.** `unserialize()` on a payload
  instantiates whatever classes it names, which is a remote-code-execution primitive pointed at a
  table that exists precisely because something went wrong; a regex cannot hurt anybody, and a
  missed id renders as an em dash rather than a guess. Retry goes through `queue:retry` (the driver
  decides how a payload returns, and a batched job must be re-registered), with no transaction
  around it because wrapping an external push in one is what CLAUDE.md §2 forbids outright. A
  **delete** is the other way round: its audit entry is written _before_ the row goes, in the same
  transaction, because the payload is the only copy of that work.
- **Queue depth that admits what it cannot see**, and **schedule health Laravel does not store.**
  Depth is only readable on the database driver; on Redis the page names the driver and says so,
  because a queue depth of zero is the most reassuring number on an operations screen and must never
  be a default. The scheduler records nothing about past runs — there is no table, and
  `schedule:list` prints the next due time — so `RecordScheduledTaskRun` writes each one to the
  cache and the column is honestly headed _last seen_, not _last run_. A **skipped** run is recorded
  separately, because `withoutOverlapping()` quietly skipping a stuck overnight job every night
  afterwards looks exactly like a healthy schedule.
- **Provider health that refuses to draw a light it did not wire up.** The circuit breaker's state is
  live; the error and latency rates are the last seven complete days from the rollup, and the screen
  says which is which — a provider that started failing an hour ago shows a healthy rate next to an
  open circuit, which is the useful pairing rather than a contradiction. Three providers (the AI
  transport, the mail transport, Stripe) have **no breaker at all** and report "not instrumented",
  never "closed". The breaker is cache-backed and advisory by its own design, so every row carries
  that caveat. `Core\Support\CircuitBreaker` gained `isOpen()`, `failures()` and `reset()` — and the
  reset action calls `reset()` rather than `recordSuccess()`, which does the same thing, because an
  operator overriding the protection is not recording a success and the only place that knows how a
  provider is doing should not be written a small lie.
- **A cross-tenant audit viewer whose categories are the point.** `audit_logs` holds every tenant's
  ordinary activity, and the entries that matter when reviewing _operator_ behaviour are four rows
  in ten thousand — so impersonation sessions and operator reads are first-class filters rather than
  action strings you can only find if you already know them. The enum's case order is load-bearing:
  `admin.impersonation.started` and `admin.resource.viewed` both begin `admin.`, so the specific
  categories are declared before the general one and win. Read-only **structurally** — one `GET`, no
  Action, no route to change it, asserted over the whole route table. Reading the trail is the one
  cross-tenant read the panel deliberately does not audit: the same rule applied here would write a
  row to the trail on every look and bury what somebody came to find.
- **The three operator views earlier phases deferred to Phase 19, and in each case the missing write
  route is the decision.** `signal_runs` across tenants with cost, failures and durations (no
  re-run: a run spends the tenant's own Serper and AI allowance, which their own screen throttles).
  Compliance activity — sweeps, exports, erasures, opt-outs, assessments and retention policies —
  read from the **audit trail** rather than a table of its own, because those entries already carry
  counts and ids and deliberately no copy of what was erased, and a second activity table would
  either duplicate them or tempt somebody into recording the contents. Subscriptions, MRR and failed
  payments, which are now figures on the reporting screen. All three are read-only and
  `PlatformOversightTest` asserts the absence of a write route rather than trusting a comment.
- **Outreach oversight, because a shared sending domain means one tenant's behaviour is every
  tenant's deliverability.** Platform send volume and bounce/opt-out rates by channel, and the
  **global do-not-contact list** — which stays in `outreach_suppressions` with a null `team_id`
  rather than a second table (CLAUDE.md §3). That is not tidiness: `OutreachGuard` is still the only
  thing that answers whether a prospect may be approached, `SuppressionList` already consults the
  global scope in every tenant's send-time check, and a platform list kept anywhere else would be a
  list the guard does not read — so the enforcement was built in Phase 11 and needed nothing new.
  Adding goes through Outreach's own service so the value is normalised, hashed and masked exactly
  like every other entry; only a preview is ever shown, because only a hash is stored. **There is no
  lift route**, here or anywhere, and `removable` is rendered on every row — always false — so the
  absence reads as a rule rather than an oversight. Three of the five reasons are offered: a bounce
  is one tenant's technical fact and an erasure belongs to the tenant that received the request.
- **Two new abilities rather than stretching an existing one.** `reports.read` — the figures are
  aggregates and name no _person_, so reading them is not the privacy event `tenant.read` audits;
  and they are not only money, so hiding them behind the Finance ability would have hidden the
  onboarding funnel, the one report that says whether the product works, from everybody except
  Finance. (They are not anonymous either, and the config says so: the margin reports name
  **companies**, with what each pays and costs.) `operations.manage` — retry a job, reset a breaker,
  suppress an address platform-wide: **incident** actions for whoever is on support at 2am, who does
  not need the Stripe switch. Read-only deliberately holds the first and not the second.

### Fixed — 2026-11-01 — Phase 19c review: five figures that were wrong, and nobody would have noticed

The `laravel-module-reviewer` and `brand-reviewer` agents (CLAUDE.md §5a) found twelve and nine
items between them. These are the ones that were defects rather than polish, and all five of the
first group were numbers a dashboard would have reported confidently and incorrectly.

- **A snapshot that emptied froze instead of falling to zero.** Snapshot metrics only write rows for
  non-zero dimensions, and the reader took the window's last _populated_ day — so the night the
  platform's last paying tenant cancelled, MRR wrote nothing, the reader fell back to the last day
  that had revenue, and the dashboard reported the old figure for as long as the window contained
  it. A frozen number is worse than a zero. Two bookkeeping markers (`rollup_day`,
  `rollup_state_day`) make "the last day we know about" a fact about the **rollup** rather than
  about one metric; two rather than one, because a backfill reconstructs MRR for an old day but
  cannot invent that day's Stripe statuses, and reading a point-in-time snapshot against the wrong
  marker would report "no dunning" for every reconstructed day.
- **A night the scheduler missed booked the entire platform's revenue as movement.** Movement
  compared against "yesterday", and with no row there every paying tenant had a baseline of zero —
  so the next successful run reported them all as **new** on a first run and, worse, as
  **reactivated** on any later one, because `everPositiveBefore` was true. The baseline is now the
  last day actually recorded, so a gap produces the correct movement _across_ it; with no baseline
  at all, movement is left unwritten rather than invented.
- **The comparison window overlapped the current one.** A rolling preset's bounds are mid-day
  instants, so `DateRange::previous()` reduced to whole days both shared the boundary date with the
  current window and spanned one date more — biasing every "vs the window before" delta by about a
  thirtieth and counting a day's signups twice. `Admin\Support\PlatformWindow` does the arithmetic in
  date space: the same number of dates, ending the day before the current window opens.
- **Every churned customer read as never having paid us.** `subscriptions.stripe_status` is not
  historised, so a subscription the customer has since cancelled reads `canceled` today — and
  treating that as dead meant a backfill showed zero MRR for their entire paying history, with no
  churn in the series to explain where they went. Only `incomplete`, `incomplete_expired` and a
  `canceled` row with **no** end date are dead now; past due is money owed, not money that never
  existed.
- **`OverageRevenue` was registered as a flow.** `usage_meters.reported` is a running total for the
  open billing period, so each night re-recorded the whole month and any window summed about thirty
  copies of the same money. Latent — nothing displayed it yet — and now a snapshot.
- **The delete audit copied the exception's first line.** Failed-job detail sits behind
  `operations.manage` precisely because an exception message carries bound values — a provider error
  quoting an address, a database message quoting a parameter — and the audit viewer sits behind
  `audit.read`, which Finance and Read-only also hold. The entry therefore widened the audience for
  the very thing the narrower ability exists to contain. It records the class, queue, uuid and
  tenant; the logs keep the rest.
- **The per-plan margin could not show a loss on the Free plan.** A revenue-less tenant has no
  `TeamMrr` row, so keying the report on the plan from that row dropped it — and its cost with it.
  Free and trial tenants now land in an explicit "No revenue in this window" bucket. The same report
  also converted cost per tenant and _then_ summed, flooring each row: a thousand tenants at a
  fraction of a penny each reported no cost at all, overstating margin — the one direction this
  figure must not err in. It sums micro-units and converts once per group.
- **Three new cross-tenant reads were not audited**, unlike every other one in the panel. Signal runs
  show a tenant's watch names and a provider's error text, compliance activity names tenants and
  their assessments, and the failed-job list carries payload-derived tenant ids. All three record
  `admin.resource.viewed` with their filters and a count. The general audit viewer stays unaudited,
  deliberately.
- **Smaller, and still real:** the Actions paged the newest thousand failed jobs and searched them in
  PHP, quietly losing the job class and tenant id for anything older — on the screen whose reason to
  exist is a backlog; the suppression and its audit row were two separate writes and are now one
  transaction; the bulk insert would have exceeded MySQL's 65,535-placeholder limit at about 10,900
  tenants; and `dimension` at 64 characters would have let one long model name reject a whole day's
  figures under strict mode.
- **Retention asked the wrong question.** "Has this customer ever paid us" was computed from MRR, and
  a plan priced on application or granted manually contributes **zero** — so a cohort of Enterprise
  and invoiced customers read as having never paid a penny. A `team_paying` metric answers it
  without going through a price.
- **Brand.** `Providers.vue` printed `0 / 0` where a figure was null, breaking the em-dash rule the
  same file's comment cited; six scrollable table regions had no keyboard access and no focus ring
  (WCAG 2.1.1, 2.4.7); in-table links fell back to the browser's default outline instead of the
  panel's; the two-step confirmations were silent to assistive technology and could not be
  cancelled (both now `role="status"`, both cancelled by Escape); the **irreversible** global
  suppression was a single click while a retryable job drop was not, and its required note was not
  tied to the input; three freshness lines printed a raw timestamp on a page claiming UTC (there is
  a `utcDate()` now); `ReportTableView` formatted date cells in whichever team the operator last
  looked at, which a `utc` flag closes; and two status tints used `/8` and `/40` against §7f.2's
  documented `/12` and `/30`.

### Added — 2026-10-31 — Admin panel: platform controls, Stripe switches, plan features & provisioning (Phase 19b)

- **One question, four possible answers, and the order is the feature** — **ADR 0015**.
  `Core\Contracts\CapabilityGate` has a single method and is the only way anything in the product
  asks whether a team may use a feature. Three bindings decorate it in provider order: Core's
  permissive `AllCapabilitiesEnabled`, Billing's `PlanCapabilityGate` (the plan's own
  `capabilities` list) and Admin's `GovernedCapabilityGate` (suspension, the global kill switch,
  per-team overrides). Resolution is **suspension → kill switch → override → plan → permissive
  default**, asserted layer by layer and in collision. Two of those would be wrong reversed: the
  kill switch beats a per-team override because an operator reaching for it is dealing with
  something broken and hunting down every exception first is how an incident becomes an outage; and
  an override beats the plan **in both directions**, because "give this pilot a feature their plan
  excludes" and "switch this off for a customer whose own compliance team asked" are both real
  requests. A plan declaring **no** capability list falls through to permissive — a missing
  configuration is not a product decision, and the alternative would have switched six features off
  for everybody the day this shipped.
- **Not Pennant**, although it has been installed and unused since Phase 0 and this was the phase to
  decide rather than defer again. Pennant's model is a value that should stay _stable_ per scope once
  resolved; ours changes the moment a webhook, a grant window, an override expiry or a suspension
  changes, so using it would have meant inventing a cache-invalidation problem. It also has no
  ordering story, and an override's reason and expiry are first-class here where a flag value of
  `true` carries neither. **A paid entitlement is a term of a contract, not a feature flag.** Pennant
  remains the right tool for a rollout percentage or an experiment.
- **Six capabilities, not the thirteen the plan listed.** `signals`, `ai_insights`,
  `business_intelligence`, `enrichment`, `csv_export`, `api_access` — and only `ai_insights`
  (Starter+) and `signals` (Team+) are actually restricted, matching what the price list already
  promises. Gating outreach channels, multiple ICPs or saved views means deciding they cost money,
  which is a commercial decision and not a build task; the rest are declared, enforced and on for
  everybody, so making one paid is a line in `config/billing.php`. Every case must name its
  enforcement point, and `CapabilityEnforcementTest` **walks the enum and proves each one refuses** —
  the previous test only checked the string was non-empty, which is no test at all.
- **Enforcement in the guards, not middleware** — ADR 0012's argument unchanged, because a queued
  discovery job and a scheduled watch spend a team's allowance with no request behind them.
  `capability:` middleware is the courtesy half so a member who clicks a link their plan excludes
  gets a sentence (402) rather than a half-rendered page, and a shared `capabilities` prop keeps the
  nav from advertising what the plan does not include. `CapabilityExplainer` reports **which layer
  decided**, which is the condition ADR 0015 accepts four layers on: a product where somebody has to
  guess why a feature is off is a product nobody can support.
- **A plan grant outranks a live Stripe subscription**, which is what makes "turn Stripe off" and
  "take somebody's plan away" two different acts. `team_plan_grants` carries its own
  seats/leads/credits, so Enterprise finally gets the numbers a signed contract says rather than the
  price list's "unlimited"; `null` on a grant means "use the plan's number". Granted teams get
  `SubscriptionState::Granted`, because the billing screen has no card to change, no invoices and
  nothing to cancel. Precedence: **grant (inside its window) → live subscription → trial → default
  plan**.
- **`teams.billing_mode`** (`stripe` / `manual` / `disabled`) is a separate axis and **grants
  nothing**. It stops checkout and usage metering — reporting overage against a subscription Stripe
  is not billing is how a command logs an error every morning — and leaves entitlement alone. Which
  means a team moved off Stripe without a grant is on the **default** plan, and if it was trialling
  it drops there quietly when the trial lapses. The migration's first draft claimed the opposite;
  the dry run and the mode-change toast now say what actually happens.
- **"Stripe is off" is a product state, not an outage.** The container binds
  `DisabledBillingGateway` over the real gateway so a checkout attempt is a `BillingException` with
  a sentence, the billing screen does not render a button that would throw, and
  `SyncSubscriptionFromStripe` **ignores a webhook that arrives anyway** — the middleware that 404s
  the route is defence in depth, but a misconfigured proxy or a route cached before the switch
  flipped must not change a team's entitlements while an operator believes Stripe is not being
  listened to. It returns rather than throws, because Cashier has already answered 200. The rule the
  tests exist to pin is that **turning Stripe off does not turn entitlement off**: a team on Scale
  stays on Scale with its allowances intact and its usage sync running.
- **Platform settings are a schema, not a key-value bin.** Every key `platform_settings` may hold is
  declared in `config/admin.php` with a type, rules, a default and help text, and **both `get()` and
  `set()` throw on an undeclared key** — every key is a literal in our own code, so an undeclared one
  is always a typo, and a typo that returned null was the worst available failure:
  `bool('billing.stripe_enable')` reads as false, which switches Stripe off and looks exactly like a
  working screen. Writing the declared default deletes the row, so the table stays a record of
  deviations. A declaration can carry an `allowed` list, which is what stops `signal` being saved as
  a kill switch that kills nothing — discovered during the incident you reached for it in. The screen
  is generated from the declarations, lists the pending changes in words, takes a second press before
  switching anything off platform-wide, and saves the page in **one** transaction.
- **Provisioning a business end to end**, calling Auth's own `CreateTeamAction` rather than
  reimplementing it, so a tenant onboarded by sales is indistinguishable from one that signed up —
  same Owner membership, same `team.created` audit entry, same trial-starting model event. A **dry
  run** first, because the first real use of this screen is somebody typing a customer's name wrong
  and the second is discovering the owner's address already belongs to an account, which an operator
  must be told _before_ a stranger is emailed into somebody else's business. **An operator never
  chooses a password:** a new owner gets a reset link after commit, through 19a's own
  `SendUserPasswordResetAction`, so a provisioned account's first credential leaves the same audit
  trail as an operator pressing "send reset link" on day ninety.
- **Platform announcements**, scheduled, targetable by plan and market, dismissible **per person**
  rather than per team, EN/AR because tenants read them, with a kill switch for the banner that turns
  out to be wrong. The notification fan-out is **aggregated** — one per team through
  `NotificationDispatcher::toTeam()`, never a loop over every member of every tenant — and it took
  three guards, not the two planned: `ShouldBeUnique` stops a double-saved form, `notified_at` stops
  a retry of a job that _finished_, and `announcement_notifications` (a per-tenant claim with a
  unique index, taken **before** the send) covers the case this job actually hits, because it walks
  every tenant and a crash at team 3,000 of 4,000 re-notified the first 2,999.
- **The two notifications both module docs had parked:** `payment_failed`, raised by
  `SyncSubscriptionFromStripe` on `invoice.payment_failed` and sent `toAdmins` because it is money a
  Member can neither act on nor be expected to care about; and `assessment_review_due`, raised by a
  new `compliance:notify-reviews` command. The second closes a gap that was indefensible rather than
  merely missing: the compliance screen said a lawful-basis review was due and the gate closed when
  it lapsed, but nothing ever _told_ anybody, so a team found out when their next discovery run was
  refused. Two notices per cycle, and `lia_records.review_notified_at` is what lets the window be
  wide enough to survive a missed scheduler morning without becoming a daily reminder for a
  fortnight.

### Fixed — 2026-10-31 — Phase 19b review (Phase 19b)

Both review agents (CLAUDE.md §5a) found real defects, and the backend ones were not style.

- **Privilege escalation through provisioning.** The screen is behind `teams.manage`, which `support`
  holds, and it calls `GrantPlanAction` and `SetCapabilityOverrideAction`, whose own routes need
  `billing.manage` and `settings.manage` — so a support operator could POST `grant_plan=enterprise`
  with `capabilities_on[]=signals` and hand a tenant an unlimited plan and feature overrides through
  a route that never mentions billing. `ProvisionTeamRequest` refuses those fields without the
  matching ability, rather than stripping them: a team provisioned without the plan the form showed
  is worse than an error naming the ability needed.
- **Two capabilities declared an enforcement point that did not exist.** Nothing under `/api/v1`
  consulted the gate at all, so the platform kill switch could not stop API traffic and a token
  outlived the plan that justified it; and the insights API had no capability check although the web
  screen did. `capability:api_access` now guards the whole api group, `capability:ai_insights` the
  insights API routes, and `IssueApiTokenAction` checks before issuing — the mobile two-factor
  exchange has no session for route middleware to hang on. This is exactly the "declared but not
  enforced" case the enum's own docblock calls worse than no capability at all.
- **Every validation rule on the settings form was a no-op.** A setting name _contains dots_, so a
  rule keyed `settings.billing.stripe_enabled` is read by Laravel as three levels of nesting the
  payload does not have — `data_get()` returns null and `sometimes` therefore skips the rule.
  `UpdateSettingsRequest` validates each setting under a dot-free alias and maps the errors back to
  the dotted name the screen indexes by.
- **A settings save was one transaction per key**, so a failure part-way through left whichever half
  came first in the config file applied — in a file full of kill switches.
  `UpdatePlatformSettingsAction` wraps the page, keeping one audit entry per key.
- **`CapabilityGate` was `bind`, not `scoped`**, so `extend` rebuilt the whole decorator chain on
  every resolution: the per-team memo the docblocks described was per-resolution, and `forget()`
  cleared a throwaway instance.
- **`RevokePlanGrantAction` restamped `revoked_at`** on an already-revoked grant, moving the date a
  tenant's entitlement actually ended — the one thing the kept row exists to answer — and writing a
  second audit entry for a decision made once. `PublishAnnouncementAction::retire` had the same
  shape. **`GrantPlanAction` revoked only grants _in force_**, so a future-dated grant stacked
  behind a new one and which won next month became a tiebreak rather than a decision; it now revokes
  every un-revoked grant under a row lock.
- **`Announcement::dismissedBy()` used `withTimestamps('created_at', null)`**, which does not mean
  "no `updated_at`" — Laravel reads the null as unspecified and falls back to the parent model's
  column, so every dismissal 500'd on a column that does not exist.
  **`PublishAnnouncementRequest::attributes()`** collided with `FormRequest`'s own hook, which the
  validator calls while building messages and therefore _before_ `validated()` is available: every
  failed validation died with "Call to a member function validated() on null" instead of rendering
  the error bag, so the screen looked broken precisely when an operator had made a typo.
- Also: `SettingDefinition::fromConfig` read a mistyped `type` as `Bool`; the assessment-review
  command had an N+1 and no `onOneServer()`; `ends_at => after:starts_at` errored when `starts_at`
  was blank; and `AnnouncementBoard` reached for the raw table builder where the relation already
  described the pivot.
- **Brand.** The announcement banner told `warning` from `critical` **by colour alone** (WCAG 1.4.1)
  — each level now has its own icon and a visually hidden level word — and rendered operator-authored
  per-locale copy with no `lang` or `dir`, so an Arabic reader handed the English fallback got
  right-aligned English with its punctuation in the wrong place; `AnnouncementBoard` now returns the
  locale each line _actually_ resolved to. Status-coloured 14px titles sat on a 12% tint of the same
  status at roughly 4.1:1, under AA — the status token is now the icon, border and tint, and the
  words are `text-foreground`. Four destructive actions (retire an announcement, revoke a grant,
  clear an override, switch something off platform-wide) were single unconfirmed clicks. Admin
  timestamps were formatted in the **viewer's team** timezone with no label, which for an operator is
  either nothing or a borrowed one from an impersonated account, so a contractual grant date read
  differently depending on whose account they last opened; admin screens use `utcDateTime()` and the
  `datetime-local` inputs say UTC, which is what the server reads them as. Several banner and row
  actions shared a generic accessible name, and the "Overridden" badge spent `status.warning` on a
  non-problem. Recorded in `docs/brand/brand-tokens.md` §7f, including the items still **PENDING**
  brand-owner approval: `status.info`, the dark-mode status values, the three-level notice scheme and
  the 12% tint.

### Added — 2026-10-31 — Admin panel: foundation, identity & cross-tenant CRUD (Phase 19a)

- **Built ourselves, not with Filament** — **ADR 0014 supersedes ADR 0009**, which had planned
  Filament v5 with an Inertia fallback and left the decision to be confirmed at this phase. Four
  reasons, the first decisive: Filament's generated screens **write straight to models**, which is
  precisely the second, unvalidated write path the Action/transaction/audit rules in CLAUDE.md §2
  exist to prevent — and it would be the _default_, needing discipline on every resource to avoid
  rather than discipline once to allow. Several of this product's invariants live only in those
  Actions (the last-Owner refusals, the suppression entry that makes an erasure stick, the Stripe
  round-trip on a plan change). The rest: a second Livewire/Blade stack to keep brand-compliant, a
  second place tenancy can quietly fail, and the fact that the hard requirements — plan-driven
  feature flags, manual billing, provisioning, margin reporting — are bespoke under any framework.
  The CRUD was the cheap part.
- **The platform identity is one column, not a second guard.** `users.admin_role` →
  `Admin\Enums\AdminRole` (`super_admin`, `support`, `finance`, `read_only`), with ten abilities in
  `AdminAbility` and the role-to-ability mapping in `config/admin.php` — a reviewed diff rather
  than a `match` buried in a middleware. ADR 0009's "admin guard separate from tenant UI" was
  reversed: a second guard over a second table forks 2FA, passkeys, password resets and the audit
  trail, and what you want is one person with one credential and one trail. Four roles rather than
  a boolean because reading a tenant's prospect list, impersonating a customer, suspending a team
  and turning Stripe off are different powers. **Two-factor is required** —
  `ADMIN_REQUIRE_TWO_FACTOR`, default on; one super-admin credential reads every tenant's data, and
  a password alone is the wrong lock. **404 for a non-operator, 403 for an operator without the
  ability**: a 403 on the door confirms there is something behind it.
- **Crossing the tenancy boundary deliberately, and the test that makes it stick.**
  `BelongsToTeam`'s global scope is a **no-op when no team is active**, so the panel's cross-tenant
  reads work by _removing_ a constraint — which is exactly what an admin index needs and exactly
  what must never happen anywhere else. `PlatformContext` clears `TeamContext` and refuses to run
  on a route not named `admin.*`; `AdminRouteContractTest` asserts from the other side that **no
  route outside `admin.*` carries it**, that every route inside carries the whole five-part stack,
  and that each declares exactly one ability the enum defines. Phase 17's precedent, for the same
  reason: a per-route test only covers the routes somebody remembered to write one for, and here
  one forgotten middleware on one route is not a missing permission check — it is every tenant's
  data served to whoever asked. Reading **one** tenant goes through `TeamContext::run()`, so there
  is one implementation of tenancy rather than two that can disagree.
- **Cross-tenant reads are audited as privacy events.** An operator opening a tenant's prospect
  list leaves an `admin.resource.viewed` entry with the resource, the filters and a count — ids and
  counts only, never a row, a name or an address, because a log of who looked at personal data must
  not itself be a copy of it. An update records the _names_ of the fields that changed, not the
  values. **The subtle bug this phase had to get right first:** `AuditLogger` stamps `team_id` from
  `TeamContext`, which the panel has deliberately cleared, so every admin entry would have landed
  with `team_id = null` — the row exists, nothing errors, and the per-tenant audit view a
  customer's support ticket is answered from shows nothing at all. `AdminAudit` passes the target
  team explicitly and writes inside `TeamContext::run()`.
- **CRUD over all forty resources, from forty descriptors and three pages.** A declarative
  `Admin\Resources\<Name>Resource` per model — columns (reusing Reporting's `ColumnFormat`),
  detail-only columns, editable fields with their rules, searchable columns, filters, sorts, eager
  loads — listed in `config/admin.php`, which is a single readable answer to "what can an operator
  see and change?". Three rules hold it in shape, each enforced by a test: descriptors return
  **data** (anything that needs to decide something gets a bespoke screen, which is what the team
  and user operations controllers are); where a module owns the operation the panel **calls that
  module's Action**; and `allowsCreate`/`allowsUpdate`/`allowsDelete` all default to **false**.
  Create is permitted on two resources and only where every column the table requires is a declared
  field — `lead-tags` fails that, because `slug` is derived and deriving a column is logic. Delete
  is permitted on three, and the contract test pins the list, so changing it is a failing test and
  a conversation rather than a quiet diff. The cross-tenant index over `leads` is the **only query
  in the product with no `team_id` in its `where` clause**, so sorts and filters are whitelisted
  against the descriptor, searches bind rather than build SQL, pagination has no "all" escape
  hatch, and a dotted column without its eager load fails the contract test.
- **Team suspension, enforced in three guards rather than in middleware.** `teams.suspended_at`
  plus a **required** reason — a suspension nobody explained is one the next operator cannot safely
  lift, so the customer stays switched off over a missing sentence. What stops is **sourcing,
  sending and spending**; what does not stop is signing in and reading, deliberately: locking
  people out of data they own punishes the colleague who did nothing, and a tenant who cannot reach
  their own data cannot exercise their rights over it either. ADR 0012's argument decides _where_:
  a queued discovery job and a scheduled signal watch spend a team's allowance with no request
  behind them. Admin registers **last** and **decorates** (`extend`, never `bind`) Billing's
  `CreditSource` and `LeadQuotaSource` and Outreach's new `TeamSendingGate` — a bind would have
  replaced Billing's plan-backed source with one that had to know what a plan is. Lifting a
  suspension **keeps** the reason, because a lifted suspension is still a thing that happened.
- **Outreach gains a third seam rather than a shortcut.** `Contracts\TeamSendingGate` with
  `AlwaysAllowTeamSending` as the permissive default (Auth's `UnlimitedSeatLimit` reasoning: a
  default that refused would block sends for a suspension nobody applied), consulted by
  `OutreachGuard` beside the deployment-wide switch, refusing with the new
  `OutreachBlockReason::TeamSuspended`. **Binding over `LeadRestrictionResolver` would have been
  one line**, and it would have had the guard tell a member that _this prospect_ may not be
  approached — a false statement about somebody else's legal position, written into the team's
  compliance history as though it were true.
- **Impersonation, defined by what it cannot do.** Start is behind `admin.can:impersonate`
  (`super_admin` and `support` only — never `finance`, never `read_only`); **stop is deliberately
  not an `admin.*` route**, because by then `Auth::user()` is the customer and an admin-gated stop
  would 404 the only way back. One list in `config/admin.php` of route-name patterns blocks
  outreach sending, every billing write, erasure and export, CSV exports, passwords, email, 2FA,
  passkeys, API tokens, and the team and account themselves — one file rather than a middleware
  sprinkled across eight modules' route files, because that would be eight places to forget. An
  operator cannot impersonate another operator, the session expires after 60 minutes, and the
  banner lives in the **app shell** so it is on every tenant screen rather than the screens
  somebody remembered. And `impersonated_by` rides in Core's new ambient `AuditContext`, so **every
  write the session makes — through whichever module's Action, none of which know impersonation
  exists — records the operator behind the customer**. That ambient bag is the one Core change the
  phase needed: there is no layer between an Action and the logger to annotate from.
- **Admin chrome, inside the brand.** Its own `AdminLayout` and server-driven sidebar, visually
  distinct so an operator never mistakes it for a tenant screen — a Deep Ink shell, DM Mono labels,
  a cream content surface (§2a), never a colour nobody chose and never Signal Orange, which is
  decorative only. Timestamps are **UTC everywhere**, which falls out of an operator belonging to
  no team and is correct rather than incidental. **English only, by decision**: an internal tool
  carries no EN/AR or RTL obligation, and the panel is **web only** — there is no `/api/v1/admin`,
  because a token that can read every tenant is a liability no ops task in the MVP needs.
- **`SuperAdminSeeder`** — `super@admin.com` / `password`, super admin, verified, and **in no team
  with no `current_team_id`**, which is the point of seeding one rather than promoting a demo user:
  an operator belongs to no tenant, so the panel is exercised the way it is actually held. It
  **refuses to run in production** unless both `ADMIN_SEED_ALLOW_PRODUCTION=true` and `--force` — a
  seeder that plants a known password on a public host is not a convenience, it is the breach — and
  it refreshes the role on a re-seed while **never resetting a password somebody has changed**. The
  account has no second factor, so its first `/admin` visit is the enrolment screen: the correct
  local experience, written into the runbook rather than excepted in the middleware.
- **Tests: 66 new** (1520 → 1586), of which the ten in `AdminRouteContractTest` are the ones the
  panel's isolation rests on. The rest: every admin route answering 404 to a signed-in customer
  (swept over the whole route table, not a list of paths), each lower role refused its forbidden
  actions, a cross-tenant index returning more than one team's rows **and** a drill-down returning
  exactly one's, reads and writes audited with the target team, a posted field the descriptor never
  declared being ignored, delete refused where the descriptor forbids it, impersonation's whole
  forbidden list, suspension blocking sourcing/sending/spending **from a queue with no request
  behind it** while sign-in and reading still work, and the seeder's idempotence and its production
  refusal. Phase 16's `PlanEnforcementTest` was updated rather than worked around: it now asserts
  the decorator is bound **and** that the limit it reports is still the plan's, which is the
  property that matters — a decorator answering on its own would silently drop every plan allowance
  in the product, and the class name alone would not show it.
- **Then reviewed, and eight real defects fixed.** The `laravel-module-reviewer` and `brand-reviewer` agents (CLAUDE.md §5a) both found things, and the backend ones were not style: (1) the registry's **write** routes never consulted the descriptor's own ability, so `support` — which holds `tenant.write` — could rewrite the platform-wide **AI prompt registry** and change every tenant's next AI call; a new `platform.write` ability and a gate on store/update/destroy close it. (2) Account operations refused nothing when the target was another **operator**, so `support` could clear a super admin's second factor and leave `EnsureAdminTwoFactor` politely sending them to re-enrol — a lower role taking the second lock off a higher one; now super-admin only, via `ProtectsOperators`. (3) `Auth::logout()` during impersonation cycles the **authenticated** user's remember token, and that user is the customer: an expiring session signed a real customer out of every device they had ticked “remember me” on. Expiry now hands the operator back to themselves instead, and `logout` joined the blocked list. (4) The audit entry for an index stored the **raw search term**, and operators search by email address — so the log of who read personal data was itself accumulating it, in an append-only table outside Compliance's retention sweep; it records `searched: true` now. (5) `DatabaseSeeder` called `SuperAdminSeeder` unconditionally, so a `db:seed --force` in production aborted the whole run at the refusal and skipped everything after it. (6) The seeder would **promote** a pre-existing `super@admin.com`, which on any non-production host with open registration is a privilege escalation waiting for somebody to register the address; it now refuses an account that belongs to a team, which is what registration always creates. (7) `AuditContext` was a singleton that nothing reset, so `impersonated_by` could stamp a later request's entries in the same process — a _false attribution_ in an audit trail, not a missing field; it is `scoped`. (8) The blocked-route list had real gaps — un-suppressing a do-not-contact entry, editing the lawful-basis declaration, switching teams, accepting an invitation, spending the customer's allowance — and its `teams.store` pattern did not match `api.v1.teams.store`, because `Str::is()` is anchored. Also fixed: the panel's logo rendered Teal on Ink (a “Never” in the book, and 2.1:1) below its 120px minimum width; the impersonation banner printed the customer's local time labelled UTC, and was an English sentence with no `dir` on screens that may be Arabic; member removal was a one-click ghost button; eyebrow labels sat at 10.4px under a 12px floor; and `aria-sort` and per-row link names were missing. Three §2 layering violations went with them: five account operations became Actions with their writes and audit entries in **one** transaction, and the dashboard's and team page's queries moved into `PlatformOverview` and `TeamOverview`.
- **Fixed: `migrate --seed` was broken, and the suite could not see it.** `DemoSeeder` mass-assigned `business_profiles.version` and `completed_at` — real columns deliberately kept off `$fillable`, because they are ours to set rather than the onboarding form's — which throws under `Model::shouldBeStrict()`, i.e. everywhere but production. So nobody could seed the demo tenancy at all. It survived because **`$this->seed()` returns a `PendingCommand` that only executes when it is destructed**, and an exception thrown there never reaches the assertion count: twenty tests called it and none of them noticed. The seeder now mirrors `CompleteOnboardingAction` and `forceFill`s those two columns, and `tests/Feature/Core/DatabaseSeederTest` invokes the seeders **directly** — which is the real lesson, since `$this->seed()` is fine for a test that then asserts on what a seeder produced and useless for asserting that it ran. Pre-existing, and unrelated to the admin panel beyond having been found while building it.
- **Deferred, and said out loud:** no platform-user deletion. Deleting a user who is a team's last
  Owner needs a product decision (delete the team, transfer it, or refuse?), and the panel can
  already remove a member, suspend a tenant and erase a _data subject_ through Compliance. **Phase
  19b** owns the settings store, the Stripe switches, plan grants, plan-driven capabilities over
  Pennant and provisioning; **Phase 19c** owns platform reporting and the operator views Signals,
  Compliance and Billing each deferred here.

### Added — 2026-10-31 — Mobile app (Phase 18)

- **The app.** `/mobile` — Capacitor + Ionic Vue + TypeScript, its own npm project, consuming
  `/api/v1` with a scoped Sanctum token. Sign-in (with the two-factor challenge), a dashboard, the
  leads list and one lead, stage moves, notes and tasks, an AI draft you can edit and send, logged
  approaches, the bell, and settings for language, theme, push and signing out. Four tabs, and
  deliberately not a second web app: discovery, ICPs, scoring, the board, templates, the
  do-not-contact list, notification preferences, team, tokens, billing and compliance all stay on
  the web. A settings screen duplicated across two surfaces is a decision that can be made
  differently in two places.
- **Shared, not copied.** Two Vite aliases reach up into the Laravel app: `@shared` →
  `resources/js/shared/` (the typed API client, the error envelope, every payload type, the brand
  tokens) and `@lang` → `lang/` (the product's own translation bundles). The master logo path
  **moved** to `shared/brand/artwork.ts`, so the launcher icon, the launch screen, the notification
  icon and both apps' screens all draw the same mark — a logo that exists twice is a logo that
  drifts. `en.json` is not imported at all: it is an identity map, so `t()` falls through to the key
  and 120 KB stays out of the bundle; Arabic loads on demand.
- **Two-factor over the API, in one request.** `POST /auth/token` used to refuse a 2FA account
  outright with "create a token from the web app" — which left anyone with 2FA switched on unable to
  use the app at all. Fortify's own challenge is a two-request dance over the session, and a phone
  has neither the session nor the redirect, so the exchange now settles the password **and** the
  second factor together: a first post answers `423 auth.two_factor_required` with
  `details.methods`, and the client re-posts the same credentials plus `code` or `recovery_code`.
  The password is checked **before** anything reveals that the account has a second factor, so the
  423 is never a way to probe which accounts have it on; nothing is parked server-side between the
  two requests, so a half-finished challenge is not itself a credential; and a recovery code is
  spent and replaced exactly as the web challenge spends it. What counts as a valid code is still
  Fortify's — same provider, same encrypter, same codes.
- **Push, wired end to end.** The server half shipped in Phase 12; this is the handshake. Permission
  is asked for on a deliberate tap in Settings and never at launch, because asking before the member
  has seen anything worth being told about is how an app collects a permanent "no" on its first
  screen. The registration token is posted from the OS's own asynchronous `registration` listener
  rather than from the button, so a token the OS rotates unprompted is sent up too. Sign-out
  deregisters first and is best-effort: a handset with no signal must still be able to sign out of
  itself.
- **EN/AR with real RTL.** `dir` goes on the root element, which is what flips Ionic's chrome — the
  back chevron, the list arrows, the slide transitions and every logical margin — and not merely the
  text. Arabic is Tajawal, ~10% larger, never letter-spaced; the logo is forced LTR because it is
  never mirrored in any language; and identifiers (a score, a company number, a scope string) stay
  LTR inside the Arabic layout.
- **Native projects, with the brand generated from the vector.** Android's adaptive launcher icon,
  launch screen and notification icon are vector drawables written from the master logo path by
  `npm run artwork`, with the palette in `colors.xml` and a `values-night` variant. The
  `POST_NOTIFICATIONS` permission is declared, and cleartext HTTP is permitted by a **debug-only**
  manifest — so a release build cannot send a bearer token over plain HTTP however `VITE_API_URL` is
  set. On iOS, `AppDelegate.swift` carries the two APNs callbacks Capacitor cannot add for you (the
  omission that looks like a working app right up until a notification is due), `Info.plist`
  declares `en` and `ar`, and the launch screen is a brand colour asset with a dark variant.

### Fixed — 2026-10-31

- **Cross-origin callers could not read two headers they are told to branch on.** The framework's
  CORS defaults leave `exposed_headers` empty, and a browser hides every response header a
  cross-origin response did not explicitly expose. So `X-Api-Version` and a `429`'s `Retry-After`
  came back **empty rather than wrong** — the version pin unverifiable, and a throttle with nothing
  to wait on, in the one client that reads both. `config/cors.php` is now published and names them,
  with credentials deliberately off (the token authorises the call, so allowing cookies
  cross-origin would open a CSRF surface for a caller with no use for it). Latent until Phase 18,
  because the web app is same-origin and nothing else had ever called the API from a browser.

### Fixed — 2026-10-31 — the Phase 18 review round

Found by `laravel-module-reviewer` and `brand-reviewer`; the full list with reasoning is in
`docs/modules/mobile-app.md` under "Review".

- **The second factor had no per-account cap.** The token endpoint's limiter is keyed on email
  **and IP**, which is the right shape for guessing a password and the wrong one for a six-digit
  code: an attacker who already holds the password buys five more guesses with every rotated
  address. Failures are now counted against the **user**, where rotating buys nothing. The
  challenge itself is not counted — nothing was guessed.
- **A recovery code could be spent twice under concurrency, or spent for nothing.** The match and
  the replace were a read-modify-write on an in-memory model; the spend now happens under a row
  lock, and the challenge and the token mint share one transaction, so a failure while minting no
  longer burns a one-time credential.
- **An unknown email answered measurably faster** than a real one with a wrong password, which is
  an enumeration oracle. Pre-existing; closed by verifying against a throwaway hash.
- **Six choice pickers were unreachable without a mouse or a finger.** The stage, tone, language,
  variant, channel and outcome controls were chips with click handlers — no role, no tab stop, no
  pressed state, selection carried by fill colour alone. They are real buttons now, in labelled
  groups, with `aria-pressed` and 44px targets. This was WCAG 2.1.1 and 4.1.2 failing on the app's
  main write interaction.
- **The logo appeared in three colourways the brand forbids** — Teal on Ink on the dark sign-in
  screen, and a Teal _tint_ in the Settings footer and on the Android night splash, the last
  because its drawable referenced a colour the night theme redefines.
- **The native dropdowns were under 16px**, which makes iOS zoom the whole page whenever one takes
  focus, and under the 44px touch minimum.
- **The Arabic size bump did not reach Ionic's own text.** Scaling the root scales what is sized in
  `rem`; Ionic sizes its components in `px` inside shadow DOM, so the toolbar, tabs, buttons and
  segments — the chrome a member touches most — stayed at Latin size.
- **The unread badge was the error colour**, the eyebrow 11px against the book's 12, the focus ring
  2px against the token's 3, and buttons 8px-radius under a comment that said pill.
- **The KPI delta assumed a rise is good.** The server sends no polarity, and `ai_spend` is a tile
  where a rise is bad — nothing was wrong on screen because that tile has no delta, but it would
  have been the day it got one.

### Changed — 2026-10-31

- `resources/js/components/brand/artwork.ts` moved to `resources/js/shared/brand/artwork.ts`. The
  three web call sites import from the new path; nothing else changed.
- The root project's lint and format passes now skip `mobile/`, which has its own oxlint and oxfmt
  configuration set to the same rules and the same style. Not to lint it less, but because the root
  pass also reached its committed native projects — Gradle files, Xcode asset catalogues and a
  `project.pbxproj`, none of which are ours to format.

### Added — 2026-10-31 — API v1 hardening (Phase 17)

- **One error shape, for everything.** `{error: {code, message, details?}}` was already the contract
  for domain exceptions, and only for those: a failed validation came back as Laravel's
  `{message, errors}`, and a 401, 404, 405 or 429 as a bare `{message}`. Three shapes, and which one
  you got depended on which layer refused — the thing a client can least afford to guess at, since
  it only finds out on the error path. `Core\Http\Responses\ApiError` is now the single definition
  and both callers go through it: `DomainException::render()` and the new `ApiExceptionRenderer`,
  registered centrally for everything the framework raises. Scoped to `api/*` **by path, not by
  `expectsJson()`** — Inertia requests also expect JSON and every form in the app reads its error
  bag from the native 422, so widening it would have broken all of them silently.
- **Token scopes that actually bite.** Sanctum has stored abilities on every token since Phase 1 and
  nothing ever checked one, so every token was effectively `*` and "scope this integration to
  read-only" was a promise the API could not keep. `Auth\Enums\TokenAbility` defines the scopes —
  one read/write pair per resource group, coarse on purpose so a person can reason about them and so
  a new endpoint isn't a breaking change for existing tokens — and `EnsureTokenAbility` enforces
  them per route. A refusal is a **403** naming what was missing, never a 401: re-authenticating
  cannot widen a scope, and sending a client round the sign-in loop for it is a loop it never
  leaves. A first-party session caller presents no bearer credential and is let through, because the
  web app it stands in for has no scopes at all — refusing it would make the same member less
  trusted through the API than through a page.
- **A scope picker, because enforcement without one means nothing.** Settings → API tokens now
  offers the catalogue (built from the enum, so a new ability appears in the UI by existing), shows
  each token's scope, and both token requests validate against it: an ability nothing enforces is a
  scope that reads as a restriction and isn't one. Leaving the limit off still issues `*` — the
  permissive default on purpose, since a token that silently came out able to do nothing would fail
  on first use with no clue why.
- **A version policy, written down and enforced.** `X-Api-Version` on every response, errors
  included. The version changes only for a change that would break a client already in the field;
  everything additive ships inside it, which holds only if clients tolerate fields they do not
  recognise — the one thing asked of them in return. A client may pin itself by sending the header,
  and a mismatch is a `400` rather than a best-effort answer from the wrong contract.
- **The dashboard reached the API.** `GET /api/v1/reports/dashboard` — Reporting had no API at all,
  which would have stopped the mobile home screen dead. Same FormRequest, same Service, same figures
  as the web screen, and the whole report rather than a lighter mobile subset: one window, one set of
  numbers, and no second place for the cohort rules to drift.
- **Pagination means one thing.** `per_page` is defined once with one ceiling
  (`PaginatedIndexRequest`, 100). The two genuinely unbounded lists are now paged — discovery runs,
  and the do-not-contact list, which only ever grows because every opt-out stays for as long as the
  promise it records has to hold. The notification list stopped ignoring `per_page`. The handful of
  lists bounded by a business rule still answer with `data` alone, and which are which is written
  down rather than left to be discovered.
- **The contract is generated, not described.** `docs/api/openapi.json`, built from the routes,
  FormRequests and Resources themselves by `composer api:docs` (dedoc/scramble as a dev dependency —
  the document is a build artefact, so production has neither the generator nor the `/docs/api`
  route). `docs/api/README.md` is the prose beside it. A transformer writes in the two things the
  generator cannot see: the error envelope, which lives in middleware rather than in any return
  type, and a relative server URL, so the committed file doesn't carry whichever machine generated
  it.
- **Shared TypeScript the mobile app can import.** The API payload types moved from
  `resources/js/types/` to `resources/js/shared/types/` — moved rather than duplicated by a
  generator, because the hand-written set carries what a specification cannot express (which `label`
  fields are translation keys, which unions are discriminated), and two sets would drift. `@/types`
  re-exports them, so nothing in the web app changed. Beside them, `resources/js/shared/api/` adds
  the envelope and pagination shapes and a small `fetch` client that sends the token, pins the
  version, reads the envelope instead of guessing, and surfaces a 429 as something a caller can wait
  on.
- **The audit is a test, not a document.** `RouteContractTest` asserts over the whole route table
  that every endpoint is versioned, named, behind Sanctum, throttled, version-stamped, scoped with an
  ability the enum defines, handled by a controller rather than a closure, and present in the
  committed specification. A per-endpoint test only covers the endpoints somebody remembered to write
  one for; this is what catches the next route that forgets.

### Fixed — 2026-10-31 — Phase 17 review round

From `laravel-module-reviewer` and `brand-reviewer` at the end of the phase. The full list, with
what was accepted as-is and why, is `docs/api/README.md` §9.

- **An exception carrying its own response became a 500.** `HttpResponseException` is not an
  `HttpExceptionInterface`, so the new renderer took it as unhandled and threw away a deliberate
  answer — an `abort($response)`, a FormRequest overriding `failedValidation`, a vendor package.
  Latent, since nothing in `app/` throws one today; covered by a test now.
- **An unmapped status answered `server_error`.** A 415 or a 419 told the client the fault was ours
  and the request was worth retrying, which is the one conclusion it must not draw.
- **A 5xx message reached the client verbatim**, debug or not. `abort(503, 'redis pool exhausted')`
  is for the logs, not for a prospect's phone.
- **The scope check read the bearer header** and treated its absence as first-party. No live bypass,
  but the fail-open case sat on the common path, and every scope exercised through
  `Sanctum::actingAs()` — which sets a token and sends no header — passed without being checked. It
  now asks the credential, which answers correctly for all three things Sanctum hands over.
- **Listing and revoking the account's other tokens was unscoped**, grouped in with identity. A token
  issued to read leads could enumerate every integration on the account and switch them all off; it
  needs `tokens:manage` now, while identity and a token retiring _itself_ stay unscopable.
- **The scope picker could issue the opposite of what it was asked for.** With the limit on and
  nothing ticked the form posted no `abilities[]`, which the server reads as "no scope asked for" —
  a full-access token. Closed on both sides: the button is disabled in that state, and an explicitly
  empty `abilities` array is a `422`.
- **The per-token rate limit was one anybody could multiply** by minting another token; a per-user
  ceiling sits over it now.
- **The picker's layout and tab order.** `Label` is a flex row, so every scope string sat beside its
  label instead of under it, and the Create button came before the fieldset, so a keyboard user met
  it before seeing the choice. Plus `aria-describedby` on the hint, the app's own `tracking-eyebrow`
  idiom on the group headings, and `dir="ltr"` on every mono scope string — `leads:read` is an
  identifier and must not reorder under Arabic.

### Removed — 2026-10-31

- **`GET /api/user`**, the starter kit's leftover closure. Unversioned, outside the error envelope,
  outside the scopes, and it returned the raw `User` model — the one thing the API rules forbid.
  `GET /api/v1/user` already did the job properly. `routes/api.php` is now deliberately empty: every
  route belongs to the module that owns the domain.

### Fixed — 2026-10-31

- **Error responses carried no `X-Api-Version`.** The middleware sets the header on the way back out
  and an exception never comes back out through it, so the one kind of response a client most needs
  the header on was the one without it. Stamped in `ApiError`, the builder they all share.
- **The `/api/v1` rate limiter keyed on the user, not the token**, despite the comment saying
  otherwise. A member's phone and a long-running integration are different callers; a script's retry
  loop could exhaust the allowance the phone in their pocket was using.
- A stray indentation in `docs/brand/brand-tokens.md` that had `npm run check` failing before this
  phase started.

### Changed — 2026-10-30 — App navigation & the auth screen

- **The sidebar reads as chapters.** `AppSidebar.vue` was two flat groups ("Workspace" 01–10 and
  "Account"); it is now Dashboard on its own line, then **Business** (business profile, background
  check, ideal customers), **Leads** (lead discovery, leads, signals), **Outreach**, **Reporting**
  (insights) and **Settings** (account, plan & billing, compliance). The numbering stays one run,
  01–09, in pipeline order across the chapters rather than restarting in each — the numbers are the
  editorial device, and the marketing drawing in `ProductShowcase.vue` quotes them as the product's
  sequence. **Design system is gone from the nav**; `/design` is still reachable by URL for
  development. The active link now carries `aria-current="page"` and the decorative numbers are
  `aria-hidden`.
- **The auth split screen was recomposed to the reference screen** in `resources/screenshots/`,
  measured against it rather than eyeballed. The statement sits in the open middle of the lime
  panel on two lines; the oversized "alima" sets to the panel's own measure with its baseline on the
  bottom padding line; the lilac peek moved to the bottom end corner of the cream column (one peek
  per layout, and the panel's lower half belongs to the wordmark); and the form column gained a mono
  sign-off on its bottom line. The copy is unchanged. The judgements this forced — a statement size
  that is off the type scale, and the display wordmark still being **typed** rather than lock-up D
  artwork — are recorded in `docs/brand/brand-tokens.md` §7d for the brand owner.

### Added — 2026-10-30 — Billing & Plans (Phase 16)

- **The team pays, not the person.** Laravel Cashier `^16.9`, with its customer columns on `teams`
  and `Cashier::useCustomerModel()` pointing at `Core\Models\Team`. One person may own two teams on
  two plans and two owners may share one subscription; a `stripe_id` on `users` breaks the moment
  either happens (ADR 0006).
- **Five plans, in config rather than in a table.** Free (the fallback a lapsed trial lands on),
  Starter £49 / 1 seat, Team £129 / 3, Scale £379 / 10, Enterprise on request. A plan is a product
  decision that ships with a release, so a price change is a reviewed diff instead of an `UPDATE`
  nobody can find later. **The Stripe products do not exist yet** — every price id is a readable
  placeholder, overridable per environment, and the module is tested against a fake gateway, so
  nothing is blocked until somebody creates them.
- **The three stubs are gone, and nothing that used them changed.** AI Platform's `CreditSource`
  (Phase 5), Lead Sourcing's `LeadQuotaSource` (Phase 7) and a new `Auth\Contracts\SeatLimitSource`
  are now bound to plan-backed implementations. `AiCreditGuard`, `DiscoveryLimitGuard` and every
  caller of them are untouched, which is what those seams were built for — and why the tests go
  through the guards rather than through Billing's own services.
- **Enforcement is at the point of spend, not in middleware.** The phase plan called for an
  `EnforcesPlanLimits` middleware; three of the four places a limit has to bite — a queued discovery
  job, a scheduled signals watch, the nightly insights — have no request behind them. A middleware
  would have guarded the one case that is cheapest to guard and missed the three that actually run
  up a bill.
- **A metered plan keeps working past its allowance, up to a ceiling.** The sources report the
  _ceiling_ rather than the included allowance, because stopping a team at the point we start
  charging is the opposite of what overage means. The ceiling — twice the allowance by default —
  exists because an unbounded metered plan is a way to hand a customer an invoice whose first
  warning is the invoice. A run asked for 200 companies with 40 left fetches 40 rather than failing.
- **`usage_meters` is a record, not a counter.** The authoritative numbers already exist in
  `ai_requests` and `discovery_runs`, and the guards read those directly, so a limit can never be
  wrong because a worker died between doing the work and incrementing a row. The table remembers
  what a closed period cost after the retention sweep has pruned it, and — in `reported` — how much
  overage Stripe has already been told about.
- **Overage is reported exactly once, and it took three things.** Stripe sums the usage events it
  receives, so the reporter sends increments. The claim on `usage_meters.reported` is a
  **conditional** `UPDATE` — an unconditional increment is atomic, but its _amount_ came from a row
  read a moment earlier, so two reporters would both send the same units. The meter event carries a
  **deterministic identifier** derived from `reported`, because Cashier stamps a fresh UUID on every
  call and a request that times out _after_ Stripe recorded it would otherwise be billed twice on
  retry. And an **ambiguous** failure keeps the claim, where only a definite refusal releases it: a
  timeout may mean Stripe has the event and we never heard. So the worst case is under-billing one
  claim, loudly, in the `billing` log — the other direction takes money from somebody who did not
  agree to it.
- **A closed month is sealed.** Re-syncing a settled period would overwrite what it cost with
  whatever the ledgers still hold — less, once retention has pruned them — and overwrite its
  allowance with that of whatever plan the team is on now. One write after the month closes captures
  its final hour; everything after that leaves it alone.
- **Seats are counted inside the transaction, behind a lock on the team row.** Two invitations sent
  in the same moment would otherwise both see the last free seat and both take it.
- **A pending invitation holds a seat, and the seat is checked again on acceptance.** Counting only
  accepted members would let a three-seat team send ten invitations and surface the problem to the
  tenth person, who did not cause it and cannot fix it. An invitation is valid for a week, in which
  the team may have downgraded or filled the seat — so acceptance re-checks, and refuses rather than
  consumes, so the invitation still works once a seat is freed.
- **Allowances run with the calendar month, in UTC.** Not the Stripe billing cycle: anchoring them
  to the invoice date would move the reset day whenever a plan changed and put a round trip to
  Stripe behind every usage query. Overage is reported as it accrues, so it still lands on whatever
  invoice is open.
- **A failed payment does not demote a customer.** Stripe retries a card for a fortnight and then
  cancels, at which point entitlement ends on its own. Cutting a team off on the first failed retry
  turns an expired card into a support incident.
- **Stripe is the source of truth, and webhooks are how it gets in.** A plan changed in the customer
  portal never passes through this app, so a listener on Cashier's `WebhookHandled` re-reads the
  team's entitlements, re-snapshots the meters and writes an audit row with **no actor** and
  `source: stripe_webhook` — because "why did my plan change on the 4th" has to be answerable.
- **`/billing`, in the sidebar's Account group.** Every member can see the plan and the usage bars:
  when discovery stops on a Tuesday afternoon, the person it stopped for should see that the month's
  allowance is gone rather than file a bug. Spending money stays with Owners and Admins. Cards,
  addresses, tax numbers and invoice documents stay at Stripe, reached through its portal — a form
  of ours collecting a card number would put this application in PCI scope to reimplement a screen
  Stripe already maintains. The invoice fetch degrades if Stripe is unreachable, so an Owner looking
  for their usage does not get a payment-provider error instead.
- **`credit_low` finally has a producer.** The notification type has existed unused since Phase 12
  because "running low" needs a usage figure and `AiCreditGuard` only knows exhausted or not. It is
  now sent at the configured thresholds, at most once per team per metric per period per threshold,
  and its copy is metric-aware — "new searches stop" and "scoring and drafting stop" are different
  pieces of news.
- **⚖️/🔑 Outstanding:** the Stripe products and prices must be created and the `STRIPE_PRICE_*`
  keys set before a real payment is possible — the API keys and the webhook secret are already
  configured in test mode. The figures above are provisional, and VAT (UK/UAE) stays deferred. All
  three are listed in `docs/modules/billing-plans.md`.

### Changed — 2026-10-30 — The public pages, and the logo on them

- **The logo was being drawn under its own minimum.** The marketing header, the footer, the sidebar
  and the auth pages all rendered the horizontal lock-up at 32px tall. The lock-up is 3.36:1, so
  that is **107px wide — below the 120px floor** the brand book sets for it (`brand-tokens.md` §4),
  and it is why the mark read smaller on our pages than on the reference screens in
  `resources/screenshots/`. `AppLogo` now has three sizes that are all legal widths, and the default
  (`md`, 40px → 134px) is the size those screens actually show. `lg` (48px → 161px) is §2.3's
  "logo 160px" and is what the marketing footer signs off with.
- **The logo is drawn, not loaded.** `AppLogo` was an `<img>` pointing at one of two pre-coloured
  SVGs, which is why a dark surface needed a second file. The master artwork now lives once in
  `components/brand/artwork.ts` and is rendered inline through `BrandArtwork` with
  `fill="currentColor"`, so a single component serves all eight approved colourways and the eyes
  stay true cut-outs over whatever is behind them. `PandaFace` reads from the same module, so the
  mark and the lock-up can no longer drift apart. The path data is byte-identical to the delivered
  vector source — nothing was redrawn.
- **The landing page leads with the product.** The hero is followed by `ProductShowcase`: a
  miniature of the real workspace — the Deep Teal sidebar with its real numbering, the Midnight Ink
  opening card — walking through four screens, Source, Qualify, Watch and Reach. It is built from
  the same tokens as the real screens rather than being a screenshot, so it cannot drift into a look
  the product does not have. It advances itself every six seconds, pauses while a pointer or the
  keyboard is inside it, stops for good once somebody picks a tab, and does not move at all under
  `prefers-reduced-motion`. The tabs are a real tablist, arrow keys and all, and every screen stays
  in the DOM so the frame never resizes mid-cycle.
- **Both pages say what the product is.** The landing page now covers the pipeline in four steps and
  the system in eight capabilities, states the two markets and the compliance position, and ends on
  a full-width invitation to About rather than a link in the nav. About answers it: why the product
  exists, the pipeline in seven stages, the workspace grouped as Understand / Find / Reach / Learn &
  protect, and the four decisions we would rather state than be asked about.
- **The peeks are now peeks.** Both pages had a panda sitting inside a box with margin around it —
  a square container letterboxes 383:278 artwork, so it never reached the edges. They are sized by
  the artwork's own ratio and cropped by their frame, bleeding one or two edges with both eyes in
  frame, as §5 and `PageHero` have it. One peek per page, as the book says.
- **Arabic ends a question with "؟".** The landing page's "Ready to grow?" had the mark hard-coded
  in the template, so the Arabic page showed a Latin "?". The mark is now its own translation key.
  Full stops stay literal — both scripts use the same one. 142 new strings were added to `en.json`
  and `ar.json` together; the two files keep identical key sets.
- Sections on both pages arrive on scroll through a `Reveal` wrapper, which decides in `setup`
  whether it may animate at all — so a reduced-motion viewer, or a browser without
  `IntersectionObserver`, gets the finished state and never sees content flash and hide.

### Added — 2026-10-30 — Phase 15: compliance the system enforces, not the team remembers

- **No recorded lawful basis, no lead discovery.** A team writes a legitimate-interest assessment —
  purpose, necessity, balancing test, in the regulator's own order — and signs it off with their
  name and the date. Until they do, sourcing is refused. Lead Sourcing already called the gate
  through a contract, so this phase only swapped the permit-everything stub for the real one. Saving
  and signing are two buttons, because a lawful basis that can be affirmed over three empty boxes
  records nothing; and the assessment **expires** after twelve months, because it is a judgement
  about a balance that moves. One per market: the UK runs on legitimate interest and the UAE expects
  prior consent, and one assessment cannot honestly cover both.
- **The consent gate now reads the prospect's own jurisdiction, not just the flag on the lead.**
  This closed a real hole. Signals stamped its converted leads with a prior-consent restriction, but
  a company sourced from a UAE business listing by Lead Sourcing carried **nothing** — so a team
  could cold email a Dubai prospect that UAE PDPL requires prior consent to approach. The guard now
  asks a resolver that takes the strictest of the recorded flag, the lead's country and the team's
  market, and can only ever make the answer stricter. A known UK country never _lifts_ a
  restriction, which is the mistake Signals' own deviation note already warned about.
- **Two retention branches, and the split is the policy.** Every night, a lead **nobody ever
  touched** — no approach, no draft, no note, no task, no owner, still in the first column — is
  deleted. Everything else is **anonymised** once it has gone quiet: the contacts, the message text
  and recipient, the attempt notes, the notes and tasks, the free text in timeline entries and the
  converted news signals all go; the company, its score, its stage and one row per approach with the
  channel, outcome and date all stay. That residue is the team's own evidence of who they contacted
  and when — the accountability the retention rule sits beside, not something to trade for a tidier
  database. The two branches are exact negations of one another, so no lead falls between them and
  is kept for ever while nothing fails.
- **The outreach restriction survives the sweep.** It lives in the provider payload, which
  anonymising empties — and clearing it wholesale would quietly reopen cold email on a lead that
  must never have it. A restriction may be added to a lead but never dropped from one.
- **Signals attached to a swept lead are deleted, not scrubbed.** The foreign key nulls on delete
  and the signals sweep never touches a converted signal, so one left behind would outlive its lead
  with a named individual still on it and nothing would ever remove it.
- **A data-subject request is answered per team, and a name match is a guess.** Searching finds
  contact records, companies named after a person, drafted and sent messages, logged approaches,
  news signals and the do-not-contact entry. An address or a number is exact; a **name** returns
  candidates, and the erasure takes only the rows the member ticked — "John" matches people the
  request was never about, and erasing one of those would be its own data-protection failure. This
  is also the first caller of the Signals exporter built back in S6, closing that module's last open
  dependency.
- **An erasure suppresses the identity, in the same transaction.** The part that is easy to leave
  out and makes the rest pointless: without it the next discovery run re-sources the same company,
  AI re-derives the same address, and the person who asked to be forgotten hears from us again. The
  entry holds a one-way hash and a mask, and cannot be lifted. A do-not-contact entry, by contrast,
  is **reported and never erased** — a subject asking for it gone is asking for something that works
  against them, and the screen says so beside the row.
- **One-click unsubscribe, hosted.** The footer's opt-out was a `mailto:` honoured by somebody
  reading a mailbox and remembering to log it. It is now an HTTPS endpoint with
  `List-Unsubscribe-Post` beside the header, so a mail client's own unsubscribe button works — and a
  recipient who uses it instead of reaching for "report spam" protects every other team's
  deliverability. The link is a signed, expiring URL carrying a message id and nothing else, and
  **the GET deliberately does nothing**: mail clients and security gateways fetch every URL in an
  email, so an endpoint that acted on GET would unsubscribe people who never clicked. The POST
  suppresses the address and the phone number globally, and audits it with no actor, because the
  recipient is not a user of ours.
- **A hosted privacy notice, written for the person who was cold-emailed.** Public, bilingual, and
  in the order they actually care about: who has your details, where they came from, how to make it
  stop. It names Alima as the processor and the business that made contact as the controller, and
  the outreach footer links it automatically when no notice is configured. The public pages drop the
  marketing shell's "Let's talk" pill — a sales call to action beside an opt-out button is both a
  second primary CTA on one view and the wrong thing to say.
- **A team can take its data out**: a zip of twelve CSVs plus a README, formula-neutralised and
  BOM'd so Arabic reads in a spreadsheet, with the do-not-contact list masked because that table
  holds a hash rather than an address. Audited before the file is sent, and metered.
- **Fixed, found by review: `LIKE ... ESCAPE` with a backslash is an unterminated string in MySQL.**
  It passes every SQLite test and fails every production query — exactly the trap this repo's own
  `LeadQueryBuilder` switched to `!` to avoid. The Signals exporter had shipped with it in S6 and
  had no caller until now; both are corrected, and tested with a name containing a wildcard.
- 1369 tests (6090 assertions), Larastan level 7 clean, `npm run check` + `types:check` + `build`
  green. ⚖️ The privacy-notice wording, the retention defaults, whether an erasure's suppression
  should bind every team, DSAR identity verification for non-users, and what evidence could lift a
  UAE prior-consent restriction per lead all still need counsel — they are listed in
  `docs/modules/compliance-privacy.md`.

### Added — 2026-10-30 — Phase 14: insights that say what to do, not what happened

- **Three insights every morning, in the team's own clock.** The companies they picked up and went
  quiet on, the strong matches nobody has approached yet, and the model's read of the week's
  figures. `insights:generate` runs hourly and fires per team when its local clock reaches 07:00,
  so a London team and a Dubai team both get theirs before the working day.
- **A lead goes cold only once the team has started it.** The obvious query — any lead with no
  recent activity — turns one week's sourcing into four hundred alerts a fortnight later, which is
  a backlog rather than a follow-up problem. So it takes an approach, an assignment or a move off
  the default column to put a lead in scope, and a Won or Lost stage takes it back out: silence is
  the correct state for a closed lead.
- **"Worth a call today" asks Outreach whether it may be.** Every candidate goes through the same
  guard a draft and a send go through, and the answer is taken as it comes. A compliance refusal on
  every channel drops the lead; a configuration one (nobody has verified a sender yet) does not,
  because that changes by teatime. A suppressed or prior-consent company stays on the list marked
  **introduction only** rather than being offered an email that would be blocked.
- **The AI is the part that is allowed to fail.** The two SQL insights are built first and stored
  whatever happens next, so a provider outage, an exhausted allowance or an unparseable response
  costs the feed its commentary and not its contents.
- **Figures only reach the model.** No company name, no member name, nothing anybody typed — which
  keeps the prompt a few hundred tokens whatever size the team is, and makes it the one AI call in
  the platform with no untrusted text in it to defend against. The trade is honest and written
  down: the digest can say "your LinkedIn reply rate is three times your email rate" and cannot say
  "chase Acme". Naming a company is the other two insights' job, and they do it from the database.
- **One run a day, and it is the absence of insights that makes that hard.** A day with no stale
  leads, nobody to call and a model with nothing to say writes no insights — so a sweep that asked
  the insights table whether a team had been done would run the aggregates and the AI call again
  every hour until midnight. `insight_runs` records the day whatever it found. On top of that, each
  insight's dedupe key is cut on the team's local date, so a dismissal is never undone by the next
  run, and a forced run skips the model when the day already has a digest.
- **Dismissing is not deleting.** Cleared insights wait under their own tab, audited, until the
  retention sweep removes them — 30 days from the dismissal, 60 from an unactioned generation. They
  are swept harder than anything else in the platform and are the one thing that can afford it: a
  week-old "contact these five today" is misleading rather than merely old.
- **`InsightsReady`**, one notification per run carrying the counts, and a line in the daily
  round-up for what is still waiting.
- EN/AR feed at `/insights` and `/api/v1/insights` for the mobile app. Every figure on a card is a
  label followed by a number, because Arabic's noun takes four forms depending on the count and
  "Quiet for 1 days" is wrong in English too.

### Added — 2026-10-30 — Phase 13: reporting you can filter, read and take away

- **One date filter, scoping the whole screen.** Seven presets and a custom pair, cut in the
  **team's** timezone and never running past now. Server-driven like the leads list, so a shared URL
  reproduces exactly what the sender saw and a download carries the same window.
- **The window picks a cohort, and every chart reports on it.** "Contacted" means _of the prospects
  sourced in this window, how many have we approached_ — not how many approaches we made — so the
  funnel narrows the way a funnel must. Reports that are about activity rather than about leads (AI
  usage, team activity, outreach performance) are scoped by their own event time instead, and each
  says so. A dashboard whose charts disagree is worse than no dashboard.
- **A delta that is actually a comparison.** The previous window is equal in length and butts up
  against where the current one opens, so "this month so far" is measured against the same number of
  days before it rather than against the whole of last month. All time has no window before it, so
  it carries no delta at all instead of a flattering one.
- **The funnel's bottom half is real.** "Contacted" and "Replies" used to come down unavailable with
  an em dash and "arrives with Outreach"; they are counts off `contact_attempts` now. An opt-out
  counts as a reply — it is a reply, and a painful one, and hiding it would flatter the reply rate by
  dropping exactly the responses worth learning from. A bounce does not: nobody read it.
- **Four new reports.** How each **ideal customer profile** is doing at picking companies out
  (retired profiles included and marked, because "the last one was better" is the most useful thing
  that table can say); **outreach performance** by channel with reply rates; **team activity** per
  member, read off the lead timeline; and **AI & data usage** per prompt, with the cache-hit rate
  beside the cost — a falling hit rate is the first sign a bill is about to grow.
- **CSV export of any report.** All eleven of them, through one writer: a report is a table, so a new
  chart cannot ship unexportable. The file is what a spreadsheet wants rather than what a screen
  wants — money in major units, rates as numbers with the unit in the header, dates in ISO-8601 —
  with a UTF-8 BOM so Excel reads Arabic, every cell neutralised so a spreadsheet cannot run a
  company name as a formula, and headers translated into the member's own language. Exports are
  audited (who, which report, which window, how many rows) and the numbers are **not** copied into
  the trail.
- **The trend chart follows the filter.** Bars are days under a short window, weeks under a medium
  one and months under a long one, and the first and last bucket are clamped to the window so the
  bars add up to the same total the funnel reports.
- **An empty cell is not a zero.** A reply rate over no attempts, an average with nothing to average:
  the screen draws an em dash and the CSV leaves the cell blank. `0%` where we meant "nobody was
  asked" is the one lie this whole module is built to avoid.
- Read-only members can filter and export, same as they could always read. An export is the report in
  a different window; gating the file behind a stricter role than the chart would stop the person
  asked to read the numbers taking them to a meeting.

### Fixed — 2026-10-30 — Reporting, after review

- **A Dubai team's dashboard was four hours out.** The window's bounds were built on the team's
  clock and handed straight to the query, and Laravel binds a date by formatting it without
  converting — so team wall-clock time was compared against UTC columns. Every figure shifted at
  both ends, the trend and the funnel disagreed about the same window, and a UTC-only test suite
  could not see any of it. The bounds are converted now, and there is a non-UTC test that fails
  without the conversion.
- **A negative number in a CSV arrived as text.** Formula-neutralisation prefixes an apostrophe to
  anything starting `-`, which is right for a company name and wrong for a KPI delta of `-2`: the
  column the member wanted to sum could not be summed. Only cells that hold words are neutralised
  now.
- **An empty period looked like an empty account.** Filtering to a quiet week showed a team with a
  thousand leads the first-run "here's how Alima works" explainer. An empty window now says so and
  offers a way back to one that has something in it.
- **A year-long window could lose its oldest bar.** A weekly window opening mid-week spans one more
  bucket than its length suggests, and the 53rd was being dropped — putting leads in the funnel that
  the chart never drew. The weekly ceiling allows for the overlap, and a window wholly in the future
  no longer resolves to an inverted one.
- The trend chart's geometry now mirrors properly in Arabic: hover, arrow keys, axis labels and the
  tooltip had been disagreeing with where the line was actually drawn. A monthly bar is labelled as
  a month rather than as the first of it, and arrowing along the chart is announced rather than only
  drawn.
- The report switcher dropped a half-built ARIA tab pattern — announcing "tab 1 of 4" and then not
  behaving like one is worse for a screen-reader user than plain buttons — and the active period in
  the filter is announced, not only ticked.
- `fputcsv` is called with an explicit empty escape in both exporters: PHP's non-standard escaping
  is deprecated as a default from 8.4 and could corrupt a cell ending in a backslash.

### Fixed — 2026-10-30 — Lead discovery looked for the company, not its customers

- **The bug, plainly.** A graphic design studio asked Alima for leads and got back graphic design
  studios. Discovery was searching the active ICP's industry values verbatim, those values were the
  team's **own** trade, and so every run returned the team's competition — companies that will never
  buy from it — presented as its pipeline.
- **One field meant two opposite things.** The AI background check returned a single
  `suggested_industries` list with nothing in the schema saying what it was. The model read it the
  only way the prompt's framing allowed (the industry this business _is in_) while the ICP seeder
  read it as the industries its _customers_ are in, and wrote the same values to both. Prompt **v2**
  names and describes the two sides separately — `own_industry` and `competitors` for the seller,
  `customer_industries`, `target_customers` and `decision_maker_titles` for the buyer — and states
  outright that the business being researched is the seller. v1 stays seeded, so a report that
  recorded `prompt_version: 1` is still traceable to what produced it.
- **A suggested ICP now only ever describes customers.** Customer industries become the industry
  signal, decision-maker titles the job-title signal, and the team's own trade plus any named
  competitor go in as **exclusions**. When the check cannot tell who buys, nothing is seeded and the
  option is disabled: an ICP built from the seller's own trade is worse than no ICP, because
  discovery and scoring both act on it without asking again.
- **Alima now works out who buys from you before it searches.** The new
  `lead_sourcing.shape_query` prompt takes the business profile and inverts it — a design studio
  becomes a search for the businesses that commission design work — and phrases the result for the
  provider that will receive it, since a company register matches registered names, a places API
  matches categories, and a web search reads whole pages. It runs once per source before the fan-out,
  never inside the job, so a retry cannot re-bill it. If the member typed their own terms, those win
  untouched; if the model fails or the team is out of credits, the run falls back to the old
  deterministic search rather than erroring, because a blunt search beats no search.
- **"Never show me competitors" now holds for every source.** Exclusions used to apply only when
  scoring, by which point the company was already in the workspace — enriched with paid calls,
  counted against the allowance, and sitting there to be read. Web search now pushes them to the API
  as negative terms, and every provider's results are filtered **before** anything is persisted.
- **The run screen says what it did.** Each run now states who it looked for and what it left out, so
  a run that returns fewer leads than asked for reads as a deliberate exclusion rather than a
  failure. Costs are still metered on what the provider returned, not on what survived the filter —
  the request was billed either way.
- **Still a judgement call, so it is visible.** An ICP saved before this change can still name the
  team's own trade; the query shaper is told to prefer the business profile where the two disagree,
  but nothing rewrites stored criteria. Exclusion matching is a substring test on company name and
  domain. It is cheap rather than clever: the name is matched on whole words, so excluding "design"
  drops "Apex Design" but keeps "Designer Outlet", while a customer whose name genuinely contains
  the team's trade word will still be dropped. See `docs/modules/lead-sourcing.md` → Known
  limitations.

### Added — 2026-10-30 — Outreach: drafts you can send, and a list you cannot

- **Alima writes the message.** `outreach.draft_message` v2 (reasoning tier) drafts an **email**, a
  **LinkedIn message** or a **call script** from the business profile, the lead's own data and — when
  the team has graded it — the grounded reasons it looked like a fit. Four tones, either language,
  two or three genuinely different angles per call, and the member edits what comes back, which is
  the expected workflow rather than an exception to it.
- **The grounding rules are the substance of the prompt, not a nicety.** An invented mutual
  connection, funding round or metric in a scoring explanation is a bad explanation; in outreach it
  is a bad explanation that gets **sent**. So every block names what is _unknown_ rather than
  omitting it, the model is told plainly what it may not claim, and a model that volunteers a
  variant for a channel nobody asked for has it dropped. A role mailbox is flagged in the prompt
  too, because "Hi Info," is the single most obvious tell of an automated message.
- **Reusable briefs, not mail-merge templates.** A brief is what the team would tell a new
  salesperson before their first call — "lead with their onboarding time, ask for fifteen minutes,
  never promise a discount" — in their own words, handed to the model as context. It is wrapped as
  untrusted content, because a free-text field a member types into is exactly where somebody tries
  "ignore all previous instructions". Owner/Admin only: a brief is not one member's draft, it shapes
  every message the team sends afterwards.
- **Copy is a first-class action, equal to Send.** For a LinkedIn message or a call script it is the
  only one — Alima transmits nothing and says so on the screen — and for a team with no verified
  sender it is how the work still gets done.
- **Email goes out over Resend**, from the platform's verified address named for the team ("Acme Ltd
  via Alima"), with the member as Reply-To. Not the team's own domain: sending as a domain we hold no
  SPF/DKIM for is how a tenant's mail lands in spam, and per-tenant sending domains are post-MVP.
  Every message carries sender identification, an unambiguous opt-out and a `List-Unsubscribe`
  header — a recipient who uses their mail client's own unsubscribe button instead of replying is
  far less likely to reach for "report spam", which protects every other team on the platform.
- **The sent email is deliberately not Alima-branded.** Every other mail the platform sends is Alima
  talking and obeys the brand; this one is the **team** talking to their own prospect. Alima's chrome
  on it would brand a tenant's cold email as Alima marketing — misattributing the sender, which is
  the one thing sender identification exists to prevent — and a decorated HTML cold email is
  markedly worse at reaching an inbox anyway. The body is the member's text and nothing else, and
  Alima appears only in the footer's one identifying line. The Arabic rule still applies, because
  that one is about setting _someone's_ words legibly.

### Added — 2026-10-30 — the gate, and the do-not-contact list

- **`OutreachGuard` is one gate, asked twice.** Once when the draft screen renders, so a member is
  never offered a channel they cannot use, and again **immediately before the message is
  transmitted** — because minutes or days may pass in between and a reply saying "stop" may have
  arrived. The second check is the one that counts; the first is courtesy.
- **The Signals dependency is closed.** A lead carrying `raw.outreach_restriction` — every lead
  converted from a UAE signal, where PDPL requires prior consent and a news article plainly is not
  consent — is refused the email channel **at draft time as well as send time**, read through
  `OutreachRestriction::allowsDirectMarketing()` rather than by comparing cases, so a restriction
  added in a later market cannot be missed by a `match` that still compiles. The permitted channels
  are offered instead, the closed one stays on screen **with its reason beside it** rather than
  being hidden, and the drafting prompt is told to keep its suggested next step to a LinkedIn
  approach or a referral — so a restricted lead is not handed a message whose closing line is "can
  I email you some information".
- **Compliance refusals and configuration refusals are split, on purpose.** A prior-consent
  restriction or an opt-out stops a **draft**: letting a member write a message they can never send
  spends AI credit on nothing and teaches them the warning is negotiable. A missing sender identity
  or an unchosen recipient does **not** stop a draft — a member can still legitimately write the
  message and copy it into their own mailbox, and refusing them would be the platform withholding
  the work over its own setup.
- **The do-not-contact list is a real table, not the stub the plan allowed.** A stub that returns
  "not suppressed" is an opt-out silently ignored. Two scopes: **global**, honoured by every team,
  because a person who asked to be left alone asked everyone; and **team**, which is that team's own
  housekeeping. An email check also tests its **domain**, so "stop contacting anyone at this
  company" means that.
- **Logging "Asked us to stop" suppresses the identity immediately, globally, in the same
  transaction** — not a job, not a listener, not the next sweep. It takes the contact's email **and**
  their phone number: somebody who says "stop contacting me" on a call has not given permission to
  email them instead. A bounce is narrower, because it is a fact about one address and only that
  team knows it. Which of the two — or neither — an outcome demands is the enum's answer, so a new
  outcome has to decide before it can exist.
- **The list stores a SHA-256 hash and a masked preview, and nothing else.** A register of exactly
  the people who want nothing to do with us is not somewhere to keep their addresses in the clear.
  The hash is never exposed over the API and there is deliberately **no lookup endpoint** — one would
  be an enumeration oracle a client could test any address against. A global opt-out is visible to
  every team (it is why their send was refused) and removable by nobody from the product: the model,
  the policy and the service all refuse it, because lifting one would let a tenant overrule a
  person's stated wish with a button.
- **Every approach is logged**, whoever made it — an email Alima sent, a LinkedIn message pasted, a
  call made — with the outcome editable afterwards, because the answer usually arrives days later.
  One `Contacted` timeline entry per attempt, subjected to it, so recording the outcome later
  updates that entry rather than stacking a second "contacted" on top.

### Added — 2026-10-30 — Notifications, including push

- **One place to tell somebody something.** Every module now raises notifications through
  `NotificationDispatcher` rather than sending directly: a producer says what happened and to whom,
  and whether and how it is delivered is settled in one place. `toTeam()` and `toAdmins()` exist
  because the common mistakes are about the audience — notifying a whole team about one member's
  reminder, or a plain member about billing.
- **In-app, email and push**, with a per-type × per-channel grid per team, because the same person
  may run their own side project and work in someone else's team and want very different things from
  each. The bell lives in the app shell as a shared Inertia prop — it is chrome, and a controller
  that forgot to load it would show a member zero unread while they had five — and Reverb raises a
  toast and bumps the count without a page load, on the member's **own** channel rather than the
  team's.
- **The defaults are conservative about email.** In-app costs a member nothing; an email they did not
  ask for costs them their attention and costs us their trust in the next one. So email is on by
  default only where the thing is time-sensitive and they would be cross to have missed it — a
  reminder, an assignment, the AI allowance running out. A **row** in the preferences table means the
  member has an opinion; with no row the type's own defaults apply, so a type added later never
  arrives switched off for everyone who signed up before it existed.
- **New strong matches arrive as one notification, not fifty.** A discovery run can grade fifty leads
  in a minute. `AnnounceTopLeadsJob` is `ShouldBeUnique` per team and runs on a delay, so the burst
  collapses into a single message with a count and a couple of company names — which is the feature,
  because the alternative is a member who switches the whole category off before lunch.
- **Task reminders now actually arrive.** Phase 10b built `remind_at` and a `reminded_at` beside it
  and said plainly that nothing sent them; `notifications:task-reminders` is that sweep.
  `reminded_at` is stamped **before** the notification is raised, because a reminder that arrives
  twice is worse than one stamped and lost: the second is a bug someone can see on the task, the
  first is us crying wolf. A reminder whose window has passed is not skipped — a member whose worker
  was down until Monday still wants Friday's reminder.
- **Push, end to end.** Device registration, FCM **HTTP v1** with a service-account key (the legacy
  `FCM_SERVER_KEY` endpoint was retired by Google in June 2024 and is no longer a usable
  credential), and — the part that keeps push working over time — **deletion of the tokens the
  provider permanently rejects.** A device whose app was uninstalled returns `UNREGISTERED` for ever,
  and a row we keep is a notification that silently goes nowhere, which nobody ever notices because
  nothing fails. Registration is idempotent on the token and re-assigns its owner, since FCM
  re-issues on reinstall and a handset can legitimately change hands.
- **A daily or weekly round-up, on the team's clock.** The member picks an hour and the team picks a
  timezone, so the sweep runs **hourly** and converts before it compares — a single nightly run at
  08:00 UTC would mail Dubai at noon and drift every time the clocks changed in London. Only the
  sections the member asked for go in (a digest that ignored preferences would re-enable everything
  they turned off), and an empty one is **not sent**, because "nothing happened this week" is an
  email that teaches people to filter us.
- **A mute that silences the interruptions and leaves the bell alone.** "Stop buzzing me" is not
  "hide what happened": a member back from a quiet week still has to be able to find out what they
  missed. With an end date, because a mute nobody remembers switching on is a product that looks
  broken.
- **Nothing stored is a finished sentence.** A notification row holds translation keys and params,
  exactly as `lead_activities` does, so a notification written in English reads correctly in Arabic
  three days later. Email and push are rendered once, at send time, under the team's locale.
- **Every markdown email the platform sends is now branded.** `mail.markdown.theme` points at an
  Alima theme — Cloud Cream canvas, warm surface card, Midnight Ink type and buttons, brand radii,
  Poppins ahead of the system stack, and no Signal Orange anywhere, since every colour in a
  transactional email carries a status. Set in config rather than per Mailable, so the invitation
  email and the notification mails cannot drift apart. (The outreach message to a prospect is the
  deliberate exception — see above.)

### Changed — 2026-10-30

- `LeadActivityType` gains **`Contacted`** — one case for all three channels and both routes, because
  from the lead's side an email Alima sent and a call a member made are the same event.
- `SetLeadAssigneesAction` now fires **`LeadAssigned`** (after commit) for the **newly added** members
  only. Somebody already on the lead has not been given anything, and the member who assigned it to
  themselves — the most common assignment in the product — is never notified about their own click.
- `AiPromptTemplateSeeder` adds `outreach.draft_message` **v2**. v1 stays seeded and active so a
  message that recorded `prompt_version: 1` can still be traced back to what wrote it; the registry
  resolves the highest active version.
- The sidebar gains **08 Outreach** (the briefs and the do-not-contact list, which has to be findable
  without a lead in hand); the design system moves to 09. Settings gains **Notifications**.
- Added log channels `outreach` (30 days — "did we contact this person, and when" is a question
  compliance may be asked months later) and `notifications`.

### Fixed — 2026-10-30 — what the reviewers found

Phases 11 and 12 were reviewed by `laravel-module-reviewer` and `brand-reviewer` before being
called done. The five that mattered:

- **A double-click could send the same outreach email twice.** The "already sent?" check read a
  status loaded a moment earlier, so two simultaneous Send requests both passed it and both reached
  the mailer. The row is now **claimed with a conditional update** before the provider is called, and
  the caller that loses the claim is refused — the database decides the winner rather than the order
  two PHP processes happened to read in. A send whose bookkeeping then fails is deliberately
  stranded rather than retried: a missing attempt row is recoverable from the log and the provider's
  dashboard, a second email to a prospect is not.
- **The notification bell's live updates never fired.** `TeamNotification` defined
  `broadcastType()` thinking it named the event; the hook is `broadcastAs()`. So the notification
  broadcast under its framework class name, `Echo.listen('.notification.created')` matched nothing,
  and `broadcastType()`'s return was merged over the payload's own `type` — so the one notification
  that did arrive could not be classified either. Invisible to every other test in the module,
  because the database channel is unaffected; now pinned by its own test.
- **One mail failure skipped everyone after it in the digest and reminder sweeps.** `Mail::send` in
  an unguarded loop: the member it failed for lost that window's digest (already stamped), and so
  did everybody the loop had not reached, who are skipped until the next hourly run — by which time
  their hour has passed. Each item is now isolated and logged.
- **The Arabic digest rendered left-to-right.** Laravel's markdown mail layout has no `dir`
  attribute, so `text-align: start` resolved to `left` even for Arabic, and the recoloured theme
  still had two `text-align: left` rules and a `border-left` left in it. The layout is now published
  with a `dir`-aware `<html>` and a direction-specific rule for the one border Outlook would
  otherwise get wrong.
- **The unread marker was lime-only, and its label was silently dropped.** Acid Lime on cream is
  about 1.3:1 and was the only cue (WCAG 1.4.1 and 1.4.11), and an `aria-label` on a bare `<span>`
  has no role — so screen-reader users never learned a row was unread. Now a weight change on the
  title, a ring on the dot, and a real `sr-only` label. The bell's rows are also proper
  `DropdownMenuItem`s now, so arrow keys work and a notification without a link is a `<button>`
  rather than an unreachable `<div>`.

Also: `List-Unsubscribe-Post` dropped (RFC 8058 one-click needs an HTTPS target, and advertising it
with only a `mailto:` is worse than not advertising it); the sent email's transmitter line raised
from ~2.7:1 to 5.4:1, which is the one line sender-identification law is interested in; the opt-out
sentence rebuilt as one whole-sentence key instead of a link glued to a fragment that forced English
word order; the notification ownership condition consolidated onto `Notification::scopeOwnedBy()`
instead of being copy-pasted into three queries that could each forget a predicate; a
`PushDeviceResource` so the push endpoints obey "API Resources only"; `recordFailure()` wrapped in a
transaction; the outreach briefs screen given its `PageHero` as the sidebar's own destination; one
primary CTA per view on the draft screen; a `border-border`/`border-lime` conflict; the mute date
put through the app's locale and the team's timezone; `rtl:-scale-x-100` instead of
`rtl:rotate-180` on the paper-plane icon, which was pointing backwards; the mail theme's remaining
off-palette values; and 14 new cross-tenant tests across both modules' API surfaces.

Separately, **`composer test` is green again.** The whole suite had outgrown PHP's 128M default and
`artisan test` spawns Pest as a child process that a `-d memory_limit=` on the parent never reaches,
so the limit is now set in `phpunit.xml` where it applies however the suite is run.

### Deferred — 2026-10-30

- **`credit_low` has no producer.** The type, its admin-only gate, its preferences and its copy are
  built and tested, but nothing raises it: `AiCreditGuard` only knows "exhausted or not", and
  "running low" needs the usage figure Billing (Phase 16) will own.
- **Inbound opt-out replies are not processed.** A recipient who replies "unsubscribe" reaches the
  member's inbox and the platform's opt-out address; recording it is a manual step (log the attempt
  with "Asked us to stop"). The footer says to reply, which PECR accepts.
- **One-click unsubscribe is a `mailto:`.** The `List-Unsubscribe-Post` header advertises one-click,
  which needs a hosted endpoint to honour fully — deferred with the hosted prospect privacy notice.
- **Locale is the team's, not the member's,** for email and push. It is the only per-recipient locale
  the platform stores; the in-app channel is unaffected.
- **Compliance (Phase 15) takes ownership of `outreach_suppressions`** and adds the DSAR and
  retention tooling over it.

### Added — 2026-10-30 — a lead you can add yourself

- **Leads can be typed in by hand.** Until now a lead could only arrive from a discovery run, a CSV
  import or a converted signal — so a member back from an event with a business card in their
  pocket had nowhere to put it, and the lead was lost to a spreadsheet or to nothing at all.
  `GET /leads/create` → `POST /leads`, plus `POST /api/v1/leads`, because that member is holding a
  phone rather than sitting at a desk.
- **One required field, and it is the first one on the page.** The company name. Everything else —
  website, number, industry, size, where they are, who they spoke to, tags, stage, a note — is there
  for when they happen to know it, and `EnrichLeadJob` is queued to fill in the rest. A form that
  insisted on a website and a registry number would be a form people give up on halfway through,
  which is the same as not having built it.
- **A company the team already has is filled in, never duplicated — and the member is told.**
  `OwnLeadFields` was **extracted** from `ImportLeadsAction` so that the add-a-lead form and the CSV
  importer share one identity key and one set of clamps. That shared key is what makes "I added
  Bright Bean at the expo" and "I then imported the spreadsheet that already had them" one lead
  rather than two. Only the existing lead's **empty** columns are filled: whatever enrichment has
  since found beats a line typed at a stand. The toast and the timeline entry both say it was a
  merge, because the member is about to land on a lead carrying notes, tags and a stage they didn't
  write, and a silent merge reads as a bug.
- **`LeadSource::Manual` ranks _with_ an import, not above it.** A member who typed a company off a
  business card is the same authority as the spreadsheet they would otherwise have typed it into —
  so a later discovery hit fills in the number it was missing rather than renaming what they typed.
  Equal rather than higher is the deliberate part: on a rank tie the **older** record wins, so
  adding a company by hand can never displace the team's existing record of it.
- **`LeadDeduplicator` is deliberately not called here.** It can collapse records keyed differently
  — a registry number against a domain — but it does so by deleting the loser, and a hand-added lead
  outranks every provider. Calling it on create would let a company typed in at an event win against
  a discovered lead and take its notes and tasks down with it. The exact-key attach covers what a
  member can actually see, and the de-duplicator still gets its say when the new lead is enriched.
- **A hand-added contact may carry an email and a phone number**, unlike a signal's, which is a name
  and a public role because it came off a news article. Someone handed over a card. It is stored
  with `manual` as its provenance and **nothing is marked verified** — no MX check has happened, so
  that is left for the normal verification to judge. A named individual (or any non-role mailbox)
  sets `is_personal` and the lead's `has_personal_data`, which is what the compliance screens and the
  DSAR hook read; `info@` with no name is the company's front door rather than a person, and is
  flagged `is_role_based` instead. The form carries a non-dismissible note that the details must be
  ones they were given or that are already public, and that the team is responsible for them.
- **No `OutreachRestriction` is stamped.** Absent means "no restriction beyond the team's normal
  basis", which is exactly what a company a member met is — and keying a restriction off the lead's
  own country is the mistake Signals' conversion goes out of its way to avoid.
- **`LeadPolicy::create` sits with `work`, not with `import`.** Any member but Read-only. The line is
  one lead at a time versus a team's whole list at once: a business card is ordinary work, thousands
  of rows is a different kind of act. It is its own ability rather than folded into `work` so a team
  who later wants to stop members adding companies has somewhere to say so.
- **`POST /api/v1/leads` answers 201 for a new lead and 200 for a merge**, so a mobile client can
  tell the two apart without re-reading the list. The API controller now shares `ResolvesWorkspace`
  with the rest of the module instead of keeping its own copy of the caller/team resolution.
- **New enum cases, no migration.** `LeadSource::Manual` and `LeadActivityType::Created` (which
  carries `merged`, exactly as `Imported` does, and renders its own line in the timeline).
  `leads.source` and `lead_activities.type` are both `string(32)`, so both values fit what is already
  there. `EmailVerifier::isRoleBased()` became public — it is the one part of that class that reads
  nothing but config, so a caller only classifying an address can ask without triggering a DNS
  lookup, and without keeping a second copy of the role list.
- **Entry points where a member already is:** an "Add a lead" button on the leads hero and in the
  empty state (which now offers both paths — run a discovery, or add one you already know about), and
  a link from the add-a-lead screen across to the CSV import for anyone holding a whole list. The
  country field is a short list from `leads-workspace.countries` with a blank "Not sure yet", rather
  than a 250-entry picker on a phone or a two-letter code a member has to know.
- **41 new EN/AR keys, at parity** (818 each).

**Reviewed** by the `laravel-module-reviewer` and `brand-reviewer` agents. Neither found a
correctness or tenancy defect. Actioned:

- **A `www.` prefix was only stripped in lower case** — `OwnLeadFields::dedupeKey()` lower-cased the
  host _after_ stripping, so a cell reading `WWW.brightbean.co.uk` kept its prefix and keyed as
  `domain:www.brightbean.co.uk`: a second lead for a company the team already had. Found by a new
  unit test on the extracted code, inherited from `ImportLeadsAction`, and now fixed for **both**
  paths. A spreadsheet is exactly where an upper-case `WWW.` gets typed.
- **The orange "?" on the headline was a Latin character**, so the Arabic build read
  "قابلت أحدًا مميزًا?" — wrong punctuation, rendered mirrored. It is now the orange full stop the
  brand actually specifies for a statement (§3), matching the sibling CSV import screen.
- **The submit button is no longer disabled on an empty company name.** A disabled button leaves
  the tab order, so the one member who most needed telling why could not reach it; `company_name` is
  validated on the server, which puts the message beside the field instead.
- **The board is resolved before the transaction opens.** Giving a team its first board is a write
  of its own, and it had no business happening inside the create's lock window — or being re-run on
  every retry.
- **Accessibility and RTL:** every hint is now referenced by its control's `aria-describedby`, not
  just the first; tag chips announce through a live region and hand focus back to the input instead
  of dropping it on `body`; `autofocus` is gone (on a phone it opened the keyboard and scrolled past
  the header); the postcode reads `ltr`, which is what it is in both MVP markets.
- **Arabic copy:** the plural "you" in "Already using:" made singular like the rest of the screen,
  and the masculine assumptions about a contact ("اسمه", "منصبه", "غير متأكد بعد") replaced with
  neutral wording. Still wants a native-speaker review, as the whole AR bundle does.
- **Tests:** a team-isolation case (Team B adding a company Team A already has gets its own lead and
  leaves Team A's untouched), API cross-tenant stage and Read-only 403 cases, a direct Action test
  for the out-of-team stage fallback, a chosen-stage case, a clamping case, and a new
  `OwnLeadFieldsTest` pinning the shared keying contract both ways in now depend on.

Deliberately not actioned, and recorded in the module doc instead: the lost-insert race is handled
but can't be reproduced on SQLite; neither `store` route is throttled, because the team's AI
allowance is what caps the spend; a merge into a lead already carrying 20 tags silently drops the
typed ones (`SyncLeadTagsAction`'s cap, shared with the importer); and `InputError` announces
nothing to a screen reader, which is every form in the app rather than this screen.

### Added — 2026-10-30 — a panda on every hero, and the EN/AR parity the heroes owed

- **Every `PageHero` now carries the peek** — Soft Lilac, scaled up, set into the bottom end corner
  and cropped by the card on two edges at about three quarters of the face, both eyes in frame. The
  same mark, the same colour, the same placement on every screen, matching the reference screens the
  brand owner supplied (`resources/screenshots/Dashboard1.png`, `Clients.png`). Offsets are
  **logical**, so under RTL the peek moves to the other side with the layout while the mark itself
  never mirrors.
- **It does not vary per screen, and that was a correction.** The first build gave each destination
  its own tone and crop; the brand owner's verdict was that it had been moved, recoloured and taken
  off brand, and they were right — the app read as eight different products. §5's "one peek, one
  colour per layout" is about the mark being consistent, not an invitation to reskin it per page. A
  screen is told apart by its number, its statement and its figure. Recorded under §5 in
  `brand-tokens.md`, including that the Lilac face is a third accent beside the statement's Lime and
  the rule's Orange — a call the brand owner has made, not one to "fix" back.
- **The eyes are never redirected.** Varying the pupils was the obvious way to make each screen's
  panda its own, and the brand book forbids it outright — "turn the pupils" is on §4's Never list,
  alongside stretching and rotating.
- **A headline figure on the hero** (`stat`), as in the reference: the one number that matters on
  that screen, set large in Lime against the end edge with a tracked label above and a line beneath.
  Counts are zero-padded to two digits, so a team starting from nothing still gets a figure with
  weight. Wired on the Dashboard (signals waiting), Leads (list size) and Signals (unactioned);
  screens with no such number omit it and the statement runs wider.
- **A hero statement is one translation key again.** Splitting the title into `title` + `accent`
  props had quietly made every headline two keys, which forces Arabic to accept English word order.
  It is now a single key carrying a `|` where the Lime tail starts — `t('Find your next|leads.')` —
  and the component splits the **translated** string, so each language breaks its own line. Several
  of the Arabic statements break in a different place from the English, which is the point.
  `accent` survives for the one case that is data rather than copy: the dashboard's first name.
- **73 + 6 new EN/AR keys, at parity.** The dashboard, the chart set and the heroes had shipped a
  wall of untranslated English into an app that is bilingual by ADR. Everything the Reporting
  module emits is a key rather than a sentence — `BuildHeadlineKpisAction` was building a caption
  with `sprintf`, which would have put English on an Arabic screen whatever the locale, and the
  denominator it was inlining is already on the fit-mix chart.
- **Components take finished strings; pages call `t()`.** `PageHero` had started translating its own
  props, which made it the odd one out and, when I mechanically converted the call sites, silently
  turned several `EmptyState` descriptions into raw English keys. Reverted to the convention.
- **The topbar label is translated too.** Breadcrumb titles are declared as English keys in each
  page's `defineOptions` and were rendered raw, so an Arabic screen had one English word above an
  Arabic hero. `AppSidebarLayout` now runs the last crumb through `t()`, and the 6 crumb titles that
  had no bundle entry got one.
- **Still English, and out of scope:** the public marketing pages, `Welcome.vue` and `About.vue`.
  Their copy has never been in the bundles; translating it is a copywriting job, not a mechanical
  one, and it is the only remaining gap the key audit reports.
- `composer test` green (835), `composer analyse` clean (level 7), `npm run check` / `types:check` /
  `build` green. Verified on screen in both languages: the hero, the peek placement and the chart
  set in LTR and RTL, light and dark.

### Added — 2026-10-30 — a dashboard that reports on real data, and one page hero everywhere

- **New `Reporting` module** (ADR 0002; the home Phase 13 will build out). `dashboard` was a
  `Route::inertia` stub rendering hard-coded zeroes; it is now `DashboardController` →
  `DashboardReportBuilder` → seven focused aggregation Actions over leads, scores, the pipeline,
  signals and AI usage. The module owns no tables — it is a read-model — and the builder is free of
  the controller so the mobile dashboard (Phase 18) and a future `/api/v1/reports` can reuse it.
  Authorisation is `TeamPolicy::viewReports`: any member, Read-only included, because reporting is a
  view of work the team already owns.
- **What the dashboard now shows.** Four KPI tiles with sparklines (leads, with a true 30-day-vs-the-
  30-before delta; strong fits; signals waiting; AI & data spend this month); the prospecting funnel;
  a twelve-week sourced-vs-qualified trend; the A–D fit mix; and breakdowns by pipeline stage, lead
  source and signal event type. A team with no leads still gets the "how Alima works" explainer
  rather than five empty frames — `has_data` decides that server-side, once.
- **The funnel refuses to invent two of its own steps.** Outreach (Phase 11) owns contacts and
  replies, so those steps come down `available: false` with a **null** value and render as an em
  dash, a padlock and "Arrives with Outreach". `0` would read as "nobody replied", which is a
  different claim from "we are not tracking this yet"; a test pins the distinction so a later change
  cannot quietly turn them into zeroes. The two steps that do have data are a **cohort** — "of the
  40 we found that week, 11 were worth calling" — not two unrelated series.
- **Charts hand-built, no dependency.** `StatTile`, `SparkLine`, `TrendChart`, `FunnelChart`,
  `GradeMixBar`, `BarListChart`, all on brand tokens and all to the `dataviz` guidance: one y-axis,
  2px marks, a recessive grid, a legend whenever there are two series, direct labels, and an sr-only
  table as every chart's text equivalent. The trend line has a crosshair + tooltip on hover **and**
  on arrow keys, and mirrors correctly under RTL. Bars are plain HTML — a bar is a box of a given
  width, and `div`s mirror for free; only the line needed SVG.
- **The chart palette is settled** (`brand-tokens.md` §7.7 moves from PENDING to RESOLVED, with the
  measurements). Three findings: the base accents do not work as data marks — Acid Lime is **1.3:1**
  on Cloud Cream and base Lilac reads grey — so `--chart-*` now carries re-stepped variants;
  `chart-1`/`chart-4` are a validated two-series pair (deutan ΔE 23.6) whose **hexes are identical in
  both themes**, so a series never changes identity when the theme flips; and the five brand accents
  **cannot** form a five-way categorical palette (Blue↔Lilac ΔE 2.9 under deuteranopia), so charts
  use at most two series and everything else is single-series magnitude. A grade is a state, not a
  series, so the fit-mix bar reuses `GradeBadge`'s status tokens — colour follows the entity.
- **The dashboard's hero card is now every screen's header.** Extracted to `PageHero`: the Ink panel,
  the numbered mono eyebrow matching the sidebar, the Lime accent, the one Orange rule, the lead
  paragraph and an `actions` slot. Applied to Dashboard, Business profile, Background check, Ideal
  customers, Lead discovery, Leads, Pipeline, Signals, Watch settings (compact) and the Design
  System. A new `hero` Button variant gives the secondary action an outline that works **on Ink**
  (`outline` is a cream chip on a dark panel); the peek stays on the dashboard alone, since the brand
  allows one per layout. The shell's topbar title dropped from `<h1>` to `<p>` — the hero carries the
  page's one level-one heading now.
- **The business profile is part of the app again.** `onboarding/` pages fell through to a dedicated
  chrome-less layout, so the screen a sidebar item points at had no sidebar, no breadcrumb and no
  topbar — it read like a different product. `OnboardingLayout.vue` is **deleted** and those pages
  use the standard shell; everything it provided (locale, appearance, logout, toasts) the shell
  already has, and onboarding is not hard-gated, so there was nothing to protect by hiding the nav.
- **`DemoSeeder` now seeds a quarter of prospecting** — an active ICP, a graded lead list spread back
  over the twelve weeks the trend covers, a populated pipeline, and a Dubai watch with signals for
  the UAE team (ADR 0011). Without rows, a demo tenancy lands on the first-run explainer and none of
  the charts can be seen.
- **A real bug the unit tests caught.** `WeeklyBuckets` annotated its clock as `CarbonImmutable` and
  relied on `AppServiceProvider` having configured the Date facade that way. Against a mutable Carbon
  — which is what a Unit test gets, since the app is not booted — `subWeeks()`/`addWeek()` rewrote the
  same instant on every pass and the twelve buckets came out duplicated and out of order. It now
  converts explicitly, so the chart cannot depend on a global setting being right.
- **Tests (16):** 11 feature (funnel counts, the unavailable steps, weekly bucketing and the
  cohort invariant, all four grades always present, source ordering, stage order, undismissed-only
  signal counts, cross-tenant isolation, Read-only access) and 5 unit (timezone week cutting, the
  Dubai-vs-London cut at the same instant, window bounds, the generated SQL and its bindings, row
  reading). `composer test` green (835), `composer analyse` clean (level 7), `npm run check` /
  `types:check` / `build` green.

**Phase 13 is not done** — it is 🟡 in `mvp.md`. Date-range filters, CSV export, ICP/source performance
and team-activity reports, fuller AI usage, and the Outreach halves of the funnel all remain.

### Fixed — 2026-10-30 — a signal with a long article URL couldn't be converted

- **The crash.** `signals.source_url` has been 2048 wide since S3, but `leads.source_url` and `lead_contacts.source_url` were Laravel's default **255**. Serper answers some articles with a Google redirect rather than the publisher's own link — `https://google.com/goto?url=<opaque token>`, about 280 characters — and `ConvertSignalToLeadAction::addContact()` wrote `$signal->source_url` **raw**, bypassing the clamp the lead's own value went through. Result: `SQLSTATE[22001] Data too long for column 'source_url'`, so the signals a member most wanted were the ones that could not be converted.
- **The quieter half, which mattered more.** `SourcedLead::safeUrl()` clamped every URL to 255 to fit the column, so the _lead_ didn't throw — it stored a **truncated URL**, and the origin panel rendered it as a working link to the article. Nothing reported it. That path is shared with Lead discovery, so it was never signals-only.
- **Fixed by making the columns fit the data and the clamp fit the column.** Both `source_url` columns are now 2048, matching `signals` (neither is indexed, so the width costs nothing); the migration's rollback **refuses** rather than cutting URLs into broken links. `safeUrl()` takes the destination column's width, so `website` — a homepage, in a 255 column — keeps its own limit, because a clamp wider than the column simply moves the crash. And the contact plus every `field_sources` entry now read **one** `storedSourceUrl()`: the value as the lead actually stored it, so all three citations of an article agree instead of two agreeing and one throwing.
- **Tests (4):** the long URL kept whole on both the lead and its contact; a URL past 2048 clamped rather than thrown; `website` still held to 255; a non-http URL still dropped however long it is.

**Still open, and worth a decision — the redirect itself.** The Google token is Google's own encoding, so the publisher URL **cannot be recovered locally**; there is nothing to unwrap. Two consequences are real and unaddressed: `url_hash` hashes the token rather than the article, so if Google varies it between requests the same article re-enters as a new signal every run (a fresh Serper query and AI batch each time — exactly the spend the hash exists to prevent); and the per-team ignore list compares hosts, so every redirected item reads as `google.com` and a team can never exclude a publisher. Candidate fix, not applied: hash `source_name` + the normalised headline when the host is a known redirector, and match the ignore list against `source_name` too. Recorded in `docs/modules/signals.md` under "Known limitations".

### Fixed — 2026-10-30 — "Run now" on a signal watch shows its work

- **The live progress was unroutable, so the card showed nothing.** `SignalRunProgressed` has carried a stage and a percent since S3 (`searching → analysing → saving → done`), but it identified itself only by `run_id` — and "Run now" dispatches a job and redirects **before** the run row exists, so the screen was waiting on an id it could not know. Every stage arrived, matched no card, and was discarded; the only visible change came minutes later when the run finished. The event now carries **`watch_id`**, and the screen keys progress by the watch — a watch has at most one live run (`ShouldBeUnique`), so it is an unambiguous key.
- **The run row is opened by the press, not by the worker.** `TriggerSignalWatchAction` now opens a `queued` run inside a transaction and dispatches **`afterCommit`**, so the redirect lands on a card that can already say what is happening. `RunSignalWatchAction::claimOrCreateRun()` adopts that row rather than opening a second, so a press still means one run; a run still waiting for a worker is reused rather than stacked on; and a **refused** press — paused watch, 15-minute throttle — leaves nothing behind, because a stray queued row would show a card working on something no worker will ever run. It also means _queued_ stays honest when the queue is stopped or backed up, instead of a spinner that will never resolve.
- **What a member now sees:** the button answers the click itself (spinner, "Running") before the round trip finishes; the card grows a stage line, a percent and a bar; and a single page-level `role="status"` region announces each stage **with the watch's name**, since any of the cards could be the one that moved — not a live region per card, which would talk over itself.
- **`ui/progress` extracted.** `lead-sourcing/Show.vue` and `business-intel/Review.vue` had already hand-rolled this bar identically, and the watch card would have been a third copy; both pages now use the component. Determinate only, on purpose: everything that uses it has a percent to report, and a watch waiting for a worker says so in words.
- The 4 stage strings were **translated at last** (11 new EN/AR keys at parity) — they had existed since S3 but nothing had ever displayed them, so nothing had needed them.
- **`brand-reviewer`: no blockers.** Its three should-fix items are done: the live region's text is a translated key rather than `${name}: ${step}` (a hardcoded colon doesn't reorder under RTL); the press itself is now announced, so the queued stretch before the first broadcast isn't silent; and the bar is `aria-describedby` the stage line. The `Spinner` inside the button and the badge became `aria-hidden` — it hardcodes `role="status"` and an untranslated `aria-label="Loading"`, which was joining the button's accessible name and forming one live region per running card, against the single page-level one. It confirmed **`brand`, not `info`, for the fill** — a bar is a neutral indicator, and `info` is still PENDING approval (`brand-tokens.md` §7.6), so a base component shouldn't depend on it. Also from the review: the progress block lost its `border-t` (the card was stacking rules into a ladder), the "takes a minute or two" line now shows only until the worker reports in, the Arabic uses the term the existing "Signals found" key already uses, and `Progress` guards a non-finite value instead of rendering `aria-valuenow="NaN"`. Two app-wide follow-ups it surfaced are recorded in `signals.md`: the `Spinner` base component should take a translated label, and Discovery and Business Intel render their stage text untranslated.
- **Tests (7, all green):** the queued row and its reuse; nothing left behind by a paused or throttled press; every broadcast carrying its watch id; the worker adopting the queued row instead of opening a second; and the queued run reaching the settings screen's Inertia props. `composer test` green (817), `composer analyse` clean (level 7), `npm run check` / `types:check` / `build` green.

### Added — 2026-10-29 — Signals S6: API v1, retention, compliance hardening & the demo runbook

- **API parity.** 11 `/api/v1` routes behind Sanctum — list/show a signal, convert, dismiss, restore, and full watch CRUD plus "run now" — with **the same Actions behind web and API**, so there is one definition of what converting or dismissing a signal means. `run` answers **202 Accepted** and returns immediately (the work is a Serper search plus an AI pass; results arrive over Reverb or on the next `GET`), and domain errors come back as the standard JSON envelope with their status: 409 for an already-converted signal, 422 at the watch ceiling, 429 when throttled. The web endpoints get the same errors as a redirect and a toast instead.
- **Retention that the UI can be held to.** `signals:prune` runs nightly: dismissed signals after 90 days, unactioned ones after 180, **converted ones never** — they are the provenance of a real lead. Chunked at 500 rows, idempotent, sweeping every team (`withoutGlobalScopes()`, which is why it is console-only), logging counts and windows and nothing else, with `--dry-run` because retention is irreversible. Dated on `created_at`, not `published_at`: the promise is about how long _we_ have held the record. The windows live in a new **`SignalRetention`** service that the feed's **"Deletes in N days"** label reads too — so the promise on screen and the rule that deletes the row are one rule rather than two copies of it, which answers this phase's own risk note about retention removing something a team relied on.
- **Data-subject requests.** `SignalsPersonalDataExporter` exports and erases by `subject_person_name` within one team, ready for Phase 15 to call. There is no email or phone to report, because the module stores neither. **Converted signals are reported but not erased:** removing the origin of a lead someone is actively working would leave the team unable to say where it came from, which is the opposite of the accountability the request is exercising — so `erase()` returns `retained_signal_ids` and the decision stays explicit. Matching is by name and therefore imprecise, so results are candidates for a human, never an automatic erasure.
- **Per-team ignored sources.** `signal_watches.ignore_domains` — edited one host per line, normalised by the query builder (a team types what they see: a pasted URL, a `www.` prefix, mixed case, a Unicode domain) and **carried on the `SignalQuery`** rather than read from config by the client, so the client holds no notion of which team asked and can't apply one team's list to another's results. Merged with the global list.
- **Audit and observability.** Watch create/update/delete now write audit entries (ids, enum values and counts — never a team's competitor list), and a failed run leaves a Sentry breadcrumb carrying the team and watch ids. A read-only admin view of `signal_runs` is **deferred to Phase 19**, as the phase allowed: there is no Filament panel yet, and run cost and failures are already visible per watch.
- **`docs/runbooks/signals-demo.md`** — how to demo this to the Dubai client: what to set up the day before, what a good week actually looks like (5–20 signals, pennies a day), the order to show it in, the three sentences to use about compliance, what to do when it fails live, and what not to promise.
- **Post-review fixes — `laravel-module-reviewer`, two blockers, both now pinned by tests:**
    1. **Deleting a watch cascaded to converted signals**, silently destroying the origin of leads the team was still working — contradicting what `compliance.md` §5a, `signals:prune` and the module's own docblocks all promise. Both FKs became `nullOnDelete` with nullable columns, so the **database** protects provenance however a watch comes to be deleted, and the action now removes only the un-converted signals.
    2. **`signals:prune` read ids and then deleted by id alone**, so a signal a member converted between the read and the delete was removed anyway, leaving a brand-new lead pointing at nothing. The delete re-applies its own status and age guards, which is where the "converted is never deleted" guarantee actually needs to live.
- **Also from that review:** a `max_watches_per_team` ceiling (the per-watch run throttle does nothing about a team that creates two hundred watches, and every active one spends money on its cadence); a cheap `CountSignalWatchesAction` for the feed, which was loading every watch with its run history to get a number; and the lead origin panel's link pointed at a `?signal=` filter the feed doesn't have, so it would have landed on the New tab showing everything except the signal it promised.
- **`security-review` — no blockers, two real bugs in the ignore list this phase had just added:** a tab or newline **inside** a host made `parse_url` return `bayut_.com`, and a trailing-dot FQDN (`bayut.com.`) matched nothing — either one walked straight past an exclusion a team had deliberately set, and handed us a fresh `url_hash` for the same article on every run (a Serper query and an AI batch each time). Hosts now go through one strict normaliser used by both the dedupe hash and the exclusion check. It also prompted: storing the **canonical** URL so userinfo can't dress up a link (`https://gulfnews.com@evil.ae/…` renders as the publisher a member trusts); rejecting over-long links **before** parsing them; an explicit `ESCAPE` clause on the DSAR `LIKE` (MySQL defaults to backslash, SQLite has no default — so the tests had been validating behaviour that differs from production); a ban on bare URLs and messaging handles in the suggested-approach line, which a salesperson reads and an article controls; and `maxItems`/`maxLength` on the AI response schema so the schema is the guard rather than the downstream clamps.
- **Tests (51 new, all green):** API parity across both roles and both tenancies including the 202/409/422/429 shapes; the prune windows, idempotency, chunking, every-team sweep and the "never a converted signal" guarantee; the retention countdown; DSAR export/erase, the wildcard escape and the retain-converted behaviour; the per-team ignore list end to end; and one test per review finding — the watch-deletion cascade, the prune race (simulated with `DB::listen`), the watch ceiling on web and API, the host tricks, and the approach-line sanitiser. `composer test` green (810 total), `composer analyse` clean (level 7), `npm run check` / `types:check` / `build` green.

### Added — 2026-10-29 — Signals S5: the feed & watch settings (EN/AR, RTL)

- **A feed the client can be shown.** `/signals`: New / Converted / Dismissed tabs with counts, collapsible filters (event type, date range, minimum relevance), and a card per signal — the event type, the headline, the AI summary, who and how much, the relevance score, the suggested approach, and the source link. **Server-driven**, like the leads list: the tab, the filters and the page all live in the query string, so the screen never holds more than a page and a shared URL reproduces exactly what the sender saw. Ordered by `published_at`, because the feed is about **when the event happened**, not when Alima found it.
- **`SignalFilters`** is the one shape the request, the query and the Inertia props all speak. Unknown values are **dropped, not rejected** — a link someone shared last month still opens the feed, minus the filter that no longer exists — and a date range typed backwards is swapped rather than 422'd, because "the 9th to the 1st" plainly means the 1st to the 9th and an empty feed explains nothing.
- **Three distinct empty states**, because they have three different fixes: no watches at all ("set up your first Dubai watch"), filters that match nothing ("try widening the dates"), and a genuinely quiet week.
- **Watch settings** at `/signals/watches`: a card per watch with its event types, cadence, last and next check, signals found and spend; recent run history; create/edit; a quick pause/resume; "Run now" with **live progress over Reverb**; and a removal confirmation that says plainly that the signals go too. A team with none gets a **one-click "Watch Dubai for me"** — deliberately the broadest useful watch, because a first watch that finds nothing teaches the team the feature doesn't work.
- **Reactivating a paused watch doesn't make it due.** `last_run_at` is left alone, so a watch paused for a month doesn't fire the moment it is switched back on — that is a surprise bill, not a feature. "Run now" exists for when that _is_ what the member wants, and is throttled to once per 15 minutes per watch plus a per-member cap.
- **Thin controllers behind `ResolvesSignals`**, which resolves every signal and watch inside `TeamContext` — so a cross-tenant id is a **404 before any policy runs**, and the policy still double-checks (ADR 0006). An out-of-team pipeline stage or ICP is refused at the FormRequest _and_ dropped in the Action.
- **94 EN/AR strings at parity**, RTL throughout (logical properties, `dir="auto"` on data, mirrored directional icons), and a **Signals** entry in the sidebar.
- **Post-review fixes (`brand-reviewer`) — its three blockers:** the tabs are now **links, not `role="tab"`** (each one navigates, so it is shareable and bookmarkable, and a `tablist` may not contain the Filters button that was sitting inside it); a selected filter chip carries a **tick, not just a tint**, because colour alone was the only thing conveying selection (WCAG 1.4.1); and focus now moves to the list — with an always-mounted live region announcing the outcome — when converting or dismissing unmounts the button that was focused, which had been dropping focus on `<body>`.
- **Also from that review:** the card was **trimmed**, which was the plan's own flagged risk — the Converted/Dismissed badge went (the tab already says it), "found by" went (it is plumbing, and belongs on the watch), relevance moved up beside the event chip and the date, and the suggested approach lost its tinted panel and icon circle for a thin brand rule. Counts are phrased **count-neutrally** so the Arabic needs no dual or plural forms; a queued run reads `outline` rather than `warning` (waiting is not a problem); the Play icon mirrors under RTL; the empty states use `Satellite` rather than Lead discovery's `Radar`; "relevance floor" became "minimum score"; and the consent notice now says _why_ rather than only what.
- **Two base components were extracted** rather than copying `Input`'s class string a fifth time: `ui/native-select/NativeSelect.vue` and `ui/textarea/Textarea.vue`. Native elements on purpose — the reka-ui `Select` is a listbox of divs, which loses the native mobile picker and the form post. ~15 older call sites across the app still hand-roll these and silently drop `Input`'s disabled and invalid styling; that is recorded in `signals.md` as an app-wide follow-up.
- **Tests (32 new):** every route for auth, validation, tenancy and the happy path; the Inertia props shape (including that no contact field and no `raw` reach the client, and that money is minor units plus a code); filters, the drop-the-unknown-filter behaviour and the ordering; the watch lifecycle, the cap-free edit, the "don't make a reactivated watch due" rule, the delete cascade and its audit counts; the run-now throttle and the paused-watch refusal; and read-only and cross-tenant refusals on all of it.

### Added — 2026-10-29 — Signals S4: convert a signal into a standard Lead

- **A signal becomes an ordinary lead, and then stops being a signal's problem.** `ConvertSignalToLeadAction` runs in **one transaction** (`attempts: 3`): lock the signal, build the lead through the same `SourcedLead` DTO discovery uses (so it is keyed, clamped and URL-filtered identically), attach-or-create against the team's existing rows, add the person as a contact, record the timeline and audit entries, mark the signal converted, and — **only after commit** — queue `EnrichLeadJob` and fire `SignalConverted`. From there the lead flows through the existing Enrichment → Scoring → Workspace pipeline, which knows nothing about where it came from. Serper and the AI are not involved at all.
- **Clicking convert twice gives you the same lead.** The signal row is locked for the whole transaction and an already-Converted signal **returns the lead it made** rather than erroring — a double-click, a retried request and an impatient member all land on the same place. The one case that can't be made idempotent is a converted signal whose lead has since been deleted (the FK nulls on delete): there is nothing to hand back and nothing to re-create, so the member is told plainly instead of being handed a second lead for a signal that already reads as done.
- **A company the team already has is filled in, never duplicated.** The lead is matched on `dedupe_key` first (the unique index the CSV importer matches on), then run through `Enrichment\Services\LeadDeduplicator` to catch the same company already on file under a _different_ key. `LeadSource::Signal` **ranks at the bottom**, so a merge may only _fill_ the survivor's gaps: an article's spelling of a company never renames the registry record or the team's own import. Each gap-filled column is stamped into `field_sources` (ADR 0008) naming `signal` / `news_extraction`, which the CSV importer still doesn't do.
- **No contact details are invented.** A signal holds a name and a public role and nothing else, so the `LeadContact` is written with `email` and `phone` **null by construction**, flagged `is_personal`, and attributed to the signal. A second signal about the same founder doesn't stack up a second contact (matched case-insensitively).
- **The UAE outreach restriction is keyed on the watch's market, not the company's country.** `Market::outreachRestriction()` (Core) returns `UaePriorConsent` for the UAE and null for the UK, and the value is stored as `leads.raw.outreach_restriction` behind the new `Core\Enums\OutreachRestriction`. That distinction was a **review fix**: an earlier cut read the country off the convert form, which silently dropped the restriction the moment a Dubai founder's lead was filed under a UK holding company — exactly the case it exists for. It is also **re-asserted after de-duplication**, because `raw` is one of the columns the merge fills only when empty, so a lead that already had a `raw` payload would otherwise have lost it. A compliance limit may be added to a lead but never dropped from one. `LeadResource` now sends it on **every** lead (not just the detail screen) so Outreach and mobile can't miss it, and `docs/modules/outreach.md` records exactly what Phase 11 must do with it.
- **Dismiss and restore, with reasons kept for tuning.** `DismissSignalAction` takes an optional `SignalDismissReason` (`NotRelevant`, `AlreadyKnown`, `NotAnEvent`) into a new `signals.dismiss_reason` column; `RestoreSignalAction` takes Dismissed → New and clears the reason **and** the actioned-by/at stamps. Both are transactional, audited and idempotent, and neither works on a converted signal — the lead is real and in someone's pipeline. The reasons are the only honest evidence for tuning the query templates and the relevance floor: "not an event" says the classifier is too loose, "not relevant" says the queries are.
- **The lead detail screen shows the event it came from — without the Workspace depending on Signals.** The Leads Workspace defines a `LeadOriginProvider` contract (+ a `LeadOrigin` DTO and a `NoLeadOrigin` default binding) and Signals binds `SignalLeadOriginProvider` over it, so the dependency points one way only and a later origin kind needs no change to the screen. The provider keys on **`raw.signal_id` alone, not the lead's `source`** (a second review fix): a signal attached to a company the team already had leaves that lead's stronger attribution intact, so a source check would have hidden the event on exactly the leads where it is most interesting. The screen gained a read-only **The event** panel (event type, the quoted headline, publisher, date, a link out to the article and in to the feed) and a **Get consent first** note beside the existing personal-data one — `warning`, never Signal Orange, which the brand reserves as decorative.
- **One timeline case, not stored prose.** `LeadActivityType::SignalConverted` carries `signal_id`/`event_type`/`headline`/`attached` as values; "Created from the signal …" and "Linked to the signal …" are composed at render time so the entry can be English or Arabic (ADR 0007). 15 new EN/AR strings, at parity.
- **Post-review hardening (module reviewer + brand reviewer):** the conversion transaction now retries (`attempts: 3`), because this action pre-locks a row that `LeadDeduplicator` then locks in id order — so a concurrent enrichment deduping the same companies could deadlock with it; the lost-insert race (two members converting signals about the same new company at once) is retried **outside** the transaction, since by then it is doomed and a failed statement poisons everything after it on some drivers — the same trap `ImportLeadsAction` avoids the same way; the first signal keeps the `raw` origin pointer, so a second conversion onto the same lead can't leave the panel describing one event while the timeline says another; the caller's signal model is refreshed so an S5 controller isn't holding a stale `New`; and the restriction note's icon moved from a handshake (which the brand is wary of, and which reads backwards next to "don't cold-email them") to a shield, matching the panel beside it.
- **Also fixed in passing:** the discovery picker listed _every_ `LeadSource` case, so `import` already rendered as a permanently greyed-out card nobody can ever select, and `signal` would have added a second. It now lists the providers only.
- **Tests (48 new, all green):** the created lead and contact shape (no email, no phone), idempotent re-conversion, attach-by-key and merge-by-different-key, the restriction surviving on a GB-registered lead and on a merge, no restriction from a UK-market watch, first-signal-keeps-the-pointer, `field_sources` provenance, the lost-insert-race fallback, enrichment queued only after commit and **not** re-queued for an already-enriched company, a company-less relocation signal refused, an out-of-team pipeline stage dropped and logged, cross-tenant attach and a tampered `raw.signal_id`, the origin panel on both a created and an attached lead, dismiss/restore and their refusals, and the enum coverage for `LeadSource::Signal`, `SignalDismissReason`, `OutreachRestriction` and `Market::outreachRestriction()`. `composer test` green (724 total), `composer analyse` clean (level 7), `npm run types:check` + `npm run build` green.
- **Not in S4 (as planned):** the convert/dismiss/restore **endpoints and screens** are S5 and the API is S6. S4 is the Actions, the data model and the lead-side surfaces.

### Added — 2026-10-29 — Signals S3: AI event extraction & the scheduled watch run

- **A scheduled, idempotent run turns Dubai news into signals.** `RunSignalWatchAction` orchestrates one watch: open/claim a run (Running), build queries from the watch + active ICP (`SignalQueryBuilder`), search Serper, **drop articles the team has already seen** (so a re-run does no AI work), batch the rest (≤10) through the AI, keep genuine in-threshold events, and persist the kept signals + the run totals (queries/results/created, Serper + AI `cost_micros`) in **one transaction** with `insertOrIgnore` on `(team_id, url_hash)` — so a re-run creates nothing new and never overwrites an existing (possibly converted) signal. Serper and the AI are never called inside a transaction.
- **`signals.extract_event` prompt (cheap tier)**, seeded in the registry and documented in `docs/ai-prompts.md`. It classifies each item from title + snippet only, **geo-qualifies** to the watched location (keyword hits that merely mention the UAE score low / aren't events), treats every article as **data not instructions** (each wrapped in `UntrustedContent`), and the suggested next step is always a LinkedIn or referral introduction — never cold email (UAE PDPL, ADR 0007). Output is schema-validated per item; a batch that can't be made valid is retried once then skipped (logged) without failing the run. `ExtractedSignal` maps the output, converting the amount to integer minor units and resolving the currency, and keeps only genuine, typed events clearing the confidence (≥60) and relevance (≥40) floors.
- **`RunSignalWatchJob`** — `ShouldBeUnique` per watch (a scheduled run and a "run now" can't execute the same watch at once), `tries=3`, `backoff=[30,120,300]`, `timeout=120`; runs inside `TeamContext` with `Log::withContext(['team_id','signal_watch_id'])`; `failed()` marks the latest non-terminal run Failed. A transient Serper failure bubbles so the queue retries; a misconfiguration or **AI-credit exhaustion** (via `AiCreditGuard`) fails the run cleanly instead of half-running.
- **Scheduling & run-now.** `signals:dispatch-due` (hourly, `withoutOverlapping`) queues a run for each active watch whose cadence is due (Daily >24h, Weekly >7d, or never run) — the project's first scheduled command, wired in the module provider. `TriggerSignalWatchAction` is "run now", rate-limited to once per 15 minutes per watch.
- **Live progress over Reverb.** `SignalRunProgressed` (searching → analysing → saving → done) and `SignalsDetected` (fired after commit with the new-signal count) broadcast on the team's private `teams.{id}` channel, mirroring `DiscoveryRunProgressed`.
- **Post-review hardening (module reviewer):** a failed run now records the spend already incurred (Serper + any AI batches) and **advances `last_run_at`**, so a persistently failing watch (e.g. a team out of AI credit) backs off to its cadence instead of being re-dispatched every hour; the outreach rule is enforced **in code** (`suggested_approach` that drifts toward email/phone/cold-contact is replaced with a safe LinkedIn/referral default) and a leaked email is stripped from stored text (data minimisation); the dedupe/claim/ICP queries are explicitly `team_id`-scoped (defence in depth beside the global scope); the job `timeout` is 85s (below the 90s queue `retry_after`, matching the other AI jobs); duplicate per-article AI results keep the highest confidence; and `fail()` logs to the `signals` channel (the job logs the exception class, not its message). Two cost trade-offs are accepted for the MVP and documented in `signals.md`: a retry re-pays earlier AI batches, and rejected/non-event articles aren't yet remembered.
- **Tests (34 new, all green), AI and Serper faked end-to-end:** happy path (right signals, counts, cost, `last_run_at`, `SignalsDetected`), idempotent re-run (0 new, no AI call), below-threshold drops (+ no `SignalsDetected`), prompt-injection treated as data, schema-invalid retried-then-skipped, non-retryable vs retryable provider failure, AI-credit exhaustion fails cleanly **and still meters the spend + backs off**, the scheduler picking only due active watches, run-now throttling, cross-tenant isolation, job uniqueness + `failed()`, and `ExtractedSignal` mapping incl. the compliance/minimisation/overflow guards. `composer test` green (675 total), `composer analyse` clean (level 7).

### Added — 2026-10-29 — Signals S2: Serper News client & ICP-driven query builder

- **`config/signals.php`** holds the whole feature's knobs. Serper is **reused from Lead Sourcing (ADR 0010) by referencing the same env keys** (`WEB_SEARCH_API_KEY` / `WEB_SEARCH_BASE_URL`) — one Serper account for the app — but Signals keeps its **own** HTTP timeouts, retry, circuit-breaker, cost and result-count settings so the two features tune independently. The **query templates are data**: the loosened plain-keyword UAE templates from the S0 spike (no exact-phrase quoting, no `-based`, which returned 0 results on `/news` + `qdr:w`), editable without code. Every key is documented in `.env.example`.
- **`SignalQueryBuilder`** turns a watch (+ the team's active ICP) into Serper queries, **deterministically, no AI**: the watch's `location` overrides the ICP geography, its `event_types` pick the templates, and an ICP Buying-signal criterion is **appended as an extra query only when it maps to a watched event type** (keyword match, e.g. "raised funding" → FundingRound) — otherwise it's ignored and the reason logged (IDs/counts only). Identical queries are de-duplicated.
- **`SerperNewsClient`** (behind a `NewsSearchClient` contract) calls `POST /news` with `q`/`gl`/`hl`/`tbs`/`num`, maps the `news[]` array to clamped `NewsItem` DTOs, parses Serper's best-effort relative dates ("3 days ago") to UTC, **rejects non-http(s) links, drops `ignore_domains`** (property portals + aggregators) and collapses duplicate articles within a response. It reuses the Lead Sourcing hardening pattern — timeouts, retry on **429/5xx only**, a `CircuitBreaker` (keyed `signals_news`) that trips only on transient failures, and fail-fast on a missing key — and raises a `SignalsException` (retryable for transient failures, not for config errors) rather than returning partial garbage.
- **`SignalItemNormaliser`** derives `signals.url_hash` as the sha1 of a canonical URL (lower-cased scheme+host, `www.`/trailing-slash/`utm_*`/fragment stripped, query sorted), so the same article dedupes across tracking parameters and across runs.
- **`FakeNewsSearchClient`** (`app/Modules/Signals/Testing/`) with canned Dubai fixtures, for the S3 pipeline tests.
- **`CircuitBreaker` moved to `Core\Support`** (from Lead Sourcing) and given a `prefix`, so Signals' breaker state (`signals:cb:*`) no longer shares Lead Sourcing's cache namespace (`lead_sourcing:cb:*`). Lead Sourcing is bound with its existing prefix, so its behaviour is unchanged; both modules now consume one Core contract rather than Signals borrowing a `Support` class.
- **Post-review hardening:** every value stored on a `NewsItem` — including the `raw` payload persisted to `signals.raw` in S3 — is length-clamped; links over the 2048-char `source_url` column are rejected; and a 200 response whose body isn't the expected JSON shape (an HTML error/auth page behind a 200) raises `SignalsException` rather than being read as "no results" (a valid object with no `news` key is still empty results).
- **Tests (36 new, all green):** query-builder table tests (location override, default-location fallback, event-type selection, buying-signal mapping incl. unmatched + unwatched), the Serper client (payload mapping, request shape, ignore-domains incl. subdomain vs look-alike, non-http rejection, in-response dedupe, retry-on-429, 4xx not retried, transient failure → retryable exception, circuit opens after repeated failure, 4xx doesn't trip it, missing key fails fast and sends nothing, unsupported driver, unparseable date, text clamping + over-long link, malformed/empty 200 bodies — with `Http::preventStrayRequests()`), URL canonicalisation, and the `FakeNewsSearchClient` itself. Serper is faked in every test. `composer test` green (650 total), `composer analyse` clean (level 7).

### Added — 2026-10-29 — Signals S1: module scaffold, data model & tenancy

- **The `Signals` module now exists** (`app/Modules/Signals/`, ADR 0002), registered via the Core base provider. No external calls, controllers or business logic yet — S1 is the data model and its tenancy guarantees; Serper, AI extraction, conversion and the UI follow in S2–S6.
- **Four backed enums, no magic strings:** `SignalEventType` (`Acquisition`/`FundingRound`/`Relocation`, each with `label()` + `description()` for the UI), `SignalStatus` (`New`/`Dismissed`/`Converted`), `SignalWatchCadence` (`Daily`/`Weekly`, with `staleAfterHours()` → 24 / 168 so the S3 scheduler has no magic numbers), and `SignalRunStatus` (`Queued`/`Running`/`Completed`/`Failed`, `isTerminal()`/`isActive()` — mirrors `DiscoveryRunStatus`).
- **Three team-scoped tables.** `signal_watches` (what to watch: market/location, `event_types` json, cadence, active flag, `last_run_at`, nullable `icp_id` `nullOnDelete`); `signal_runs` (one execution: query/result/signal counts, `cost_micros` in integer micro-units, user-safe `error`, timings — mirrors `discovery_runs`); `signals` (one kept event: AI headline/summary/scores, subject person **name + public role only — never email or phone**, amount as integer minor units + `Currency`, `raw` clamped Serper item, `source_url`, `url_hash`). Unique `(team_id, url_hash)` so one article is never two signals for a team; feed index `(team_id, status, published_at)`.
- **One-way link to Lead Sourcing.** `signals.lead_id → leads` is `nullOnDelete`, so a converted signal points at its lead but **Lead Sourcing never depends on Signals** — delete the lead and the signal simply loses the link (covered by a test).
- **Models, policies, exception, logging.** `SignalWatch`/`SignalRun`/`Signal` all `BelongsToTeam` with typed casts and `@property` docblocks, plus factories with useful states. `SignalWatchPolicy`/`SignalPolicy` double-check `team_id` via `User::roleOn()` — every role may view, only Owner/Admin/Member may create/run watches or dismiss/restore/convert signals. `SignalsException extends DomainException` (`alreadyConverted()` 409, `watchInactive()`, `noSubjectToConvert()`), rendered centrally. New `signals` log channel (log IDs/counts only).
- **Tests (15, all green):** enum values/labels/behaviour; cross-tenant isolation and `team_id` auto-assign for all three models; per-team `url_hash` uniqueness (same hash allowed across teams, rejected within one); `lead_id` nulling on lead delete; and the full policy matrix (owner, plain member, read-only, outsider). `composer test` green (614 total), `composer analyse` clean (level 7).
- **Deferred per plan:** `signal_watches.ignore_domains` (per-team ignore list) lands in S6; `config/signals.php` and env keys in S2.

### Added — 2026-10-29 — Signals S0: decisions, docs & Serper News spike

- **New feature planned: Signals** — a feed of wealth/liquidity events (a company sold, a founder exit, a big funding round, a founder/exec relocating to Dubai) in the team's ICP geography, found via Serper News, classified by AI, and convertible into a standard `Lead`. Phase S0 is docs-only (no application code); the build runs S0–S6 in `signals-plan.md`.
- **ADR 0011 accepted** (`docs/decisions/0011-signals-module.md`): Signals is its **own domain module** (ADR 0002), **not** a `LeadSourceProvider`. It reuses the Serper account (ADR 0010) via the `/news` endpoint; v1 is **UAE-only** and **snippet-only** (title + snippet + source + date, no full-page fetch); AI treats article text as data, never instructions; **conversion is the only route** from a signal to a `Lead`, via a one-way FK so Lead Sourcing never depends on Signals.
- **Module doc** `docs/modules/signals.md` created from the template: purpose, planned code map, data model (ER diagram for `signal_watches`/`signal_runs`/`signals`), the watch-run and conversion flows, external dependencies, permissions — and the recorded Serper spike.
- **Serper News spike run with the live key** (`gl=ae, hl=en, tbs=qdr:w`): the plan's **strict exact-phrase templates return 0 results** (7 of 8 queries); **loosened plain-keyword variants return 3–10 genuine Dubai/UAE events per event type** — well past the ~3/week bar — so the feature proceeds. `/news` caps at ~10 items/query, so coverage comes from template breadth, not a bigger `num`; keyword hits include non-UAE deals that merely mention the UAE, so S3's AI prompt must geo-qualify. The S2 templates in `signals-plan.md` were **loosened accordingly**, with noisy domains to ignore recorded in the module doc.
- **Compliance** (`docs/compliance.md` new §5a): Signals processes personal data (a named person's name + public role from published news) on a **legitimate-interest** basis with a dedicated LIA section; **data minimisation** (no contact details), **retention** (dismissed deleted @ 90 days, unactioned @ 180 days, built in S6), a **DSAR exporter hook**, and a **UAE prior-consent outreach flag** on converted leads. Marked ⚖️ for the pre-launch legal review ADR 0007 already requires.
- **Wired into the map:** Signals added to `docs/architecture.md` (module table + flow), `CLAUDE.md` (§3 + §5 ADR list), and linked from the `mvp.md` status table. `signals-plan.md` S0 marked done.

### Fixed — 2026-10-29 — Discovery run detail listed no companies

- **A completed discovery run always showed "No companies matched", however many leads it had created.** `DiscoveryRunResource` nested `LeadResource::collection(...)` for its `leads`, and the Inertia controller serialises with `->resolve()`, which resolves only the **top** level — so the prop arrived as `run.leads = { data: [...] }` while `pages/lead-sourcing/Show.vue` reads `run.leads.length`. `undefined` is falsy, so the empty state won every time. The nested collection is now resolved to a plain list, the same fix `LeadResource.contacts` got in Phase 10.
- **Shipped broken in Phase 7**, not a Phase 10 regression: the resource, the controller and the Vue read are all unchanged since then (`git show HEAD:` confirms it). It survived because nothing asserted the leads — `DiscoveryApiTest` had a test _named_ "shows a single run with its leads" that only checked the id and status, and the screen had no web test at all.
- **Now pinned at both ends.** A new `tests/Feature/LeadSourcing/DiscoveryWebTest.php` asserts the run detail renders its companies as a list (with grades) and an empty run as an empty list, and the API test asserts the same plain shape — so the two can't drift again. A sweep of every other resource found one more nested collection (`IcpResource::criteria`), which is safe because that resource is only ever returned directly from the API rather than `->resolve()`d.

### Added — 2026-10-29 — Phase 10b: Leads Workspace (pipeline, notes, tasks, bulk, CSV)

- **A customisable pipeline.** `pipeline_stages` per team (`name`, stable `slug`, `position`, one `is_default`, an optional `won`/`lost` `outcome`), created **lazily** the first time a team opens the board — so a team that never looks at it never needs stages, and teams that existed before this phase get one the moment they do, with no backfill. The board is drag-and-drop **and** has an equivalent pick-then-Move control on every card, because a board that only works with a mouse is a board half the team can't use. Owner/Admin can rename (inline, on the column heading), reorder and add or remove columns; removing one **moves its leads** to the starting column and says so before you confirm; the last column and the starting column can't be removed; and the save Action keeps the "exactly one starting column" invariant by promoting another stage rather than letting a team edit its way into having none.
- **`leads.pipeline_stage_id` is nullable, and null means "the starting column".** Lead Sourcing creates leads knowing nothing about the board, so a lead's position is only recorded once someone decides something about it. The fold is resolved **inside the query** (`LeadQueryBuilder`), not by each caller — the list, `/api/v1`, a saved view and the CSV export therefore agree about which leads are in the first column, and changing which stage is the starting one carries the unplaced leads with it.
- **Tags, notes, tasks and assignment.** `lead_tags` (+ pivot; created on first use, matched on a normalised name so "Warm"/"warm"/" warm " are one tag — and deliberately **not** `Str::slug()`, which strips Arabic entirely), `lead_notes` (append-only; a note is amended by writing another), `lead_tasks` (separate `due_at` and `remind_at`, plus a `reminded_at` already in place so the Phase 12 sweep is idempotent from its first release), `lead_assignments` (a row per member, so a lead can be shared, and a record of who handed it over). Every user reference is **nulled** rather than cascaded where the record must outlive the account: a note is the team's record of a conversation, and an open task mustn't vanish because the person it was given to left.
- **Each control sends the whole new value** — the stage, the full tag set, the full assignee set — and the Action works out the difference, so a half-applied change can't leave the screen and the data disagreeing, and the timeline entry says what actually changed rather than what was submitted. Nothing is recorded when nothing changed: re-saving the same tags, completing an already-done task, or a drag that lands where it started.
- **Eight new timeline types**, written synchronously inside the Action that caused them. An entry's subject still sets its lifetime: a note's entry (which quotes an excerpt) goes when the note does, a task's entries go with the task, while stage moves, assignment and tag changes are appended so the whole path a lead took is kept.
- **Bulk actions** over a page's selection — add/remove tags, assign, unassign, move stage — with only the field the chosen action needs accepted (`requiredIf` on the enum), a team-scoped id resolution so a foreign id is simply not in the set, and a `max_bulk_leads` cap enforced in both the request and the Action. It **reuses the single-lead Actions**: 500 small transactions instead of one big `update`, in exchange for bulk history being identical to doing it one lead at a time. `suppress` and `add to outreach` are deliberately **absent** until Compliance (Phase 15) and Outreach (Phase 11) give them a meaning.
- **CSV import.** The browser reads the header row and fills in the column mapping, so a two-step server flow never has to store a file full of someone's leads; the upload is then read in place. The reader is conservative about what a "CSV" is because the file comes from whatever the team exported from: a UTF-8 BOM is stripped, Windows-1252 is converted, the delimiter is sniffed (`,` `;` tab), short rows are padded rather than dropped, tags split on the **Arabic** comma as well as the Latin one, and the row count is capped with the result saying when it stopped early. Each row is its own transaction with its own error handling, every value is clamped to its column, websites are scheme-checked before they can become an `<a href>`, and emails that aren't emails are dropped. De-duplication is by the same `dedupe_key` Lead Sourcing uses and **fills gaps, never overwrites** — the spreadsheet is the team's older copy and enrichment's findings are better data. The result reports created / merged / skipped / truncated, because "40 imported, 3 merged, 1 skipped" is the only answer that says whether to go looking for something.
- **A new `LeadSource::Import`**, ranked **above every provider** and not selectable for a discovery run. Not because a spreadsheet beats a registry, but because it is the team's own record of who the company is: a later discovery hit now fills in the number and address it was missing instead of renaming the company they typed — the same "fill in, never overwrite" promise in the other direction.
- **CSV export.** Streamed in chunks by **keyset** on `id` (so a slow export can't skip or repeat a lead), honouring whatever the list is filtered to, with a UTF-8 BOM so Excel shows "Café Ltd" rather than "CafÃ© Ltd", the stage column naming the starting column for unplaced leads, and **formula injection neutralised** — a company name of `=HYPERLINK(…)` arrives from a web-search hit or a supplied CSV, and the export is opened in Excel by an Owner or Admin. The column set is the importer's own, so an export can be edited in a spreadsheet and brought straight back; the apostrophe the exporter adds is stripped on the way in, so the round trip is lossless.
- **Permissions:** `LeadPolicy` gained `work` (stage, tags, notes, tasks, assignment and their bulk forms — one ability because it is one job), and `import`/`export` as **Owner/Admin**, because both move a team's lead data in or out in bulk, contact details included (docs/compliance.md). `PipelineStagePolicy::manage` is Owner/Admin — the board is shared furniture — while moving one lead is open to any member. New `LeadNotePolicy` and `LeadTaskPolicy`; a task can be ticked off by any member, because a task only one absent person can close is a task that stays open.
- **Routes:** 21 web and 7 `/api/v1` (the mobile app's stage move, note, task, tags and assignment, through the same Actions). `/leads/bulk`, `/leads/import` and `/leads/export` carry a per-member `leads-bulk` rate limiter. New `config/leads-workspace.php` (`default_stages`, `LEADS_MAX_STAGES`, `LEADS_BOARD_COLUMN_LIMIT`, `LEADS_MAX_BULK`, `LEADS_BULK_RATE_LIMIT`, `LEADS_CSV_*`), documented in `.env.example`.
- **Frontend (on brand, EN/AR + RTL, WCAG AA):** `pages/leads/Pipeline.vue` (a horizontally scrolling board that mirrors, cards that drag or move by picker-plus-button, inline rename, a removal confirmation stating where the leads go, and a live region announcing each move because the card leaves the column), `pages/leads/Import.vue` (upload, client-side header read, mapping with examples from the file), plus `components/leads/{BulkActionBar,LeadWorkPanel,LeadNotes,LeadTasks}.vue`. The list gained per-page selection with an always-mounted live region, stage/tag/assignee/"nobody" filters, a Stage and a Who's-on-it column, and Board/Import/Export buttons. EN + AR bundles at parity (569 keys each).
- **Tests:** `PipelineTest` (lazy board creation, unplaced leads in the default column, moves with timeline + audit, no-ops, the one-default invariant under every edit, reorder keeping a column the caller didn't know about, removal moving leads, role gates, no lazy load), `LeadCollaborationTest`, `BulkLeadActionTest`, `LeadCsvTest` (mapping, merge, encodings, round trip), `LeadCsvHardeningTest` (formula cells both ways, over-long values, `javascript:` URLs, Arabic commas, re-imported contacts, header-only files, quoted newlines), `LeadWorkspaceFiltersTest` (every new filter across the list, the API, a saved view and the export) and `LeadWorkApiTest`, plus global-scope and auto-assign tests for all five new tenant models. Full suite **597 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass.
- **Reviewed** by the `laravel-module-reviewer` + `brand-reviewer` agents. Actioned on the backend: the Phase 10b filters were **never in `ListLeadsRequest`'s rules**, so `stages`/`tags`/`assignees`/`unassigned` were silently dropped from every URL — found by writing the feature tests the review said were missing; the null-means-default fold moved from a flag each controller had to set into the query itself (the API and the export had been quietly disagreeing with the web list); a team can no longer edit its way into having no starting column; the CSV export neutralises formula cells and the importer strips the prefix back off; imports are attributed to a new `LeadSource::Import` that outranks every provider instead of masquerading as a web-search hit; import values are clamped, websites scheme-checked, emails validated, email-less contacts matched on name so a re-import doesn't stack them up, and a row the database refuses is skipped with a logged reason rather than 500-ing the upload and losing its audit entry; assignment creation is race-safe; and the bulk action's return value is documented as "applied to", not "changed". On the brand side: the card's stage picker no longer fires a request per arrow keypress (pick, then press Move — WCAG 3.2.2), the board announces each move and the list announces each selection through always-mounted live regions, focus is restored after a bulk action and moved into every "add" form that replaces its own button, select-all has an indeterminate state, the required-field marker no longer uses Signal Orange (which is decorative only) and says "(required)" in words, the blocking bulk-limit message uses the error token rather than warning, back arrows mirror under RTL, the drop highlight no longer flickers as the pointer crosses child elements, tag splitting handles the Arabic comma, icon-only controls gained tooltips and 24–32px targets (the in-chip × buttons were a bare 12px), assignee and tag groups gained group names, names came out of DM Mono (which has no Arabic glyphs), removing a stage now asks first, and the copy that promised renaming is now true — rename was built. Deferred-and-documented: a name-only import still can't merge with a discovered lead (Lead Sourcing's name key embeds its provider, and matching on a bare name across sources is the false merge Enrichment refuses to make), no Reverb on the board, no queued import for very large files, no shared `ui/select`, client-side Windows-1256 detection, and the app-wide brand debt both reviews re-flagged.

### Added — 2026-10-29 — Phase 10: Leads Workspace (list, detail, contacts, timeline)

- **Leads Workspace module** (`app/Modules/LeadsWorkspace`): the screen a team actually works in. A server-driven **leads index** (filters, search, sorting, paging, saved views), a **lead detail** page (company facts with source attribution and data freshness, contacts, the current fit grade, where the lead came from) and an **activity timeline** that aggregates what has happened to a lead from wherever in the platform it happened. The module listens **outwards** — Lead Sourcing, Enrichment and Scoring know nothing about it.
- **Data model:** `lead_activities` (`BelongsToTeam`; `lead_id`, `user_id` **null on delete** so history outlives an account, `type`, a nullable **polymorphic `subject`**, a `properties` JSON bag, `occurred_at`; indexed for the timeline query with `id` as a stable tie-break, plus a **unique (lead, type, subject)** behind the Action's upsert) and `saved_views` (`name`, canonical `filters` JSON, `is_shared`; **unique (team, user, name)** — the team is in the key because a user can belong to several, ADR 0006). Enums `LeadActivityType`, `LeadSort`, `LeadSortDirection`.
- **`properties` holds values, never a sentence.** The workspace is EN/AR (ADR 0007), so every timeline line is composed at render time from `type` + `properties` — a stored English string would also freeze yesterday's wording into a team's history.
- **An entry's subject sets its lifetime.** `discovered` is subjected to the run, `enriched` to the lead, `scored` to the `lead_scores` row, and `RecordLeadActivityAction` upserts on (lead, type, subject) with a matching unique index. So a retried job can't duplicate an entry, and a re-grade updates the entry in place exactly as the score row itself is updated in place. The trade-off is explicit: one "Enriched" entry showing the latest run, not twelve near-identical rows.
- **`LeadFilters`** — one filter shape that the query string, the query builder, the Inertia props and a stored view all speak. Every value is narrowed to an enum or a trimmed, length-capped string, coerced to the enum's own backing type, and **unknown keys and values are dropped, not rejected**, so a view written by an older release still opens minus the filter that no longer exists.
- **`ListLeadsAction`** — filters on grade (against the **active** ICP), source, enrichment state, country (a two-letter value is an ISO code; anything longer also matches region/locality) and free text over company identifiers plus contacts. Sorts by newest, company, **fit** (a correlated sub-query, so an ungraded lead sorts as null rather than being dropped by a join) or freshness (`coalesce(enriched_at, collected_at, created_at)`), always tie-broken on `id`. Filters ride every pagination link, so page 2 of a filtered list is still filtered and a shared URL reproduces what the sender saw.
- **Search is literal and correlated.** One raw `LIKE ... ESCAPE '!'` group per table (Laravel's `like` operator can't attach an `ESCAPE` clause), with `%`, `_` and `!` escaped, so a search for `100%` finds "100% Pure Ltd" rather than the whole team. `!` rather than a backslash because SQLite and MySQL disagree about backslashes inside string literals. Both groups are **parenthesised in the SQL**: a raw clause goes in verbatim, and an un-bracketed `or` inside the contacts sub-query would break the `leads.id = lead_contacts.lead_id` correlation and match one contact against every lead in the team.
- **A fixed query count.** The grade badge comes from an eager-loaded `activeScore`, the contact count from a `withCount`, and `FindLeadAction` eager-loads everything the detail screen touches (including the `team` the policies read) with the timeline capped at 50. Tested with `Model::preventLazyLoading()` **and** by asserting the query count is identical for a page of 5 and a page of 15.
- **Saved views** are real links (`/leads?view=12`), so they are shareable and the back button works. Opening a view uses **its** filters, not the URL's — the view _is_ the request. A stale or foreign view id yields the plain list rather than a 403: a bookmark that no longer resolves should still open the leads list.
- **Timeline triggers:** `DiscoveryRunCompleted` → `RecordDiscoveryActivity`, `LeadEnriched` → `RecordEnrichmentActivity`, and the **new `Scoring\Events\LeadScored`** (dispatched `afterCommit`, carrying the grade and score as well as the ids) → `RecordScoringActivity`. All three are `ShouldQueue` with `$tries`/`$backoff`/`$timeout`/`$afterCommit` and a `failed()`; all three return quietly when the lead, run or score has been pruned or merged away. The timeline is a read model, so losing an entry costs history, never data.
- **Web + API:** `GET /leads`, `GET /leads/{lead}`, `POST /leads/views`, `DELETE /leads/views/{view}`, and `GET /api/v1/leads` + `GET /api/v1/leads/{lead}` (the latter a single `LeadDetailResource` carrying the lead, its score and its timeline, so a phone doesn't make three round trips to fill one screen). `SavedViewPolicy`: create = any member except Read-only, view = own or shared, delete = own (an Owner/Admin may also tidy up a view a departed member left behind). Cross-tenant requests **404 before any policy runs**.
- **Frontend (on brand, EN/AR + RTL, WCAG AA):** `pages/leads/Index.vue` (filter bar, saved-view bar, table, pager, and **two** distinct empty states — "no leads yet" is a different situation from "nothing matched"), `pages/leads/Show.vue` (company facts, contacts with the free-tier email check, a compliance note when a contact looks personal, the fit panel, provenance and freshness, and the timeline), plus `components/leads/{LeadFilterBar,SavedViewBar,ActivityTimeline}.vue` and a reusable server-driven `components/Pagination.vue`. A **Leads** item joins the sidebar. EN + AR bundles at parity (419 keys each).
- **Tests:** `LeadsIndexTest` (grade/source/enrichment/country filters, search across company + contacts, `%`/`_`/`!` as literal characters, the contact-correlation regression, fit and company sorts, newest in both directions, pagination carrying filters, enum validation, no lazy load, constant query count), `LeadDetailTest`, `SavedViewsTest` (save, replace, unknown-filter drop, same name in two teams, opening a view, stale id, shared vs private, delete permissions, read-only refusal, validation, cascade), `LeadsApiTest`, `LeadTimelineTest` (each listener, idempotency, pruned subjects, actor attribution, event wiring) and `LeadsWorkspaceTenancyTest`, plus `LeadFiltersTest` as a unit. Full suite **477 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass.
- **Reviewed** by the `laravel-module-reviewer` + `brand-reviewer` agents. Actioned: the saved-view unique key and its upsert lookup now include `team_id` (a user in two teams saving the same view name was a 500); `ListSavedViewsAction` eager-loads `team`, so authorising a list of two or more views no longer trips `preventLazyLoading`; `lead_activities` gained a unique index so idempotency isn't application-level only; the three queued listeners gained `$timeout`, `$afterCommit` and `failed()`; both saved-view Actions now write their audit entry **inside** the same transaction as the data; the API detail endpoint became a `LeadDetailResource` instead of a hand-built envelope; `sort=newest` honours `direction` instead of silently ignoring it; and new tests cover the cross-team view name, the multi-view lazy-load case, the constant query count, and `_`/`!` as literal search characters. On the brand side: the saved-view delete button is always visible at a 24px target instead of hidden behind `group-hover`; the shared-view icon gained an `sr-only` label; grade filter chips gained spoken labels and a `border-input` edge so an unselected chip is visible; the sort-direction toggle uses Lucide arrows with a title; the brand full stop came off the company name on the detail page (it produced "Acme Ltd.." and landed on the wrong end under RTL); pagination's dim page text and the sort select's half-alpha focus ring were brought up to AA; opening and cancelling the save-view form moves focus, and the disabled button explains itself in text rather than only in a `title`; the timeline rail is laid out by its marker column instead of a hand-measured `17px`; two table cells moved their `dir` onto a `<bdi>` so RTL alignment holds; and one sentence that mixed "Alima" and "we" was made consistent. Deferred-and-documented: no shared `Select` component, no live (Reverb) updates, no manual re-enrich, no "load more" on the timeline, one `enriched` entry per lead rather than per enrichment, search needing a real index eventually, `LeadContact` still having no policy of its own (deferred to the Phase 15 compliance work), jargon in the shared enum labels, Arabic copy not yet reviewed by a native speaker, and the app-wide brand debt the review re-flagged (eyebrow-as-heading, three font weights, the `text-sm` body scale, `rounded-2xl` panels, hardcoded breadcrumb titles, the repeated focus-ring utility) which belongs in one pass across every screen rather than only the newest ones.

### Added — 2026-10-29 — Phase 9: Scoring & Matching

- **Scoring & Matching module** (`app/Modules/Scoring`): grades every lead against the team's **active** ICP with a **hybrid** score — a deterministic pass over the ICP's weighted criteria blended with an AI qualitative assessment (`scoring.qualitative_fit`, reasoning tier) — and stores the 0–100 score, an **A–D grade**, a per-criterion breakdown with evidence, and a plain-English explanation.
- **Data model:** `lead_scores` (`BelongsToTeam`; `score` + `grade`, both halves `deterministic_score`/`ai_score`, `breakdown` JSON via a `CriterionScoreList` cast, `explanation`, `strengths`/`concerns`, `excluded` + `excluded_by`, prompt/model provenance, cumulative `cost_micros`, `scored_at`; **unique (lead, ICP)** so a re-score updates in place) and `score_feedback` (`rating`, optional `comment`, plus a **snapshot** of the score + grade the member was reacting to; **unique (score, member)**). Enums `ScoreGrade`, `CriterionMatch`, `FeedbackRating`.
- **`DeterministicScorer`** — reproducible, no network. Weights are **normalised, not summed** (`matchedWeight / assessedWeight`), so an ICP with three criteria and one with thirty share a scale. A criterion the lead has no data for is `Unknown` and leaves **both** sides of the ratio, so the score measures fit rather than enrichment coverage; buying signals always abstain. Company size matches on **numeric band overlap** ("11–50" vs "25-60"); geography resolves **country aliases** (UAE ↔ AE); criterion values read as "any of" across Latin and Arabic separators, and values under four characters must hit a word boundary ("IT" never matches inside "Security"). A matched **exclusion is absolute**: score 0, grade D, and no AI call at all.
- **`ScoringPayloadBuilder` + `ScoreLeadAction`** — the lead block is built only from stored fields, names every field we don't hold as **unknown** (so the model can't quietly fill one in) and is wrapped in `UntrustedContent`; the ICP block is the team's own config. The AI call runs **outside** any transaction; the writes take a row lock and upsert in one short transaction, advancing `leads.status` to `scored` **only** from `discovered`/`enriched`. The model's own letter grade is discarded — the stored grade comes from the blended score, so one scale governs the product. Response values are clamped by `QualitativeFit`.
- **Degrades instead of failing, and never overstates:** credit exhaustion / un-validatable output / a missing score → rules-only score; **but if the lead already holds an AI-backed score, the existing one is left untouched** (a team-wide re-score that ran out of credit must not strip every remaining lead's explanation). No assessable criteria → AI-only. No assessable criteria **and** never enriched → **not scored, and the prompt is never called** — a grade off a bare company name would be a guess wearing a number. Neither half available → no row written; a lead is never branded "D" out of ignorance. A transport failure bubbles for the queue.
- **Re-scoring:** `EnrichLeadAction` now fires **`LeadEnriched`** `afterCommit` (surviving lead only) → `ScoreEnrichedLead` → `ScoreLeadJob`; `IcpUpdated` → the **queued** `RescoreTeamOnIcpChange`, which walks the team's leads in chunks and forces one job each (never fanning out inside the request). With no active ICP nothing is queued — old scores stay attached to the ICP that produced them as history, so members' feedback survives. `ScoreLeadAction` is idempotent against `enriched_at` + the ICP's `updated_at` (deliberately **not** the lead's own `updated_at`, which scoring itself touches), and `ScoreLeadJob` is `ShouldBeUnique` per (lead, ICP, forced).
- **Feedback loop:** `RecordScoreFeedbackAction` records one verdict per member per score with the score snapshot and an ids-only audit entry (`scoring.feedback_recorded`).
- **Web + API:** `GET|POST /leads/{lead}/score` and `POST /leads/{lead}/score/feedback`, mirrored under `/api/v1` (Resources only; `202` on a queued re-score, `404 scoring.not_scored`, `422 scoring.no_active_icp`). `LeadScorePolicy`: view = any member, **rescore = Owner/Admin** (it spends AI credit), feedback = any member except Read-only. A new `LeadPolicy` (`view`) backs the lead itself. Manual re-scores carry a dedicated `scoring` rate limiter.
- **Frontend (on brand, EN/AR + RTL, WCAG AA):** `pages/scoring/Show.vue` — grade panel with both halves, a neutral "ruled out by your profile" notice, the explanation with strengths/concerns, the criterion-by-criterion breakdown, a separate "not enough data to judge" block (so a thin lead is visibly _not_ marked down), and good/bad feedback buttons. Reusable `components/scoring/GradeBadge.vue` (A → success, B → info, C → warning, D → neutral outline; `role="img"` with a composed label, the letter never colour alone, Signal Orange nowhere near a status). The discovery run table gains a **Fit** column linking to the score screen. EN + AR bundles at parity (342 keys each).
- **Config:** `config/scoring.php` (`SCORING_BLEND_*`, `SCORING_GRADE_{A,B,C}`, `SCORING_DEFAULT_WEIGHT`, `SCORING_RESCORE_RATE_LIMIT`, `SCORING_RESCORE_CHUNK`, country aliases); `.env.example` documents each key.
- **Tests:** `DeterministicScorerTest` (normalisation, criterion-count independence, Unknown excluded from the ratio, range overlap, aliases, word boundaries, contributions summing to the score, absolute + unmatched exclusions), `ScoreLeadActionTest` (hybrid blend + grade + explanation with faked AI, grounded untrusted payload, no active ICP, excluded with zero spend, rules-only fallback, degraded re-score preserving a richer score, bare lead refused before spending, no-usable-score handling, idempotent reuse, forced re-score, re-score on fresh enrichment, cumulative cost, clamping), plus `ScoreLeadJobTest`, `RescoreTriggerTest`, `ScoreFeedbackTest`, `ScoringWebTest`, `ScoringApiTest`, `ScoringTenancyTest` and unit tests for the DTO + enums. Full suite **398 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass.
- **Reviewed** by the `laravel-module-reviewer` + `brand-reviewer` agents. Actioned: a degraded re-score no longer wipes an AI-backed score; a lead with nothing assessable and no enrichment is no longer graded (and no longer costs a prompt); the freshness check no longer invalidates itself via the lead's own status write; the pipeline stage only ever advances; the cumulative cost is read under a row lock; a missing AI score counts as "no AI half" rather than a confident zero; the queued re-score listener gained `$tries`/`$backoff`/`$timeout`/`failed()`; manual re-scores gained a rate limiter; the grade badge became `role="img"` with a composed label and the Fit link an explicit one; section eyebrows moved to the brand type scale; the "ruled out" notice became neutral rather than destructive, consistent with grade D. Deferred-and-documented: no sweeper for a score left stale by a failed AI half, buying signals resting entirely on the AI half, no seniority ladder, a short `country_aliases` list, global (not per-team) thresholds, no bulk re-score UI or Reverb progress, grade B's dependence on the still-PENDING `info` token, and the pre-existing 10px eyebrows on the Phase 6–7 screens.

### Changed — 2026-10-29 — Lead Discovery: pick multiple providers per search

- **Multi-provider discovery.** The discovery form now lets a member tick **one or more** lead sources (e.g. Companies House **and** Google Places) and search them in one action. `StartDiscoveryRunAction` **fans out** — one `DiscoveryRun` per selected source, each queued in parallel with its own status, metered cost, circuit breaker and retries, so one provider failing never affects the others. Cross-provider dedupe already collapses the results at the `leads` table. **No migration** — the data model (one source per run) is unchanged.
- **Request contract:** the start payload's `source` (string) became **`sources`** (array, `distinct`, each a live provider). The web `store` now redirects to the discovery **index** (for both one and many providers); the API `store` returns a **201 resource collection** (`{ data: [ ... ] }`) instead of a single run.
- **UI:** the source picker is a multi-select (radio → accessible checkbox, brand-tick cue, focus ring preserved); the recent-runs list subscribes to Reverb so each freshly-started run's status badge updates live in place. EN/AR + RTL parity for the new strings.
- **Tests:** `StartDiscoveryRunTest` + `DiscoveryApiTest` cover the fan-out (N runs, N jobs), `sources` validation (required / bad provider / `distinct`), and the new redirect + collection shape; `DiscoveryGuardsTest` updated to the new field. Full suite **313 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass.

### Added — 2026-10-29 — Phase 8: Enrichment & Data Quality

- **Enrichment module** (`app/Modules/Enrichment`): turns raw discovered leads into clean, deduped, source-attributed leads with contacts, free-tier email verification and a data-freshness stamp. No paid provider (ADR 0008) — facts come from **AI extraction of the lead's public web text** (`lead.enrich_extract`, cheap tier, injection-safe); confidence is **stored, never asserted**.
- **Data model:** enrichment columns on `leads` (`enrichment_status`, `industry`, `employee_range`, `technologies`, `field_sources` JSON, `has_personal_data`, `enriched_at`, `enrichment_cost_micros`) and a child **`lead_contacts`** table (`BelongsToTeam`; name/title/email/phone, email verification status + confidence, `is_role_based`/`is_personal`, source attribution). Enums `EnrichmentStatus` (`pending`/`enriching`/`enriched`/`failed`) and `EmailVerificationStatus` (`unknown`/`invalid_syntax`/`disposable_domain`/`no_mx_record`/`mx_found`, each with a soft `confidence()`).
- **Per-field provenance:** `SourcedValue` DTO (source/method/source_url/collected_at) + a `SourcedValueMap` Eloquent cast store `leads.field_sources` so every AI-enriched field is traceable (ADR 0008).
- **Pipeline:** `EnrichLeadAction` (normalise → fetch → AI extract → verify emails → fill empty fields with provenance + upsert contacts + flag personal data + stamp freshness/cost → de-duplicate; external calls outside transactions; idempotent; terminal on credit/validation errors) run by the idempotent, unique-per-lead `EnrichLeadJob`. A completed `DiscoverLeadsJob` now fires **`DiscoveryRunCompleted`**, and the `EnrichDiscoveredLeads` listener fans out one job per still-pending lead.
- **Email verification (free-tier):** `EmailVerifier` does syntax → disposable-domain → MX/DNS (behind a `DnsResolver` seam, faked in tests — no real DNS, no SMTP probe) and classifies role/business vs personal addresses.
- **De-duplication:** `LeadDeduplicator` collapses duplicates by exact company number, website domain, or a same-domain routable contact email — never a fuzzy name — merging each into the most authoritative survivor (`LeadSource::rank()`, oldest on a tie), keeping the best source per field, carrying contacts + enrichment bookkeeping across, and adopting the strongest `dedupe_key`.
- **Shared web fetcher moved to Core:** the SSRF-hardened `WebContentFetcher` (contract + `HttpWebContentFetcher` + fake) now lives in `app/Modules/Core` and is bound in `CoreServiceProvider`, so Business Intelligence and Enrichment share one hardened fetch. Fetch config moved from `config/business-intelligence.php` (removed) to **`config/web-fetch.php`** (`WEB_FETCH_*`). New `config/enrichment.php` (`ENRICHMENT_MAX_CONTACTS`, role-mailbox + disposable + blocked-dedupe domain lists).
- **Tests:** `EmailVerifierTest`, `ExtractedFactsTest`, `EnrichLeadActionTest`, `EnrichLeadJobTest`, `LeadDeduplicatorTest` (incl. conflicting-number / aggregator-domain / cross-team non-merge + bookkeeping promotion guards), `LeadContactTenancyTest`, `EnrichmentTriggerTest`, and the relocated `Core/WebContentFetcherTest`. Full suite **311 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass.
- **Reviewed** by the `laravel-module-reviewer` agent. Actioned (HIGH/MEDIUM): hardened de-duplication against scraped-content-driven false merges (skip conflicting registry numbers, a shared-domain/free-mail blocklist, email matches only when routable and on the lead's own domain), promoted enrichment status/freshness/cost onto the survivor so a merge never loses work, group row-locking in id order to stay deadlock-safe under the per-run fan-out, UTF-8-safe JSON in the provenance cast, dropped the dead "outranks" merge branch, stopped persisting invalid-syntax emails, added merge provenance, plus job + dedupe-guard tests. Deferred-and-documented: a manual re-enrich/`force` trigger (Leads Workspace, Phase 10), a `LeadContact` policy (before contacts are exposed, Phase 10), and the carried-over Core-fetcher DNS-rebind/URL-logging notes (unchanged from Phase 6).

### Added — 2026-10-29 — Phase 7b: Lead Sourcing (Google Places + web search)

- **Two more lead providers** behind the existing `LeadSourceProvider` contract: **`GooglePlacesProvider`** (Places API New Text Search — local/SMB discovery by location + category, strong for the UAE) and **`WebSearchProvider`** (Serper driver — organic results mapped to company candidates by domain). All three providers are now selectable; `LeadSource::isAvailable()` returns true for each.
- **ADR 0010** records **Serper** as the MVP web-search provider; `WebSearchProvider` is a driver-style seam (config `web_search.provider`) so Brave/Bing can be added later, and an unimplemented driver fails fast with a config error rather than degrading silently.
- **Shared HTTP hardening** extracted to `AbstractHttpLeadSourceProvider` (per-provider circuit breaker that trips only on transient 5xx/429/connection failures, config-error-vs-transient key checks, any failure → `LeadSourcingException`); Companies House, Google Places and web search all extend it.
- **Cross-provider dedupe:** the `dedupe_key` is now **source-agnostic** (company number → domain → name slug+hash with no source prefix), so the same company found by two providers collapses to one lead where they share a key type (domain-keyed Places + web search dedupe with each other; number-only Companies House links up once Enrichment adds a domain).
- **Per-provider cost** surfaced: each provider reports `costMicros` (Companies House free; Google Places/Serper an indicative per-request rate from `config/lead-sourcing.php`), recorded on `discovery_runs.cost_micros` and shown as an estimated cost on the run screen. New config blocks for both providers (`GOOGLE_PLACES_*`, `WEB_SEARCH_*`) documented in `.env.example`.
- **Tests:** `GooglePlacesProviderTest` + `WebSearchProviderTest` (mapping, query + headers, cost incl. zero-on-no-op, missing-key fail-fast, aggregator-domain skip, origin normalisation, place-id key, API failure, non-serper driver fails fast), `ProviderRegistryTest` (all three resolve via the service provider), cross-provider dedupe + attribution-upgrade + non-retryable fast-fail job tests, a 4xx-doesn't-trip-breaker test, and `CircuitBreakerTest`. Existing enum/dedupe/validation tests updated. Full suite **279 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass.
- **Reviewed** by the `laravel-module-reviewer` + `brand-reviewer` agents. Actioned: a HIGH attribution bug (cross-provider dedupe was overwriting the richer source's `source`/`source_url`/`raw`/name) fixed with a rank-aware merge that preserves the authoritative source and the first discoverer; stored URLs restricted to http(s); HTTP retry limited to transient conditions; a missing API key is now a non-retryable misconfiguration (the job fails fast instead of burning retries); the breaker trips only on transient failures (not arbitrary throwables/4xx); web search skips aggregator/social domains and stores the site origin; place-id + case-insensitive name dedupe keys; the run-cost chip is bidi-isolated for RTL. Deferred-and-documented: Places' 20-result cap (no pagination), web-search low-confidence titles, and currency localisation of the displayed cost (flagged for brand-owner).

### Added — 2026-10-29 — Phase 7: Lead Sourcing (framework + Companies House)

- **Lead Sourcing module** (`app/Modules/LeadSourcing`): a pluggable `LeadSourceProvider` framework, queued/tracked discovery runs with live progress and per-plan limits, and the first provider — **Companies House** (UK official registry, free; ADR 0008). Discovers raw **company-level** leads for a team and hands them off to Enrichment/Scoring; it does not enrich, dedupe (beyond a placeholder) or score.
- **Data model:** `discovery_runs` (`BelongsToTeam`; `source`, `status`, optional `icp_id`, `params` JSON, `requested_limit`, `leads_found`/`leads_created`, `cost_micros`, `error_message`, `started_at`/`completed_at`, `created_by`) and `leads` (`BelongsToTeam`; company facts, flattened address + `address`/`sic_codes` JSON, source attribution `source`/`source_url`/`collected_at`/`raw`, and a `dedupe_key` **unique per team**). Enums `LeadSource` (`companies_house`/`google_places`/`web_search`, only Companies House `isAvailable()`), `DiscoveryRunStatus` (`pending`/`running`/`completed`/`failed`), `LeadStatus` (`discovered`/`enriched`/`scored`).
- **Provider seam:** `LeadSourceProvider` contract + `LeadSourceProviderRegistry` (resolve by `LeadSource`) + `CompaniesHouseProvider` (`/search/companies`, HTTP Basic with the API key, configurable timeouts, `Http::retry` with backoff, and a shared cache-backed `CircuitBreaker` that opens after N consecutive failures). Maps results into provider-agnostic `SourcedLead` DTOs carrying the public record URL as attribution. `Testing\FakeLeadSourceProvider` runs the whole pipeline offline. New providers (Phase 7b) register here without touching the pipeline.
- **The run:** `DiscoverLeadsJob` (`ShouldQueue`, `ShouldBeUnique` per run; `tries=3`, `backoff`, `timeout=85`). Claims the run (`pending → running`, locked), calls the provider **outside any transaction**, persists leads in a short transaction via `updateOrCreate` keyed on `(team_id, dedupe_key)` (the Phase 7 dedupe placeholder — same company found again updates in place), records counts + `cost_micros`, settles `completed`, and logs on the `lead_sourcing` channel. A retry whose run is already terminal no-ops; a provider failure bubbles so the queue retries, and `failed()` marks the run `failed`.
- **Progress over Reverb:** `DiscoveryRunProgressed` (`ShouldBroadcast`) reuses the `teams.{teamId}` private channel (`discovery-run.progressed`), carrying only status/step/percent/counts — never lead records.
- **Start + gatekeeping:** `StartDiscoveryRunAction` checks provider availability, the **LIA gate** (`LeadInterestGate`, stubbed allow-all until Compliance/Phase 15) and the **plan limit** (`DiscoveryLimitGuard` + `LeadQuotaSource`, stubbed unlimited until Billing/Phase 16), resolves the ICP (chosen or active) into a deterministic `DiscoveryQuery` (terms from input or the ICP's industry signals; size clamped to config max + remaining quota), refuses with `nothingToSearch` when there's nothing to search, opens a `pending` run, audits `lead_sourcing.run_started`, and dispatches the job **`afterCommit`**. `GetDiscoveryRunsAction`/`FindDiscoveryRunAction` are team-scoped reads. `DiscoveryRunPolicy`: view = any member, start = Owner/Admin.
- **Web + API:** the Inertia discovery workspace (`GET /discovery`, `POST /discovery`, `GET /discovery/{run}`) and `GET|POST /api/v1/discovery-runs` + `GET /api/v1/discovery-runs/{run}` (Resources only, same Actions as web).
- **Frontend (on brand, EN/AR + RTL, WCAG AA):** `pages/lead-sourcing/Index.vue` (source picker with "Soon" chips for not-yet-live providers, ICP select, terms/location, bounded limit, recent-runs list with semantic status badges) and `Show.vue` (live progress bar over Reverb filtered by `run_id`, failed `role="alert"`, completed leads table with external record links). Sidebar gains a "Lead discovery" entry (Radar icon). EN + AR bundles extended to parity.
- **Config:** `config/lead-sourcing.php` (run defaults, circuit breaker, Companies House endpoint/credentials/HTTP tuning); `.env.example` documents `COMPANIES_HOUSE_*` + optional `LEAD_SOURCING_*` keys.
- **Tests:** `CompaniesHouseProviderTest` (result mapping + attribution, basic-auth + items-per-page cap, empty terms, API-failure + missing-key → domain exception), `DiscoverLeadsJobTest` (persist + complete with counts, dedupe across runs, limit respected, Reverb progress + terminal event, idempotent no-op on a terminal run, `failed()` path), `StartDiscoveryRunTest` (ICP-shaped run + dispatch, explicit-terms override, redirect, source validation, read-only forbidden, cross-tenant ICP rejected, nothing-to-search), `DiscoveryApiTest`, `DiscoveryTenancyTest` (Lead scoping, per-team dedupe uniqueness, web index/show + API index tenancy, guest redirect), `DiscoveryGuardsTest` (quota-exhausted 429 envelope, LIA gate runs before a run is created, open circuit refuses without an HTTP call), and unit tests for the enum, `SourcedLead` dedupe/domain, `DiscoveryQuery` and `CircuitBreaker`. Full suite **254 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass.
- **Reviewed** by the `laravel-module-reviewer` + `brand-reviewer` agents. Actioned: circuit breaker trips on transient (5xx/429) failures only; re-discovery preserves the original discoverer + never nulls enriched fields; name dedupe key clamped + hashed; the job enforces the run-size cap; `source_url` clamped to the column; the API start returns `201`; an accessible source picker (focus-within ring + non-colour selected cue) inside a real `<form>` with `type="submit"`; aligned the native select to the brand field style; full EN/AR parity for provider/status labels; table a11y (`scope`, caption, new-tab cue, `dir="ltr"` company number). Deferred-and-documented (future phases): search-by-name/dissolved-company quality, a dedicated per-team run-creation rate limit, and a transactional quota check (all Billing/Scoring-phase concerns).

### Added — 2026-10-29 — Phase 6: Business Intelligence (AI background check)

- **Business Intelligence module** (`app/Modules/BusinessIntelligence`): a re-runnable, **versioned** AI background check on the team's own business. Fleshes out the Phase 3 stub — `CompleteOnboardingAction` now dispatches the real `RunBackgroundCheckJob` (`afterCommit`); the stub `RequestBackgroundCheckJob` is removed.
- **Data model:** `business_intel_reports` (`BelongsToTeam`; `version` unique per team, `status`, snapshot `business_name`/`website`, `findings` JSON, prompt provenance `prompt_key`/`prompt_version`/`model`, `cost_micros`, `error_message`, `completed_at`, `accepted_at`/`accepted_by`). `BackgroundCheckStatus` enum: `pending`/`ready`/`accepted`/`failed`.
- **The run:** `RunBackgroundCheckJob` (`ShouldQueue`, `ShouldBeUnique` per team; `tries=3`, `backoff`, `timeout=85` — under the queue's `retry_after`). Resolves the report to fulfil (an explicit pending report from a re-run, or the team's pending/next version), fetches the business's public homepage text, runs `business_intel.background_check` through `AiClient`, stores the validated `BackgroundCheckFindings` as a new version, and broadcasts progress. External fetch + AI run **outside** any transaction; fetched text is wrapped in `UntrustedContent` (data, never instructions). Credit exhaustion / un-validatable output are terminal (report → `failed`, no retry); a retry whose report is already terminal no-ops (no double AI spend); `failed()` fails a stranded pending report.
- **Web fetch (SSRF-safe):** `WebContentFetcher` seam with `HttpWebContentFetcher` — http(s) + ports 80/443 only, rejects any host resolving to a loopback/private/link-local/reserved address (and re-validates every redirect), **streams** and byte/char-caps the body, requires an HTML/text content-type, strips scripts/styles/markup to plain text, and degrades to `''` on any failure. Bounded by `config/business-intelligence.php` (`BI_FETCH_*`). `Testing\FakeWebContentFetcher` for tests.
- **Progress over Reverb:** `BackgroundCheckProgressed` (`ShouldBroadcast`) on the new `teams.{teamId}` private channel (member-only auth in `routes/channels.php`), carrying non-sensitive status/step/percent only.
- **Review + accept:** `RerunBackgroundCheckAction` (opens a new pending version, dispatches the job; refuses to stack a second run, but reaps a report stranded pending >15 min so a team is never locked out), `AcceptBackgroundCheckAction` (merges findings into **blank** Business Profile fields only — never overwriting — and optionally seeds a non-active "Suggested from background check" ICP via the ICP module's `CreateIcpAction`, then marks the report accepted; one transaction, row-locked + re-checked for idempotency), `GetLatestReportAction`/`FindReportAction` (team-scoped reads). `BusinessIntelReportPolicy`: view = any member, manage (rerun/accept) = Owner/Admin.
- **Findings DTO:** `BackgroundCheckFindings` hydrates + cleans the validated AI output and **clamps** text/list lengths so a runaway response can't bloat storage, the profile or later prompts.
- **Web + API:** the Inertia review screen (`GET /business-intel`, `POST /business-intel/rerun`, `POST /business-intel/{report}/accept`) and a read-only `GET /api/v1/business-intel` (latest report via `BusinessIntelReportResource`, reused by the web payload so they can't drift).
- **Frontend (on brand, EN/AR + RTL, WCAG AA):** `pages/business-intel/Review.vue` — states for never-run, a live progress bar (subscribed to `teams.{id}` over Reverb; `role="progressbar"` + `aria-live`), failed (`role="alert"`, `bg-destructive`), and ready/accepted with findings cards diffed against the team's own profile and an accept panel (labelled checkboxes). Sidebar gains a "Background check" entry (Scan icon). EN + AR bundles extended to parity.
- **Tests:** `RunBackgroundCheckJobTest` (ready report + provenance from faked AI, untrusted-wrapping, versioning, terminal-failure handling, idempotent no-op on a terminal report, `failed()`, Reverb progress), `BackgroundCheckReviewTest` (render, rerun versioning + dispatch, accept merges blanks + seeds ICP + marks accepted, no-overwrite, read-only forbidden, cross-tenant 404, `alreadyRunning`, stale-pending rerun, not-ready accept, validation 422, API auth/null/read), `WebContentFetcherTest` (script/style stripping, SSRF guards for loopback/metadata/private/IPv6/non-standard-port, content-type, bare-domain https), `BackgroundCheckFindingsTest`. Full suite **205 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass. Reviewed by the `laravel-module-reviewer` + `brand-reviewer` agents; SSRF hardening, stream/byte caps, stale-pending recovery, job idempotency, length clamps, controller-query → Action, progress/label a11y fixes all actioned.

### Added — 2026-10-29 — Phase 5: AI Platform

- **AI Platform module** (`app/Modules/AiPlatform`): the internal, pure-capability layer every AI-using feature calls. Callers depend only on the `AiClient` contract (`structured(key, input, version?)`), never on the SDK — the transport can be swapped without touching them (ADR 0005). Installed **`laravel/ai`** (first-party) as the transport, default provider **Anthropic Claude** (`claude-sonnet-5-5` reasoning / `claude-haiku-5-5` cheap).
- **Transport seam:** `AiTransport` contract with `LaravelAiTransport` (the only class that touches `laravel/ai`; wraps an ad-hoc `RegisteredPromptAgent`, converts our stored JSON-schema → Laravel schema types via `SchemaConverter`, normalises any SDK failure to `AiTransportException`). Ships `Testing\FakeAiTransport` so the whole pipeline runs in tests with **no network call**.
- **Prompt registry:** `ai_prompt_templates` (global config; `key`+`version` unique, `tier`, `system`, `body` with `{{placeholders}}`, `schema`, `max_tokens`, `temperature`, `is_active`) + `PromptRegistry` (resolves the active/highest version, reads DB each call so workers see promotions without a restart). All **six** prompts from `docs/ai-prompts.md` seeded at v1 (`AiPromptTemplateSeeder`). Changing a prompt means a new version, never an edit in place.
- **Structured output:** `JsonSchemaValidator` (dependency-free subset validator) checks every response against the prompt schema; invalid responses are **retried** (`AI_SCHEMA_RETRIES`, default 1) then raise `InvalidAiResponseException` — callers never receive unvalidated data. Output returns as a typed `StructuredAiResponse`.
- **Cost + usage:** `ai_requests` per-call ledger (`BelongsToTeam`, auto-attributed from `TeamContext`) with token counts and **`cost_micros`** — cost in integer **micro-units** of currency (1_000_000 = one major unit) to preserve sub-cent precision. `CostCalculator` prices per-model from config (indicative — verify live). **Every retry attempt is billed**, not just the final outcome. Logs IDs/counts/cost only — never prompt content or personal data.
- **Credit guard:** `AiCreditGuard` + `CreditSource` (config-backed `StubCreditSource`) blocks a team that is over its monthly allowance **before any provider call** (margin protection). 1 credit = `AI_MICROS_PER_CREDIT` micros (default 1_000 ≈ $0.001); default 100_000 credits ≈ $100/mo. Replaced by plan allowances in Billing (Phase 16), no caller changes. A system call with no team is a no-op but any unattributed real spend raises an `ai`-channel warning.
- **Response cache:** identical calls (keyed on **team** + prompt key+version + **template-content hash** + model + normalised input) are served from cache with zero cost. Never shared across tenants; trusted vs. `UntrustedContent` inputs hash differently, so neither framing can serve the other's result.
- **Injection safety:** `UntrustedContent` + `PromptInterpolator` wrap externally-sourced input in a nonce-delimited data block with a "data, not instructions" directive, scrubbing the random nonce from the content so the closing marker can't be forged.
- **Config:** `config/ai.php` (laravel/ai transport — Anthropic) + `config/ai-platform.php` (tiers, pricing, credits, cache, retries); `.env.example` AI keys documented.
- **Tests:** `AiClientTest` (happy path + cost/usage logging, tier→model+price, retry-then-succeed, retry spend accounting, exhausted-retry failure, cache hit avoids a 2nd call, per-team + trusted-vs-untrusted cache isolation, credit-block-before-call, injection wrapping, raw-JSON-text path, unknown prompt, tenancy scoping), `AiUsageAndCreditTest` (per-team current-period usage, stub allowance, guard blocks only the over-limit team, no-team no-op, cross-tenant read isolation), `PromptRegistryTest` (seeds all six, resolves active version, every schema converts + a canned response validates, idempotent re-seed), `CostCalculatorTest`, `JsonSchemaValidatorTest`, `PromptInterpolatorTest`. Full suite **170 green**; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass. Reviewed by the `laravel-module-reviewer` agent; findings on retry-spend accounting, cache key (team + untrusted + template hash) and registry staleness actioned.

### Added — 2026-10-29 — Phase 4: Ideal Customer Profile (ICP)

- **ICP module** (`app/Modules/ICP`): a team defines one or more weighted **Ideal Customer Profiles** (one active) that drive discovery and scoring.
- **Data model:** `icps` (`name`, `description`, `is_active`) and `icp_criteria` (`type`, `value`, `weight`, `position`), both `BelongsToTeam`. `weight` is 1–100 for positive **signals** and null for **exclusions**; `position` preserves editor order.
- **Criteria types** (`CriterionType` enum, no magic strings): industry, company size, geography, decision-maker title, seniority, technology used, buying signal — plus `exclusion` (unweighted disqualifier: competitors, existing customers, domains).
- **Actions:** `CreateIcpAction` (a team's first ICP auto-activates), `UpdateIcpAction`, `SetActiveIcpAction`, `DeleteIcpAction` (promotes the newest remaining ICP when the active one is deleted), `GetIcpsAction` + `FindIcpAction` (team-scoped reads). Writes run in `TeamContext->run` + `DB::transaction`; criterion/activation logic is shared via the `WritesIcpCriteria` trait.
- **Re-score event:** `IcpUpdated(teamId, activeIcpId)` fires `afterCommit` whenever the active ICP's definition changes, the active ICP switches, or the active ICP is deleted — the integration point for Scoring & Matching (Phase 9). No listener yet.
- **Web + API:** the Inertia ICP workspace (`GET /icp`, `create`, `store`, `edit`, `update`, `activate`, `destroy`) guarded by `IcpPolicy` (view = any member; manage = Owner/Admin); full `/api/v1/icps` CRUD + activate via `IcpResource`/`IcpCriterionResource`. `FindIcpAction` resolves `{icp}` inside the team context so cross-tenant ids 404.
- **Frontend (on brand, EN/AR + RTL, WCAG AA):** `pages/icp/Index.vue` (saved profiles as cards with active state, inline delete confirm, bright empty state) and `pages/icp/Edit.vue` — the weighted editor with per-signal **importance sliders** (`accent-brand`), a guided scaffold for new profiles, and an exclusions section. One orange full-stop accent; `rtl:` logical properties throughout; "Active" uses the Teal `brand` badge (never Signal Orange). Sidebar gains an "Ideal customers" entry.
- **i18n:** EN + AR bundles extended to parity with the new ICP copy.
- **Tests:** `IcpTest` (create + auto-activate + `IcpUpdated`, criteria order, activation deactivates the previous, update replaces criteria + re-emits, activate/delete flows + promotion, validation, index render, read-only forbidden, admin allowed, cross-tenant find 404 + policy isolation, criterion `team_id` auto-assign, API auth/list/CRUD/activate/delete) + `CriterionTypeTest`. Full suite 119 green; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass. Reviewed by the `laravel-module-reviewer` and `brand-reviewer` agents; their findings actioned.

### Added — 2026-10-29 — Phase 3: Onboarding & Business Profile

- **Onboarding module** (`app/Modules/Onboarding`): the guided, resumable wizard a new team completes and the stored **Business Profile** that feeds (later) the AI background check, ICP, scoring and outreach.
- **Data model:** `business_profiles` and `onboarding_progress` (both `BelongsToTeam`, one row per team). Money (`pricing_min`/`pricing_max`) stored as integer **minor units**; `version` bumps on each (re)completion.
- **Actions:** `SaveOnboardingStepAction` (resumable per-step save, money normalised to minor units by the chosen currency), `CompleteOnboardingAction` (guards all steps saved, stamps complete, applies the primary market to `teams.market`, dispatches the background check `afterCommit`), `GetOnboardingStateAction` (shared read for both controllers). Guard/reads/writes run in one locked transaction to make completion race-safe.
- **Background check:** `RequestBackgroundCheckJob` — a **stub** (`ShouldBeUnique` per team, `$uniqueFor`, `$tries/$backoff/$timeout`, `failed()`) that Phase 6 (Business Intelligence) will implement.
- **Enums:** `OnboardingStep`, `CompanySize`, `ServedRegion` (module) + cross-cutting `Market` and `Currency` (Core). No magic strings.
- **Web + API:** Inertia wizard (`GET /onboarding`, `POST /onboarding/step/{step}`, `POST /onboarding/complete`) guarded by `BusinessProfilePolicy` (view = any member; edit = Owner/Admin); read-only `GET /api/v1/onboarding` returning profile + progress via `OnboardingStateResource`.
- **Frontend (on brand, EN/AR + RTL, WCAG AA):** focused `OnboardingLayout` (Cloud Cream, logo, locale/appearance, no sidebar), a resumable wizard (`pages/onboarding/Wizard.vue`) with a step rail (`WizardProgress`) and a chip `ListInput`, a Dashboard first-run setup CTA (Ink panel + spectrum rule), and a sidebar "Business profile" entry. One orange full-stop accent; `rtl:font-bold` headings (Tajawal); RTL eyebrow letter-spacing reset in `app.css`.
- **i18n:** EN + AR bundles extended to full parity (141 keys each).
- **Demo data:** both demo teams get a completed Business Profile so demo logins land on the dashboard.
- **Tests:** `OnboardingWizardTest` (step save/resume, per-step validation, minor-unit conversion, completion applies market + dispatches the faked job, blocked completion, admin-allowed/read-only-forbidden, cross-tenant model + policy isolation, API auth/null/happy path, re-completion version bump) + `OnboardingStepTest`. Full suite 101 green; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass. Reviewed by the `laravel-module-reviewer` and `brand-reviewer` agents; their findings actioned.

### Added — 2026-10-29 — Public marketing pages, on-brand auth & light-theme default

- **Landing page** (`resources/js/pages/Welcome.vue`) rebuilt on brand: Cloud Cream canvas, Deep Teal structure, Midnight Ink inverse panels, a hero with the promise eyebrow + display headline (one decorative orange full-stop), a three-step "how it works" section, and a closing CTA band. Brand voice throughout; one hero accent per section.
- **About page** (`resources/js/pages/About.vue`, route `GET /about` → `about`) presenting the brand's four voice principles as values, the UK + UAE bilingual story, and a CTA.
- **Marketing shell** (`resources/js/layouts/MarketingLayout.vue`): sticky 88px header (logo, nav, locale toggle, one primary CTA), footer, and a spectrum signature rule — shared by the landing + about pages.
- **Spectrum gradient utility** (`resources/css/app.css` `bg-spectrum`) — the brand §1.4 gradient with its fixed stop order (Ink·Orange·Blue·Lilac·Lime·Teal·Cream), built from palette tokens.
- **On-brand auth redesign:** `AuthSplitLayout` rebuilt as a Midnight Ink brand panel (cream logo colourway — never Teal-on-Ink — tagline + spectrum rule) beside the Cloud Cream form column with a locale toggle; `AuthLayout` now uses it. `Login`/`Register` copy moved to brand voice; the login status message now uses the `success` token instead of a raw `text-green-600`.
- **Light theme is now the default** (`HandleAppearance.php`, `resources/views/app.blade.php`, `useAppearance.ts`): visitors land on the Cloud Cream theme unless they explicitly choose Dark or System.
- **i18n:** EN + AR bundles extended with all marketing/about copy (keys kept at parity; `LocaleTest` still green). Auth page titles/descriptions now translate through `t()`.
- **Brand-review follow-ups:** status colours moved off raw Tailwind palette values onto the `success`/`destructive` tokens (`InputError`, `DeleteUser`, `VerifyEmail`, `ForgotPassword`, `TwoFactorSetupModal`, settings `Profile`); logo lockups given a reliable accessible name (`role="img"`); new `inverse` Button variant (cream-on-Ink) replacing an ad-hoc override; a `tracking-eyebrow` theme token replacing arbitrary eyebrow tracking; `rtl:font-bold` on marketing headings (Tajawal has no 600 weight); one orange accent + one spectrum band per layout; a compact mobile menu in the marketing header.

### Added — 2026-10-28 — Phase 2: Brand design system & app shell (i18n/RTL)

- **Brand tokens in Tailwind v4** (`resources/css/app.css`): the full Alima palette (Deep Teal, Midnight Ink, Cloud Cream, Signal Orange + Electric Blue/Soft Lilac/Acid Lime, each with tints), semantic digital tokens mapped onto the shadcn component variables, brand `radius`/`shadow`/`motion`, and the Poppins / DM Mono / Tajawal type families. Fonts load from Bunny (GDPR-friendly). Lead-with-cream; **Signal Orange is decorative only — never a status colour** (status.* tokens carry state).
- **Dark theme** implemented from `brand-tokens.md` §7.1 — ⚠️ **PENDING brand-owner approval** (the book defines only `bg.inverse` sections); flagged in the CSS and `mvp.md`.
- **Shared token module** `resources/js/shared/tokens.ts` — the single TS source of truth for the palette/type/spacing/motion, ready for the Capacitor mobile app to import.
- **Base component library** (on brand, WCAG AA, keyboard-accessible): rebranded `Button` (pill, Ink primary / Teal `brand` / Teal link) and `Badge` (status variants from status tokens, decorative accent chips); new `Table` set and `EmptyState`; `Input`/`Toast`/`Dialog`/`Card` inherit the tokens. A living `/design` showcase page demonstrates them all.
- **App shell:** Alima wordmark + the approved **snout** mark (real panda master SVG still PENDING, §7.3), brand-coloured team switcher, a **locale toggle** and **appearance toggle** in the top bar and user menu; nav labels translated.
- **i18n (EN/AR + RTL, ADR 0007):** `SetLocale` middleware (cookie → active-team locale → default) on web + API; `Locale` support class; `POST /locale` switcher; `lang/en.json` + `lang/ar.json`; locale + `dir` + the active translation bundle shared via Inertia; `useI18n`, `useLocaleDirection` (keeps `<html lang/dir>` in sync across SPA visits) and `useFormat` (locale/timezone-aware numbers & dates) composables; Tajawal + 10–15% size boost under RTL; components use logical properties.
- **Realtime:** Laravel Echo over Reverb wired on the front end (`resources/js/echo.ts`, `laravel-echo` + `pusher-js`, `window.Echo`) using the existing `VITE_REVERB_*` env.
- **Tests:** `LocaleTest` (8) — default, cookie resolution + RTL, unsupported-locale fallback, team fallback, cookie-overrides-team, switch persists cookie, invalid-locale rejected, options metadata. Full suite green (79).

### Added — 2026-10-28 — Phase 1: Auth, teams, roles & invitations

- **Auth & Users module** (`app/Modules/Auth`): the full team-access layer.
- Enabled **spatie teams mode** (`config/permission.php` `teams => true`, `models.team => Team`); seed four global roles (`owner/admin/member/read-only`) via `TeamRolesSeeder`. A user's role is stored authoritatively on the `team_user` pivot and mirrored to a spatie team-scoped role by the `TeamMembership` support service.
- **Registration** now creates the user **and** their personal team (Owner) in one transaction (`CreateNewUser` → `CreatePersonalTeamAction`).
- **Teams:** create / update settings / switch (web + `/api/v1`), guarded by membership + `TeamPolicy`.
- **Invitations:** `team_invitations` table; invite / accept / resend / revoke Actions; temporary **signed** accept link; queued `TeamInvitationMail`; accepting joins the team with the invited role and switches into it.
- **Members:** role change + removal with a **last-owner** guard.
- **Sanctum tokens:** credential→token exchange for mobile (`POST /api/v1/auth/token`, 2FA accounts deferred to web), list/revoke (web settings + API), per-token abilities; global `/api/v1` rate limiting (`throttle:api`) + hard `auth-token` limiter.
- **Cross-cutting (Core):** `LogContext` sets spatie's team id per request; base `Controller` gains `AuthorizesRequests`; `DomainException` renders a friendly toast+redirect for web.
- **Frontend:** Settings → Team (members, roles, invitations, create team), Settings → API tokens, the invitation-accept page, and a sidebar **team switcher**; current team + team list shared via Inertia.
- **Tests:** registration→team+owner, 2FA challenge (existing), invite→accept, switch-team denied for non-members, cross-tenant access, token auth on a sample `/api/v1` route, membership sync, last-owner guard.

### Added — 2026-10-28 — Project bootstrap (foundation session)

- Scaffolded Laravel 13.39 + PHP 8.5 app with the official **Vue starter kit** (Inertia v2, Vue 3, TypeScript, Tailwind v4, Fortify auth, `vite-plus` toolchain), MySQL config.
- Installed & wired: Sanctum, Horizon, Reverb, Pennant, Pulse, spatie/laravel-permission, spatie/laravel-data, Sentry, Laravel Boost (dev). Horizon installed for Linux prod/CI via `config.platform` (can't run on Windows).
- **Core module** (`app/Modules/Core`): multi-team tenancy (`teams`, `team_user` pivot, `users.current_team_id`), `BelongsToTeam` trait + `TeamScope` global scope + `TeamContext`, `HasTeams` user trait, `AuditLog` + `AuditLogger`, `TeamRole` enum, `LogContext` middleware, base `DomainException`, base `ModuleServiceProvider` (route/policy auto-registration).
- App defaults: `Model::shouldBeStrict()` outside production; dedicated log channels `ai`, `lead_sourcing`, `billing`; documented `.env.example`.
- Composer scripts: `analyse` added; `dev` now runs server + queue + Reverb + Vite.
- Tests: tenancy scope (cross-tenant access fails), audit logger, TeamContext.
- **Documentation & knowledge base:** `CLAUDE.md`, `docs/architecture.md`, 20 module docs + template, ADRs 0001–0009, `docs/runbooks/{local-setup,deployment}.md`, `docs/compliance.md`, `docs/ai-prompts.md`, `docs/brand/brand-tokens.md`, and `mvp.md`.

### Added — 2026-10-28 — Project agents & skills

- Agents (`.claude/agents/`): `laravel-module-reviewer` (backend rule compliance), `brand-reviewer` (UI vs brand §2a).
- Skills (`.claude/skills/`): `new-module` (scaffold a module per ADR 0002), `quality-gates` (run gates with Herd binaries), `phase-complete` (phase definition-of-done). Registered in `CLAUDE.md §5a`.

### Decisions

- ADRs 0001–0009 (see `docs/decisions/`). Key kickoff answers: multi-team tenancy; UK + UAE markets (bilingual/RTL, GDPR+PECR+PDPL); Anthropic Claude default behind `AiClient`; providers Companies House + web search + Google Places (no paid enrichment in MVP).
