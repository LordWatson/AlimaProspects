<?php

declare(strict_types=1);

namespace App\Modules\Signals\Exceptions;

use App\Modules\Signals\Actions\RunSignalWatchAction;

/**
 * The thing a query was **addressed to** does not exist — a company number that is not on the
 * register, a feed whose address has been deleted (S13b review, findings 1 and 2).
 *
 * **The third meaning of a 4xx, and S13b is what revealed it.** ADR 0023 §4 split a 4xx two ways:
 * a breaker answers *is the provider up*, and {@see SourceQueryRejected} answers *was that a
 * sensible thing to ask*. Both of those questions are about **us** — our credential, our template,
 * our parameters — because at S13a every source was addressed by a search string we had composed.
 *
 * S13b added sources addressed by a **subject** (a company number) and by the **definition** (a
 * feed's URL), and for those a 404 is a verdict about neither the provider nor our query: the
 * request was well formed, the provider is healthy, and the answer is that the addressee is not
 * there. `leads.company_number` is unvalidated and companies get struck off; publishers move their
 * feeds. Both are ordinary states of a long-lived configuration, and the run must survive them.
 *
 * Before this existed, each arrived as `sourceFailed()` and **failed the whole run**, non-retryably,
 * with the news sources on the same watch discarded and already paid for. One stale number on a
 * 250-account watchlist did that on every run for ever, because the entity cursor only advances on
 * completion — so the same dead account was the first thing asked each time.
 *
 * Treating them as {@see SourceQueryRejected} instead would be wrong in the other direction: a feed
 * gets exactly **one** query per definition, so "refused a query" and "refused every query" are the
 * same event, and {@see RunSignalWatchAction::assertSourcesAnswered()} would fail the run anyway —
 * which is right for an exhausted Serper account and wrong for a 404 that will never change.
 *
 * So it is its own outcome: not retryable, the breaker stays **shut**, caught **per query**, counted
 * under `skipped` rather than `rejected` on `signal_runs.source_breakdown`, and deliberately
 * **excluded** from the "refused everything" rule. A source that only ever skips is visible as a
 * number on the run and on `/admin/signal-runs` without taking the rest of the plan down with it.
 */
final class SourceAddressNotFound extends SignalsException {}
