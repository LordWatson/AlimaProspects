# 0033 — Leads leave in a file

- **Status:** Accepted
- **Date:** 2026-11-08
- **Phase:** PL6 (`pre-launch.md`)
- **Supersedes / amends:** nothing. Extends Phase 10b's streamed export and binds PL4's metering and
  PL5's suppression list to it.

## Context

Phase 10b shipped an export: `GET /leads/export`, streamed, chunked, BOM'd, CSV-injection-proofed,
and with the same column set the importer reads so a team can round-trip their list through a
spreadsheet. Everything in that sentence stays true.

What it could not do is everything PL6's customers actually ask for. There is one column set, so a
file for a CRM and a file for Alima are the same file and suit neither. It writes whatever is in the
database, which after PL2 includes addresses a vendor was paid for and nobody has revealed, and after
PL5 includes people who have asked us to stop. It streams, so a six-figure export is a request that
dies at a PHP timeout. And it is free, while `BillingMetric::ExportRows` has existed since PL4 with
an allowance on every plan that nothing has ever spent.

`docs/prelaunch/recon.md` binds this phase: extend `LeadCsvExporter`, keep the round trip working,
honour the suppression through PL5's checker, meter under PL4.

## Decisions

### 1. A preset is a list of column keys, and that is the whole of it

Every row is built as a keyed map of **every** column the exporter can produce, then projected onto
the preset's list of keys. A preset therefore holds no logic — `ExportPreset::columns()` returns an
array of strings — and three things follow for free:

- **"Choose the columns" costs nothing.** A custom set is the same list, arriving from the request
  and validated against `LeadCsvExporter::ALL_COLUMNS`. The plan asked for a fourth preset and we
  did not have to build one.
- **A column cannot mean two things.** A preset that built its own cells would be the place where
  `contact_phone` quietly became the raw number in one file and E.164 in another.
- **`COLUMNS` can stay frozen.** The importable set is untouched, in its original order, and the
  round-trip test that ends in two merges and zero new leads still passes without being rewritten.

The CRM-ready preset is the one that changes the **shape** rather than the columns: one row per
contact with the company repeated, because that is what a CRM's importer expects and a team
otherwise does it with a spreadsheet formula. A lead with no contacts is still one row — a company
worth exporting is worth exporting before anybody has found a person there.

### 2. Three things must not reach the file, and only one of them is the suppression list

- **A value nobody has paid to see is written as an empty cell, never as the masked form.**
  `j***@acme.com` in a spreadsheet is worse than a blank, because something downstream will
  eventually try to send to it. `LeadContact::requiresEmailReveal()` already answers who was paid
  (ADR 0029), so the rule is one condition rather than a new column.
- **A suppressed company is left out of the file altogether, and counted.** This is the one place
  "we honour the list at the point of sending" is not good enough: once a row is in a spreadsheet on
  somebody's laptop, no later opt-out can reach it. It needed a third question on the seam —
  `ContactApprovalGate::allowsDomain()` — because the unit leaving the building here is a company
  and the gate's other two questions are about a person. It reads `SuppressionList` like the other
  two; there is still exactly one do-not-contact table.
- **A suppressed value is blanked even where the company is fine.** The company is not the address.
  The row stays and the cell goes, and that case is deliberately **not** counted as a suppressed
  company, because the figure a member reconciles a row count against must mean one thing.

**Both of those rules apply to the Phase 10b export as well**, and that is deliberate rather than
an oversight in the flag. They live in `LeadCsvExporter`, which both paths share, so a team without
`prelaunch.export` also stops exporting unrevealed addresses and suppressed companies. A flag exists
to hide an unfinished feature, not to keep a defect alive: the old export was handing out addresses
PL2 charges to reveal and companies PL5's list says to leave alone, and gating the fix would have
meant writing a second, worse exporter and keeping it. What the old path does **not** gain is the
receipt — it streams, so there is no row to carry `suppressed_count`, and a team on the old export
is told nothing about the companies it left out. That is the argument for turning the flag on, not
for leaving the values in.

### 3. The export is asked of the seam, and a test asserts that rather than the behaviour

`LeadCsvExporter` takes `ContactApprovalGate` in its constructor and
`tests/Feature/Compliance/ContactApprovalGateTest` asserts it, in the list PL5 left open for
"PL6's export and PL7's sync". A behaviour test per screen only covers the screens somebody
remembered to write one for; a path that never asks the gate is a path whose own test would not
notice.

### 4. Up to 500 rows a member waits; past that a worker builds it

One generator, one `GenerateLeadExportAction`, called from the request for a small export and from
`GenerateLeadExportJob` for a large one. Two code paths writing CSV would be two places the
escaping, the BOM or the suppression rule could differ, and the one that differed would be the one
nobody tested.

The threshold is `leads-workspace.export.sync_max_rows`, because a member who clicked Export wants a
file and not a promise — and because a request that streams for four minutes is a request that dies
at a proxy timeout with half a file delivered.

### 5. The allowance is held when the member asks, and settled against what was written

`LeadsWorkspace\Contracts\ExportRowAllowance` is the **sixth** seam of ADR 0012's kind, bound by
Billing over the module's own permissive default. Reserve, then commit, then release the rest —
PL3's shape, for a related but different reason: an export costs us nothing per row, so this is not
a cost guard, it is the promise `BillingMetric::ExportRows` has made since PL4 that an export
allowance is a monthly ceiling with no overage and nothing to buy.

It is bound **inside** `billing.enforce_plans`, unlike PL4's `CreditReserver`, and that asymmetry is
the point: a reveal is money leaving the building per unit, so its reserve stands whatever the switch
says; a row of a team's own data leaving is not.

