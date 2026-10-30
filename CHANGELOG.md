# Changelog

All notable changes to AlimaProspects are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); dates are ISO (UTC).

## [Unreleased]

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
