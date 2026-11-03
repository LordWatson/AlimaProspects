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
   me"**. That adds Alima's three library signal types to the team and creates the broadest useful
   watch over them: Dubai, every type, daily. (Since S7 a watch is built from the team's **own**
   signal types — this button clones the library first so the shortest path stays one click.)
   The hourly
   scheduler picks it up within the hour.
4. **The next morning, read the feed yourself** and pick two or three signals you would be happy to
   be asked about. Know which article each came from.
5. **Dismiss the obvious noise**, with reasons. A feed with a couple of dismissed items is a more
   honest demo than a curated one, and it sets up section 5.
6. **On a feed that predates S11, run the backfill once** —
   `php artisan signals:cluster-backfill --dry-run` to see the damage, then without the flag.
   Clustering applies to signals found from now on, so an older feed opens on yesterday's eight
   cards about one acquisition and the feature looks like it does not work. It is idempotent, so
   running it again is free; it covers the newest 90 days and leaves converted signals as their own
   cards on purpose.

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
   and the **heat** on the first card. Do not read the cards to them; let them find one they
   recognise. The feed is in heat order by default, not date order, and the sentence is _"these are
   in the order we think they are worth your morning — and you can ask why"_.
2. **Open "Why you're seeing this" on the top card (S12).** This is the trust moment people
   remember, more than the article link. Eight components, what each was worth to _this_ score, and
   the evidence behind it: the amount measured **against their own history**, how many outlets
   carried it, what Alima has learned about that outlet, the fit against their ICP. Then point at a
   component that says it could not be judged — "no amount given" — and say the honest thing:
   _"where we do not know, we do not guess, and we do not score it against the signal either."_ If
   somebody asks whether they can change the weighting: yes, per signal type, and we will show them
   where in a minute.
3. **Click through to the article.** The other half of the same trust: every signal links to the
   published source. "Everything here came from somewhere you could have read yourself." If the link lands on
   a Google interstitial rather than the publisher, say so plainly — our provider sometimes hands
   back a redirect and it still reaches the article. Do not pretend not to have noticed.
4. **Open "Reported by N sources" on a card that has it (S11).** One card is one _event_, not one
   article, and this is where that lands: four to eight outlets, each a real link, behind one line.
   The sentence is _"your competitor's alert tool would have sent you eight emails about this"_ —
   and then, because it is the honest half, _"and we only paid to read one of them"_. Point at the
   tab counts while you are there: they count events too, so the number on the tab and the number
   of cards agree.
5. **Convert one.** Show the form prefilled from the article, change the company name to make the
   point that they are in control, add a note, convert.
6. **Land on the lead.** It is now an ordinary lead: Alima is already enriching and scoring it, and
   the **"The event"** panel on the right shows where it came from with a link back to the article.
   Say: "From here it is exactly like every other lead in the system."
7. **Point at the consent note on the lead** — see section 5. Do not skip it.
8. **Watch settings.** Show the cadence, the event-type checkboxes with their explainers, and the
   "sources to skip" box — _"this is where you put your competitors, so their blog stops turning up"_.
   Then **Run now**, and let them watch the progress badge.
9. **Dismiss one, with a reason.** "Telling us why is how next week gets better." Then restore it
   from the Dismissed tab, so they can see nothing is lost.
10. **Show the tuning panel on the watch settings screen, if the account has a suggestion (S12).**
    This is where the previous step pays off, out loud: _"you dismissed eleven of the twelve stories
    we showed you from that site, so we are asking whether to stop showing it"_ — with the numbers,
    and with **Accept** and **Not now**. Say the part that matters: **nothing tunes itself.** Alima
    proposes, they decide, and the change is recorded against their name like any other edit. A new
    account will have no suggestions yet; say so rather than hunting for one, and show the panel's
    empty state — "this fills up as you use it" is a true and good sentence.

---

## 4a. The builder (S9) — the part of the demo that closes

Everything above shows Alima doing what **we** decided to look for. This is the bit where it starts
looking for what **they** decided, live, in the meeting. Four minutes, and do it in this order.

1. **Signals → Signal types.** Scroll past your own types to **Alima's library** and press the chip
   for their trade. Read the audience line out — _"you place people, and you want to know who is
   about to need them"_. Five types appear. "These are not categories, they are searches we have
   already run."