Reserved at the request rather than in the worker, because a refusal a member reads while still
looking at the screen is worth far more than one that arrives as a failed notification ten minutes
later — and because two large exports asked for at once must not both be told there is room. The
estimate for the CRM preset counts contacts and contactless leads rather than guessing, since
`UsageAllowance::commit()` clamps what is settled to what was held: an estimate too low would
quietly under-meter rather than fail, which is the kind of billing bug nobody notices for a quarter.

### 6. The file is addressed by a row id, not by a path, and the link is not signed

`lead_exports.path` is never a route parameter. The download is `GET /leads/exports/{export}/download`,
and it checks three things in order: the row's `team_id` against the current team (404), the same
`LeadPolicy::export` that produced it, and the row's own `expires_at`.

The plan asked for a signed URL good for 24 hours. What shipped is **narrower**: the link needs the
member's own session as well as the row's expiry. A signature is for a door with no login behind it
— PL5's verification link is the example — and adding one here would only make the URL in a
notification unopenable from a different browser. The 24-hour figure also contradicted the plan's own
seven-day retention.

The download is deliberately **not** behind `capability:csv_export`, although creating one is:
refusing somebody their own finished file because a plan changed on Tuesday is punishing them for
our timing, and the rows have already been spent.

### 7. The file has a deletion deadline, and deleting it comes before deleting the row

`leads:prune-exports` runs nightly and deletes the file first, the row second. A crash between them
leaves a row pointing at nothing — which the download answers honestly, as "that export has expired"
— rather than a file nothing knows about, which nothing will ever delete.

`retain_days` is a deadline rather than a quota. An export file is a team's whole lead list sitting
outside the database's own retention sweeps, and the one that is still there in two years is the one
that is still there when a bucket is misconfigured.

### 8. "Ready to download" is its own notification, and it cannot be switched off

The only notification in the product that carries a link that expires, and the only one a member is
certainly waiting for. It is not configurable because it is the **only place the download link
appears** for a queued export: a member who switched it off would start exports and never be given
the files. The way not to receive one is not to ask for an export.

Its payload is counts and an id, as every payload is — never a cell of the file.

### 9. The receipt is a list on the screen, not a toast

`lead_exports` holds `row_count` and `suppressed_count`, and the export dialog lists the team's last
five. That list is where a member learns that 415 leads produced 412 rows because three companies
are on their do-not-contact list, where a queued export lands, and where somebody comes back for a
file whose email they have deleted. A toast could not be any of those, because a browser downloading
an attachment never navigates and a flashed message would surface at whatever moment they next
happened to click something.

### 10. Giving up releases the hold, in one method, from both paths

`GenerateLeadExportAction::fail()` releases the reservation and marks the row, and it is called by
the job's `failed()` **and** by the request — which never reaches a job's `failed()` at all. A full
disk is a 500 either way; it must not also be a billing problem, because a hold nobody settles sits
against the allowance until the month turns over and the member's retry holds again. A handful of
failures would lock a team out of exporting for weeks over files they never received.

It leaves a `Ready` row alone, because a worker killed after the file was written and the allowance
settled still reports a failure, and flipping that row would take away a file the member can
actually download. For the same family of reasons the file path is **reused** on a retry rather than
regenerated: a file no row points at is a team's whole lead list that the prune will never find, and
the prune keeps a row whose file it could not delete for exactly that reason.

## Consequences

- One more seam for Billing to fill, and the pattern is now used six times. The sixth was the
  cheapest, which is the argument for the pattern.
- `ContactApprovalGate` has three methods. Both implementations and the one anonymous test double
  were updated; the permissive default still permits everything.
- Two export paths exist while `prelaunch.export` is off, and the flag is deleted with the old one
  (ADR 0026 §4).
- A CRM-ready export of a list with many contacts spends more allowance than the lead count
  suggests. That is correct — it is more rows — but a plan's `export_rows` figure was written
  thinking in leads, and the figures want revisiting before launch.
- **The metering under-counts in one stated way, in the customer's favour.** The rows are held when
  the member asks and the file may be built minutes later, so leads added in between produce rows
  nobody held — and `UsageAllowance::commit()` clamps what is settled to what was held, so those
  rows are free rather than an error. Recorded rather than fixed: an exported row costs us nothing,
  and the alternatives are a second count after the walk (two passes over the list) or truncating a
  member's file at the reserved number, which is worse than the problem.
- **A company suppressed by its registration number is still exported.** `SuppressionType::CompanyNumber`
  arrived in PL5 and **nothing in the product reads it yet** — not `OutreachGuard`, not the gate —
  so the export is consistent with the rest rather than uniquely wrong. Fixing it belongs with
  whatever first consults that type, since the answer has to be the same for a send, a sync and a
  file. Logged in `docs/prelaunch/progress.md`.

## Alternatives considered

- **An `exports` table, as the plan names it.** Called `lead_exports`, because PL10's sample report
  and a later analytics export will want their own and a table called `exports` would end up
  holding three unrelated things keyed by a `type` column.
- **A signed URL.** Weaker than what shipped; see §6.
- **A "reveal all before export (est. X credits)" button.** Not built. It is a bulk spend started
  from a dialog about something else, and the one place in the product where a mis-click costs real
  money. The enrichment screens already do bulk reveals with their own confirmation and their own
  estimate (PL3), and the export's job is to be honest about what it has rather than to sell more.
- **An export history screen of its own.** Five rows in the dialog, instead. A screen for a thing a
  team does twice a month, whose rows are deleted after a week, is a screen with nothing on it.
- **Batching the suppression check per chunk.** One indexed lookup per lead instead, noted as a
  shortcut in the code. At the sizes a 500-row request and a queued job deal with it is not worth a
  second query shape; past six figures of leads it is.
- **Metering the export in the job.** Rejected in §5: the refusal has to reach the member while
  they are still there.
