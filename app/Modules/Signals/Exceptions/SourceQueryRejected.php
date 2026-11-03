<?php

declare(strict_types=1);

namespace App\Modules\Signals\Exceptions;

use App\Modules\Signals\Actions\RunSignalWatchAction;

/**
 * A signal source refused **this particular query** — a verdict about the query, not about the
 * source (S13a, ADR 0023 §4).
 *
 * **A class of its own rather than a code on {@see SignalsException}**, which is the correction the
 * S13a review forced. {@see RunSignalWatchAction::search()} is the one place in this module that
 * decides whether a provider failure is survivable, and it was making that decision by comparing
 * `errorCode()` against the string `'signals.source_query_rejected'`. That is a magic string
 * (CLAUDE.md §2) in the worst possible position: rename the code and the comparison silently stops
 * matching, at which point a refused query fails the whole run again — the exact defect this
 * exception exists to fix, reintroduced invisibly and with every test still passing.
 *
 * A typed `catch` cannot fail that way. The error code is unchanged, so the API envelope and the
 * tests that assert it are untouched; it is the *dispatch* that stopped being stringly typed.
 *
 * Everything else about it is {@see SignalsException::sourceQueryRejected()}'s docblock: not
 * retryable, the breaker stays shut, caught per query, counted under `rejected` on
 * `signal_runs.source_breakdown`, and the rest of the plan proceeds — **unless** the source refused
 * every query it was asked, which is systemic and fails the run
 * ({@see RunSignalWatchAction::assertSourcesAnswered()}).
 */
final class SourceQueryRejected extends SignalsException {}
