# Runbook — the admin panel

> For whoever is on support. Written for somebody who has not read the code, and complete as of Phase 19c.
>
> Background, if you want it afterwards: `docs/modules/admin.md` and ADR 0014.

---

## 1. Getting in, the first time

The panel lives at **`/admin`**.

```bash
# Local: seed the operator account (and the demo tenancy).
C:/Users/alexa/.config/herd/bin/php.bat artisan migrate:fresh --seed

# Or just the operator, on a database you want to keep.
C:/Users/alexa/.config/herd/bin/php.bat artisan db:seed --class=SuperAdminSeeder
```

That gives you **`super@admin.com` / `password`** — a super admin who belongs to no team.

**Signing in takes you straight to the panel.** An operator belongs to no team, and the product's
dashboard needs one — so the sign-in, the "you are already signed in" redirect and the panel's own
header all send an account with a platform role and no team to `/admin`. (Before that was wired,
signing in as the operator landed on a **404** from the dashboard. If you are on an older checkout
and see that, this is what it was.)

**Your first visit will not show you the panel.** It will send you to **`/admin/security`** — the panel's own enrolment screen — because a platform operator must have two-factor authentication: one super-admin credential reads every tenant's data in the product, and a password alone is not the right lock on that. Scan the code, enter the six digits, and the panel opens. (Fortify asks you to confirm your password on the way, which is Laravel's own protection on arming a second factor, not a password reset.)

That screen used to be the **product's** `settings/security`, and that was a bug worth remembering: an operator belongs to no team, so the customer shell it sits in had a rail of team-scoped links that every answered 404. The panel asked for one thing and handed you a room where nothing worked.

If you are developing and the TOTP dance is in your way, `ADMIN_REQUIRE_TWO_FACTOR=false` in `.env`. That is a local convenience and **not** a deployment option — production keeps it on.

### If `/dashboard` gives you a 404

That is the product telling you that you are not a tenant, which for the seeded operator is
correct: it has no team, so there is no pipeline, no leads and no dashboard to render. Nothing in
the panel links there any more — the header offers **Sign out** rather than "Leave the panel" for an
account with no team. An operator who _does_ belong to a team (register normally, then be promoted,
which is the production advice) keeps both.

### If `/admin` gives you a 404

That is the panel telling you it is not for you, deliberately — a 403 would confirm there is something there. Three possible reasons:

1. **Your account has no platform role.** Check: `select email, admin_role from users where admin_role is not null;`. Only a super admin can grant one (and `admin_role` is not mass-assignable, so no form in the product can).
2. **You are impersonating somebody.** The panel closes while you are wearing a customer's account. Use the banner's **Stop impersonating** button.
3. **You are on the wrong network.** If `ADMIN_IP_ALLOWLIST` is set, requests from anywhere else get a 404 — and a `warning` line in the log naming the address.

### If you get a 403 inside the panel

Your role does not carry the ability that screen needs. The message names it. The mapping is in `config/admin.php` under `abilities`; the short version:

| Role          | Can                                                                 |
| ------------- | ------------------------------------------------------------------- |
| `super_admin` | Everything, including deletes and (from 19b) the platform switches. |
| `support`     | Read and fix records, account operations, impersonate. No deletes.  |
| `finance`     | Read, and billing. **Never impersonates.**                          |
| `read_only`   | Read. Nothing else.                                                 |

Two refusals will catch you out even as `support`, and both are deliberate:

- **The AI prompt registry** is super-admin only. Those rows are ours rather than a tenant's, and an
  edit reaches every customer's next AI call.
- **Account operations on another operator** are super-admin only. Clearing a colleague's second
  factor or revoking their sessions is not a support task. Ask a super admin.

---

## 2. Finding anything

Everything in the product is under **Resources** in the sidebar, grouped by what you are looking for rather than by our module boundaries. Forty resources; the ones you will actually use are **Tenants**, **Users**, **Leads**, **Discovery runs**, **Outreach messages** and **AI requests**.

Each index gives you a search box (where the resource has one), a **Tenant** filter, and whatever status filters make sense for that table. All of it is in the URL, so **paste the URL into the ticket** — whoever picks it up next sees exactly what you saw.

Two things to know:

- **Every read is recorded.** Opening a tenant's prospect list writes an audit entry with the resource, your filters and how many rows came back — never the rows themselves. That is on purpose: you are reading somebody else's customers' details, and that should leave a trail. It is not something to avoid; it is something to be unsurprised by.
- **Most resources are read-only, and the reason is on the screen.** If you cannot edit something, the descriptor for it explains why in the code — usually because a module owns the operation and doing it behind that module would skip a cascade, a suppression entry or an audit row the product depends on.

Timestamps in the panel are **UTC**, always. You belong to no team, so there is no team timezone to render them in, and rendering one tenant's times in another's would be worse than showing raw values.

---

## 3. Fixing a customer's record

Open the record, press **Edit**, change the fields offered, save.

Only the fields on the form can be changed — posting anything else is ignored, not an error. What you changed is audited by **field name**, not by value.

The ones you will reach for:

| Problem                                          | Where                                     |
| ------------------------------------------------ | ----------------------------------------- |
| Company name is mangled / wrong country          | **Leads** → the record → Edit             |
| Onboarding answers are wrong, so leads are wrong | **Business profiles** → Edit              |
| ICP weights are nonsense (usually a 100)         | **ICP criteria** → Edit the weight        |
| A signal watch is burning their allowance        | **Signal watches** → Edit → untick Active |
| A template has a broken placeholder              | **Outreach templates** → Edit             |
| A reminder is stuck in the past                  | **Lead tasks** → Edit the due date        |

**Delete only exists on three resources** — lead tags, saved views and push devices — because those are the only rows whose dependents cascade cleanly and that somebody needs removing. Everything else refuses, and says so.

---

## 4. Account operations

**Admin → Users → the person** (or click their name from the team page).

| Button                    | What it actually does                                                                      |
| ------------------------- | ------------------------------------------------------------------------------------------ |
| **Send a reset link**     | Emails them a password reset. We never set or read a password.                             |
| **Clear two-factor**      | Removes their secret so they can enrol a new device. For the handset that went in the sea. |
| **Sign out everywhere**   | Deletes their stored sessions and rotates the remember-me token.                           |
| **Revoke API tokens**     | Deletes every token. The lever for a key that has leaked.                                  |
| **Mark address verified** | For a verification mail that bounced off a corporate filter.                               |

Nothing here grants access. Every one of them either takes access away or invites the person to set it up again — which is why there is no field anywhere in the panel that looks like it could set a password or an email address.

### Team membership and ownership

**Admin → Teams → the team**. Change a role from the dropdown, or remove a member.

A customer whose only Owner has left the company gets their account back by promoting somebody to Owner here. Both operations go through Auth's own Actions, which **refuse to leave a team without an Owner** — so you cannot lock a tenant out from the screen whose job is unlocking them.

---

## 5. Suspending a tenant

**Admin → Teams → the team → Suspension.**

A reason is **required**, and at least ten characters, because a suspension nobody explained is one the next person on support cannot safely lift. "Abuse" is a category; "three spam complaints from the Acme campaign, ticket 412" is a reason.

What suspension does:

- ✅ They **keep signing in** and reading all their own data.
- ❌ They **cannot source** — discovery stops (quota 0).
- ❌ They **cannot spend** — AI calls stop (allowance 0).
- ❌ They **cannot send** — outreach is blocked, and the member is told the _account_ is suspended rather than that the prospect is restricted.

It is not a lockout, and that is deliberate: a lockout punishes the colleague who did nothing, and a tenant who cannot reach their own data cannot exercise their rights over it either.

It takes effect immediately, including for jobs already queued — the checks live in the guards, not in the request pipeline, so a scheduled watch due in ten minutes is stopped too.

**Lifting it** is one button, with an optional note. The original reason is **kept**: the account's history should show that it once stopped and why.

---

## 6. Impersonating a customer

**Admin → Users → the person → Sign in as them.** Confirm.

You now see the product exactly as they do, with an amber banner across every screen saying so and when the session ends.

### What you cannot do while impersonating

| Blocked                                            | Why                                                                                                                               |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Send outreach                                      | A message the customer did not write, from their domain, to a real person — recorded in their consent history as though they had. |
| Checkout, portal, plan change, cancel, resume      | It spends or stops their money. Do it as yourself, where the trail has your name.                                                 |
| Erase data, export the team                        | Irreversible, and the controller's decision. The controller is the tenant.                                                        |
| Export leads or reports to CSV                     | A copy on your laptop that we no longer control.                                                                                  |
| Passwords, email, two-factor, passkeys, API tokens | Changing any of them is how an impersonation becomes a way to keep the account.                                                   |
| Team settings, members, invitations                | Use the panel as yourself.                                                                                                        |
| The admin panel                                    | You are the customer now.                                                                                                         |

You will get a clear refusal, not a broken page. Reading everything is fine — that is the point.

### Getting out

The banner's **Stop impersonating** button. It works from anywhere, including from screens that would otherwise refuse you, because it is deliberately not an admin route.

If you forget, the session ends by itself after **60 minutes** (`ADMIN_IMPERSONATION_TTL`) and puts
you back as yourself. A forgotten impersonation is a customer's account left open in somebody's
browser.

**Do not use "log out" to get out.** It is blocked, and the reason is worth knowing: logging out
rotates the remember-me token of whoever is signed in — which, right then, is the customer. It would
sign them out of every device they had ticked "remember me" on. Stop impersonating, then log out if
you want to.

### What is on the record

The start and the stop are both audited against **the tenant**, so it shows up in their own audit history. And every write you make while impersonating carries your operator id in its audit entry — whichever part of the product performed it. You are never anonymous in somebody's account.

Two accounts you cannot impersonate: **your own** (you are already signed in) and **another operator's** (it would put their powers behind your session with their name on the trail).

---

## 7. Reading the audit trail

**Admin → Platform → Audit log.** Filter by action, actor, tenant, subject or window.

The actions worth knowing:

- `admin.resource.viewed` / `admin.record.viewed` — somebody read tenant data.
- `admin.record.created` / `.updated` / `.deleted` — a panel write.
- `admin.team.suspended` / `.restored`.
- `admin.user.*` — account operations.
- `admin.impersonation.started` / `.stopped` / `.expired`.
- `impersonated_by` in an entry's properties — that write was made inside an impersonated session.

The table is **append-only**: no edit, no delete, and the panel offers neither. An audit trail somebody can tidy up records only what they were willing to leave behind.

A cross-tenant viewer with categories, a window and richer filters is **§17** — use that for an audit question; the resource index is for looking a single record up.

---

## 8. Production notes

- **Set `ADMIN_IP_ALLOWLIST`** to the office or VPN range once there is one. CIDR works for IPv4 and IPv6, comma-separated. It is empty by default because an allowlist configured wrong locks the ops team out of the tool they would use to fix it.
- **Keep `ADMIN_REQUIRE_TWO_FACTOR=true`.**
- **`ADMIN_PATH`** moves the panel off `/admin`. Not a security measure — non-operators get a 404 wherever it lives — but it keeps it out of the logs of every scanner on the internet.
- **Never run `SuperAdminSeeder` in production.** It refuses, and needs both `ADMIN_SEED_ALLOW_PRODUCTION=true` and `--force` to be talked into it. Create real operator accounts instead: register normally, then have a super admin set the role.
- **`ADMIN_RATE_LIMIT`** (240/min per operator) is separate from the app's limiter, because paging a cross-tenant index legitimately makes far more requests than working one team — and because a compromised operator session is the one credential here where a scripted scrape would be worth writing.

## 9. Platform settings and the kill switches (19b)

`/admin/settings`, and `settings.manage` — super admin only, because every switch here is
platform-wide.

Each row says what the setting does **and what it does not**; read the second half. The Stripe switch
in particular does **not** change anybody's plan, which is the first thing people assume and the
assumption that stops them using it.

The page shows the pending changes in words before you save, and anything that switches something
**off** for every tenant takes a second press. Save applies the whole page in one transaction, so you
never end up with half of it in force.

**The kill switch list** (`capabilities.disabled`) takes capability names, one per line, and refuses
anything that is not one. That refusal matters: `signal` instead of `signals` used to save cleanly,
kill nothing, and leave you believing you had switched a broken feature off. The valid names are in
the row's help text.

The kill switch **beats per-team overrides**, by design. If a provider is down you switch the feature
off once, rather than hunting for every pilot customer who has an exception.

## 10. Putting a team on a plan without Stripe

`/admin/teams/{id}/billing`, and `billing.manage` — finance or super admin.

Two separate levers, and conflating them is the mistake to avoid:

- **Billing mode** (`stripe` / `manual` / `disabled`) stops checkout and usage metering. It **does
  not grant a plan.** A team you move to invoiced billing keeps whatever it already had, so without a
  grant it is on the default plan — and if it was trialling it drops there quietly when the trial
  ends. The toast will warn you.
- **A plan grant** is what entitles them. It outranks whatever Stripe says, carries its own
  seats/leads/credits for a contract the price list cannot express, and takes a note — put the
  contract reference in it, because that note is what the next person reads.

A grant replaces any other live or pending grant rather than stacking. Revoking one takes effect
immediately and can drop a contracted customer to the default plan, so it takes two clicks; the row
is kept, because what a tenant was entitled to last month is a question somebody asks.

The capability list on the same screen says, for every feature, whether it is on, **what the plan
alone would say**, and **which layer decided** — suspension, kill switch, override or plan. Use that
before changing anything: four things can decide a feature, and the badge tells you which one you are
actually fighting.

An override needs a reason and takes an optional expiry. Use the expiry. A goodwill exception that
never lapses becomes permanent because nobody remembered.

## 11. Onboarding a business after a sales call

`/admin/provisioning`, and `teams.manage`.

**Press "Check" first.** The dry run tells you what will happen, and the two things it catches are
the two that actually happen: a mistyped company name, and an owner address that already belongs to
an existing account — which would otherwise email a stranger into somebody else's business.

You cannot set a password, and there is no field for one. A **new** owner gets a password-reset link
after the team is created; an existing customer gets nothing, because they already know how to sign
in.

If you only hold `teams.manage` (support), the form refuses the billing-mode, plan-grant and
capability fields and tells you which ability you need. Provision on self-serve billing and ask
finance for the grant — it is one screen away and it is audited separately.

Anything you pre-fill lands the Owner on a half-filled onboarding wizard rather than skipping it. That
is deliberate: the wizard is where they confirm what somebody else wrote down about their business.

## 12. Telling tenants something

`/admin/announcements`, and `settings.manage`.

English is required because it is the fallback every reader gets; Arabic is optional, because an
urgent outage notice should not wait for a translation. A notice in one language beats silence in two.

`info` / `warning` / `critical` map onto the status palette. A critical notice is not dismissible by
default — take that seriously, because a tenant cannot clear it.

**Tick "notify" only when it is worth a notification in every targeted tenant's bell.** It writes one
per team and it cannot be taken back: the fan-out claims each tenant before sending, so a retry will
not tell anybody twice, and that also means there is no "undo and resend".

Times are **UTC**, in the labels and in the inputs. A window is the point: schedule Sunday's
maintenance notice on Thursday and it takes itself down.

"Take down" sets an end date rather than deleting the row — "when did we say the product would be
down?" is a question that comes up later.

**If a banner turns out to be wrong**, the fastest fix is `announcements.enabled` on the settings
screen. Every tenant stops seeing every announcement on their next request, which beats working out
which row to edit while people are reading it.

---

## 13. Reading the platform's numbers (19c)

**`/admin/reporting`**, and `reports.read` — every operator role has it.

Pick a window at the top; everything below re-reads against it and the URL carries it, so a link you paste into a conversation shows what you were looking at. **Windows are UTC**, because you are in no team and there is no team clock to read one in.

**Read the "Figures as at" date in the header first.** Every number on the page comes from a command that runs at 02:40, not from a live query — so if that date is not yesterday, the figures are not current and a quiet week may be a command that failed. See §15.

The thirteen tabs, and what each is actually for:

| Tab                     | The question it answers                                                                                                            |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Growth                  | Signups, trials, activations and conversions against the window before.                                                            |
| Onboarding funnel       | **Does the product's first hour work?** Of the tenants that signed up in this window, how many got as far as approaching somebody. |
| Revenue by plan         | MRR and ARR by plan, and how many tenants are paying.                                                                              |
| MRR movement            | Where the change came from — new, expansion, contraction, churn, reactivation.                                                     |
| Retention               | For each month's signups, how many ever paid and how many still do.                                                                |
| Allowance consumption   | How close tenants are to their limits. **The shape of this is what says a plan is priced or sized wrong.**                         |
| Provider health & spend | Calls, failures and cost per provider over the window.                                                                             |
| AI spend                | What we spent on AI, by model and by the work the call did.                                                                        |
| Cost per prospect       | What each provider cost us per company it found.                                                                                   |
| Gross margin            | **What each tenant pays against what it costs us.** Worst first.                                                                   |
| Margin by plan          | The same by plan — whether a plan is priced right.                                                                                 |
| Margin by cohort        | The same by signup month — whether the customers we are winning now are better or worse.                                           |
| Outreach                | Platform send volume and deliverability by channel.                                                                                |

**Every tab has a "How these are counted" panel under the table.** Read it before you quote a number at anybody. The figures look obvious and several of them are not — "activations" means a _member_ did something to a prospect, not that Alima scored one, and the funnel's window picks a signup cohort rather than a set of events.

Three things about the money, so you do not have to work them out from a surprise:

- **An em dash is not a zero.** A blank margin percentage means there was no revenue to divide by, not that the tenant broke even. A blank failure rate means nobody called that provider.
- **A row marked `*` has cost in a currency with no configured rate**, which makes its margin a _ceiling_ — the real figure is lower. A banner appears at the top of the page when that is true anywhere on it. Fix it by adding the rate to `admin.reporting.fx`.
- **An Enterprise tenant shows zero MRR.** Its contract price is not in the system, so the revenue report cannot know it. It still counts under "paying tenants" and in retention, which is what stops it vanishing.

**Export** gives you the open tab as CSV over the same window. Downloads are audited — who, which report, which window, how many rows — and never the contents.

---

## 14. "Is this customer worth having?" (19c)

Go to **Gross margin** and read from the top: the worst margins are first.

For one tenant specifically, use the window filter to pick a whole month and find them in the table. What you are looking at is their prorated subscription revenue for the window against what their AI calls and data lookups cost us in it.

If a tenant is losing money, the usual causes in order:

1. **They are on Free or a trial** and using the product properly. That is the plan working as designed; the row exists so you know the size of it.
2. **They are hammering AI** — check the AI spend tab for the prompt key, and their own usage on `/admin/teams/{id}/billing`.
3. **They are on an old price.** Check whether the plan they are on still costs what the price list says.
4. **Their allowance is sized wrong for the price.** The allowance-consumption tab is where that shows up as a shape rather than as one row.

The **unattributed** row at the bottom is platform spend with no tenant behind it — a backfill, a scheduled job, something run outside a tenant's context. It is deliberately never divided across customers, because that would flatter every margin equally and be impossible to notice.

---

## 15. A stuck queue, a failed job, a command that has not run (19c)

**`/admin/operations`**, and `operations.manage` — super admin and support.

### Failed jobs

The table shows the job class, **which tenant it belonged to**, the queue, when it failed and the first line of the exception. The tenant is the column nothing else can give you; it is dug out of the job's payload.

- **Retry** puts it back on its queue. Safe to do twice — the worst case is a duplicate attempt — and audited.
- **Drop** deletes it. **Irreversible**: the payload is the only copy of that work. It asks twice, and the audit entry is written before the row goes, so what was lost is on the record.
- **Retry all** does the lot. It asks twice too, because a hundred retries is a hundred real side effects.

Press **Escape** to back out of either confirmation.

If a job keeps failing, retrying it is not the fix — read the exception, fix the cause, then retry. A tenant id and a job class is usually enough to reproduce it.

### Queue depth

Pending work per queue, and **the oldest waiting job's age**. That age is the number that matters: a deep queue with a young oldest job is a busy worker, and a shallow queue with an hour-old job is a worker that is not running.

If the page says depth is not readable, this deployment is not on the database queue driver — Horizon or Pulse has it instead, and the page names the driver.

### Scheduled commands

Every scheduled command, when it is next due, and **when it was last seen running**.

"Last seen", not "last run": the panel records each run in the cache, because Laravel itself stores nothing about past scheduled runs. So:

- **"not seen"** can mean it has not run, or that the cache was cleared. Check `php artisan schedule:list` on the host if you need certainty.
- **"skipped"** means the previous run was still going. One skip is nothing; a command skipped every night is a job that is stuck, and it looks exactly like a healthy schedule unless you notice the word.
- **"failed"** shows the error message underneath.

The one to watch is **`platform:roll-up`** at 02:40. If it has not run, every figure in §13 is stale. Close a gap by hand:

```
php artisan platform:roll-up                   # yesterday
php artisan platform:roll-up --days=7          # the last seven days, oldest first
php artisan platform:roll-up --date=2026-10-01 # one specific UTC day
```

It is safe to run twice: it replaces a day's figures rather than adding to them. Two things to know about a backfill, both of which the command tells you as it goes:

- It walks **forwards**, because the MRR movement for a day is measured against the day before it.
- It **will not invent** dunning, seat or allowance figures for an old day, because nothing in the product recorded them — those stay as they were.

**It must stay scheduled before 03:20.** The nightly sweeps prune the rows the figures are computed from, and a period that has been swept cannot be recomputed.

---

## 16. A provider is failing (19c)

**`/admin/providers`**, and `reports.read` to look; `operations.manage` to reset.

Each provider shows its **circuit breaker** — live — and its error rate, latency and spend over the last seven complete days from the rollup. Those two disagree on purpose: the breaker tells you about this minute and the rates tell you about the week.

What "open" means: we have refused to call that provider for now, after several consecutive failures, so we stop hammering something that is already struggling. It closes itself after a cooldown of a minute or two. The card says what stops working for a tenant while it is open.

**Reset the breaker** when you have fixed the cause — a rotated key, a raised quota — and do not want the next batch of jobs refused on the strength of the old failures. It is audited, with whether the circuit was actually open. It does not fix the provider; it only means we will try again.

Three providers say **"not instrumented"**: Claude, Resend and Stripe have no breaker in the product. That is a fact about the code, not a gap in the screen — their health is the error rate beside them. Do not read the absence as "healthy".

The breaker lives in the cache, so clearing the cache closes every circuit. Worth knowing before you do it during an incident.

---

## 17. The audit trail, across tenants (19c)

**`/admin/audit`**, and `audit.read` — every operator role has it. This replaces the resource index for audit questions.

Filter by **category** first. It is there because `audit_logs` holds every tenant's ordinary activity — notes, stage moves, tags — and the entries you usually want are a handful in ten thousand:

- **Impersonation** — every session started, stopped or expired.
- **Operator reads** — somebody outside a business read their records.
- **Operator changes** — a panel write, a setting, an announcement.
- **Compliance**, **Billing**, **Outreach**, **Access and identity**, **Tenant activity**.

Then narrow by action, tenant, actor, subject or window. The window defaults to seven days, not thirty, because this table is the busiest in the product.

The **Details** column is what the writing code chose to record: ids, counts and masked previews. It never holds a copy of the record — a log of who read personal data must not itself be a copy of it. If you need to reproduce exactly what an operator saw, the URL is in the ticket.

Nothing here can be edited or deleted, and there is no route that could be. Reading the trail is the one cross-tenant read the panel does **not** audit, deliberately: otherwise every look would write a row to the thing you are looking at.

---

## 18. Compliance questions and signal runs (19c)

Both read-only, both cross-tenant.

**`/admin/compliance`** (`audit.read`) — retention sweeps, exports, erasures and assessments.

Start with the **assessments** table. Without a legitimate-interest assessment in force for its market, a tenant **cannot run discovery at all** — the gate refuses before a provider is called. So "discovery stopped working and nothing changed" is very often a lapsed row here, and the fix is the tenant's: they complete a new assessment in their own compliance centre. You cannot do it for them, and there is no button here that pretends otherwise. Same for an erasure or a retention policy: the tenant is the data controller, and that decision is theirs to make.

The **events** table shows what actually happened and when, with counts. A sweep has no actor, because nobody ran it.

**`/admin/signal-runs`** (`tenant.read`) — cost, failures and durations per signal run across tenants. Use it when a tenant says their signals have stopped: look for failed runs and read the error. A **blank duration** means the run never started or never finished, which is itself the finding.

There is no "run again" here, and that is deliberate: a run spends the tenant's own allowance at Serper and on the AI. They re-run their own watch from their own screen.

Both screens are audited as cross-tenant reads, because both show somebody else's records.

---

## 19. "Somebody must never be contacted again" (19c)

**`/admin/outreach`**, and `operations.manage` to add.

A **global suppression** is an address, domain or phone number **no tenant may contact** — a regulator, somebody who complained to us rather than to the tenant approaching them, a journalist who asked once.

It takes effect on the next send, in every tenant, with nothing else to switch on. And **it cannot be lifted**: there is no button, no route and no command anywhere in the product that removes a global entry. Removing one would mean overruling somebody's stated wish with a click. Be sure before you press it twice.

You will be asked for:

- **What it is** — an address, a whole domain ("stop contacting anyone at this company"), or a number. A pasted URL works as a domain.
- **Why** — they asked us to stop, they reported us, or you are adding it by hand. A bounce and an erasure are deliberately not offered: a bounce is one tenant's technical fact, and an erasure belongs to the tenant that received the request.
- **A note** — required. An entry nobody can explain is one the next operator cannot act on.

Only a **masked preview** is ever shown (`j***@example.com`), because only a hash is stored. That is not an inconvenience to work around; a register of people who want nothing to do with us is not a contact book.

### Reading the deliverability figures above it

A shared sending domain means **one tenant's behaviour is every tenant's deliverability**. Watch the bounce and opt-out rates per channel. A climbing complaint rate usually means one tenant is working a list they should not be, and the way to find them is the outreach tab in §13 plus their own `/admin/teams/{id}` page — then a conversation, or a suspension (§5) if it does not stop.

The "tenants' own entries" count next to the global one is each team's housekeeping — bounces, companies they handle offline. It is normally much larger and it is **not** a platform block list.

---