2. **Ask them for a moment, in their words.** Not a keyword. Ask: _"what happens, in the world,
   just before someone needs you?"_ Type their answer into the box verbatim, typos and all. If they
   hesitate, press one of the examples — but their own sentence is worth four of ours.
3. **Press "Draft a signal type".** Read the draft **out loud** while it is on screen: the name, the
   searches, and especially _"what doesn't count"_. That exclusion list is the thing a keyword alert
   has never had, and it is where somebody recognises their own problem — "yes, it's always the
   weekly round-ups".
4. **Press "Show me what this finds" before you save.** This is the moment. Live news, their
   sentence, thirty seconds. Then walk the verdicts: the kept ones, and — more persuasively — the
   rejected ones with the reason beside each. _"This one is a real event in the wrong city. This one
   your threshold rejected. Nothing here is a black box."_
5. **Change one threshold and run it again.** Drop "how sure Alima must be" by twenty and watch two
   rejected cards turn into kept ones. Then say the line this whole feature exists for: **"you can
   tune this in the room, and you can see what the tuning did."**
6. **Save it, and add it to the watch.** Thirty seconds. They now own a signal type that did not
   exist when the meeting started.

**Three things to say while it runs, and nothing else.**

- **"We check it before you pay for it."** Every template in that library was run against live news
  before it shipped — eight of sixty-six found nothing and were rewritten. Say the number; it is the
  most credible sentence in the demo.
- **"The cost is on the screen."** Point at the line under the templates. A signal type is a search
  that runs every day, and Alima tells you what it costs while you are writing it.
- **"Three of them say 'Rare'."** Show one. _"That is us telling you a type finds less than the
  others. We would rather say so than have you discover it in a quiet week."_ Nothing earns trust
  faster than a product that marks down its own shelf.

**What not to do.** Do not pre-write the brief and paste it — the value is that their sentence
works. Do not preview the same draft five times; it costs money and the budget is per team per day,
which they may ask about (the answer: ten presses an hour each, a daily ceiling for the team, and
the ledger is in cost reporting). Do not promise the composer on the cheaper plans — the library and
the hand-written form are on every plan, the **AI drafting** is not.

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

**Safe to promise (it is built):** **describing a signal type in a sentence and previewing it
against live news before saving** (S9) — including the six vertical preset packs, every template of
which was checked against the provider first; watches over **several places at once** and over both the UAE
and the UK (S8) — so a client who sells into Dubai and London has one watch, not two — daily or
weekly; **signal types the client writes themselves** on `/signals/types`, or clones from Alima's
library and tunes (S7) — including their own confidence and relevance floors per type; a **UK
preset pack** of six types alongside the UAE three (S8), every template checked against live news
before it shipped; the feed with filters; convert-to-lead into the existing pipeline with enrichment
and scoring; dismiss with reasons; English and Arabic with full RTL; the API for their own tooling;
retention and the data-subject export.

**Do not promise:** AI drafting on the cheaper plans (the library and the hand-written form are
on every plan with Signals; the composer is not), previewing a whole watch rather than a sample from
one place, unlimited previews (ten an hour each, plus a daily team ceiling),
anywhere outside the UAE and the UK (Saudi, the US, India and Singapore are
written and deliberately switched off until the legal position for each is taken — say "not yet",
and do not offer a date), **Companies House filings** (the UK types read news, so a change of
control shows up when a journalist writes about it; the registry itself is S13), Arabic-language
_queries_ (the UI is Arabic and a watch can search Arabic-language news, but the search terms
themselves are English until the templates are written by a native speaker), full-article reading
(signals come from the headline and snippet, so amounts are best-effort), a weekly email digest,
or contact details. Those are on the plan in `signals-v2-plan.md` — say "that's on the roadmap",
not "we can do that".

**If they ask about multiple countries, say the honest version**, because it is the better story: a
watch's places decide where Alima looks, and a lead's **own** country decides how they are allowed
to approach that person (S8, ADR 0019). A Dubai founder found by a London watch still comes with
"get consent first — reach out on LinkedIn or through a referral", because the rule follows the
person and not the search. That is a thing a competitor's keyword alert does not do.

**The one caveat worth volunteering**, because they will find it themselves: amounts and names come
from a headline and a snippet, so occasionally one is wrong or missing. That is why the convert form
lets them correct every field before the lead is created.
