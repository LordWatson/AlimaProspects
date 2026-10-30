# Runbook — demoing Signals (UAE v1)

> For whoever is sitting in front of the Dubai real-estate client. Signals is the feature they
> asked for, and it is the one that is easiest to oversell — this is how to show it honestly.
> See `docs/modules/signals.md` for how it works and ADR 0011 for why it is built this way.

---

## 1. What you are showing them

**A feed of wealth and liquidity events in Dubai.** A company was sold, a founder exited, a
business raised a round, a family office opened an office in the DIFC. Alima finds those in
published news, has AI classify and summarise each one, scores how well it fits their business, and
lets them turn the good ones into leads with one click.

**Say this out loud early, because it manages the expectation that matters:**

> "This isn't a list of rich people. It's a list of _events_ — and an event is a reason to make a
> call this month rather than next year."

The distinction is the whole product. A search for "wealthy buyers in Dubai" returns property
portals, which are their competitors. A search for "Dubai founder exits in trade sale" returns
someone who has just had a liquidity event and may well be looking for somewhere to put it.

---

## 2. Before the meeting (10 minutes, the day before)

1. **Check the Serper key is set** — `WEB_SEARCH_API_KEY` in `.env`. Without it, every run fails
   fast with "Signals isn't set up yet"; you want to find that out now, not on screen.
2. **Check there is AI credit.** A team out of credit gets a clean Failed run saying so, which is
   correct behaviour and a terrible demo.
3. **Set up the watch and let it run overnight.** Signals → Watch settings → **"Watch Dubai for
   me"**. That creates the broadest useful watch: Dubai, all three event types, daily. The hourly
   scheduler picks it up within the hour.
4. **The next morning, read the feed yourself** and pick two or three signals you would be happy to
   be asked about. Know which article each came from.
5. **Dismiss the obvious noise**, with reasons. A feed with a couple of dismissed items is a more
   honest demo than a curated one, and it sets up section 5.

**Do not** create the watch live and press "Run now" expecting results in the meeting. A run takes
up to a couple of minutes, Serper caps `/news` at about ten items per query, and a quiet week is a
quiet week. Have yesterday's signals ready and use "Run now" only to show the live progress.

---

## 3. A good week looks like this

From the S0 spike and the UAE templates in `config/signals.php`:

| Event type        | Genuine events per week | What they look like                                      |
| ----------------- | ----------------------- | -------------------------------------------------------- |
| **Acquisition**   | 3–10                    | A UAE company acquired; a founder exiting in a sale      |
| **Funding round** | 3–10                    | Seed through late stage, usually with an amount          |
| **Relocation**    | 0–3                     | A founder or family office moving to Dubai; the thinnest |

So: **roughly 5–20 signals a week** for a Dubai watch across all three types, of which a team might
act on a handful. If you are seeing dozens, the relevance floor is too low; if you are seeing none
for several days, check the watch is active and the key is valid before assuming it is broken.

**Cost** is about **$0.001 per search query** plus the AI pass — pennies a day per team. The
per-watch spend is on the watch settings screen, so you can show it rather than claim it.

---

## 4. The demo, in order

1. **Signals → the feed.** Let them read two cards. Point at the event-type chip, the AI summary,
   and the relevance score. Do not read the cards to them; let them find one they recognise.
2. **Click through to the article.** This is the trust moment: every signal links to the published
   source. "Everything here came from somewhere you could have read yourself."
3. **Convert one.** Show the form prefilled from the article, change the company name to make the
   point that they are in control, add a note, convert.
4. **Land on the lead.** It is now an ordinary lead: Alima is already enriching and scoring it, and
   the **"The event"** panel on the right shows where it came from with a link back to the article.
   Say: "From here it is exactly like every other lead in the system."
5. **Point at the consent note on the lead** — see section 5. Do not skip it.
6. **Watch settings.** Show the cadence, the event-type checkboxes with their explainers, and the
   "sources to skip" box — _"this is where you put your competitors, so their blog stops turning up"_.
   Then **Run now**, and let them watch the progress badge.
7. **Dismiss one, with a reason.** "Telling us why is how next week gets better." Then restore it
   from the Dismissed tab, so they can see nothing is lost.

---

## 5. How to talk about compliance (do not improvise this)

The client is in the UAE, so **PDPL applies**, and the honest answer is a strength rather than an
apology. Use these three sentences:

> **"We work from published news."** Every signal is an article someone has already published. We
> don't buy data and we don't scrape anybody's private records.
>
> **"We store no contact details."** A signal holds a person's name and their public role, and
> that's it — no email, no phone number. There is nothing there to leak.
>
> **"We approach through LinkedIn or a referral."** UAE law wants the person's consent before you
> market to them directly, and a news article isn't consent. So Alima never suggests a cold email —
> every lead from a signal is flagged, and the Outreach tools honour that flag.

**If they ask "can't you just get me their email?"** — the answer is no, and here is why that is
the right answer: the flag that stops Alima cold-emailing a UAE prospect is the same flag that
keeps them out of trouble with the regulator. Offer the LinkedIn route and the referral route
instead, which is what actually works for this kind of prospect anyway.

**If they ask how long it is kept** — dismissed signals are deleted after 90 days and unactioned
ones after 180 (`signals:prune`, nightly). Converted ones stay as long as the lead does, because
they are the lead's provenance. If a named person ever asks what is held about them, there is a
per-team export and erase for exactly that (see `docs/modules/compliance-privacy.md`).

---

## 6. When it goes wrong, live

| What you see                                        | What it means                                                    | What to say / do                                                                                        |
| --------------------------------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| "Signals isn't set up yet"                          | No Serper key                                                    | Move on and fix it after; don't debug on screen.                                                        |
| "AI credit exhausted" on a Failed run               | The team is out of credit                                        | Top up. The run failed cleanly and recorded what it spent — no half-results.                            |
| "This watch was run recently"                       | The 15-minute per-watch throttle                                 | Expected. "A run costs money, so we don't let a button spend it twice." Show the existing signals.      |
| A Failed run with "couldn't reach the news service" | Serper timed out, or its circuit breaker is open                 | Transient. The queue retries; the watch backs off to its cadence rather than hammering hourly.          |
| No new signals for days                             | A quiet week, or the watch is paused                             | Check Watch settings first. Then say plainly that some weeks are quiet — that is what the data is like. |
| Lots of irrelevant signals                          | The relevance floor is too low, or a competitor keeps publishing | Raise `SIGNALS_MIN_RELEVANCE`, or add the domain to the watch's "sources to skip".                      |

---

## 7. What to promise, and what not to

**Safe to promise (it is built):** Dubai/UAE watches, daily or weekly; the three event types; the
feed with filters; convert-to-lead into the existing pipeline with enrichment and scoring; dismiss
with reasons; English and Arabic with full RTL; the API for their own tooling; retention and the
data-subject export.

**Do not promise:** UK coverage (post-v1 — Companies House streaming), Arabic-language _queries_
(the UI is Arabic; the searches are English in v1), full-article reading (v1 works from the
headline and snippet, so amounts are best-effort), a weekly email digest, more event types
(appointments, IPOs, DLD launches), or contact details. All of those are on the post-v1 backlog in
`signals-plan.md` — say "that's on the roadmap", not "we can do that".

**The one caveat worth volunteering**, because they will find it themselves: amounts and names come
from a headline and a snippet, so occasionally one is wrong or missing. That is why the convert form
lets them correct every field before the lead is created.
