# Runbook — the admin panel

> For whoever is on support. Written for somebody who has not read the code. Phase 19a; the Phase 19b and 19c sections will be added when those ship.
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

**Your first visit will not show you the panel.** It will send you to the security page to set up two-factor authentication, because a platform operator must have it: one super-admin credential reads every tenant's data in the product, and a password alone is not the right lock on that. Set up your authenticator, then go back to `/admin`.

If you are developing and the TOTP dance is in your way, `ADMIN_REQUIRE_TWO_FACTOR=false` in `.env`. That is a local convenience and **not** a deployment option — production keeps it on.

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

A cross-tenant viewer with richer filters is Phase 19c. Until then the resource index does the job.

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
