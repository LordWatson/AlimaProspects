<?php

declare(strict_types=1);

namespace App\Modules\Signals\Exceptions;

use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\Signals\Services\SignalSpendGuard;

/**
 * The team's plan, or an allowance on it, says no (S16 task 7).
 *
 * **A type, not a message, and the reason is the error tracker.** `sentry.ignore_exceptions` matches
 * by `instanceof`, and every refusal in this group is the application *working*: a team has used
 * this month's searches, or their plan does not include automations. Reporting those trains an ops
 * team to ignore Sentry, which is the one failure mode that makes every other alert useless
 * (ADR 0017). The base {@see SignalsException} cannot go on that list — a source that returned
 * something unreadable is a domain exception too, and that one we very much want to be told about
 * — so the refusals needed a class of their own to be filtered by.
 *
 * The precedent is {@see AiCreditExceededException}, which has been on that list since Phase 20 for
 * exactly this reason, and the mechanism is the one `SourceQueryRejected` already uses one level
 * along: subclass so the *type* carries the distinction, rather than branching on an error-code
 * string somewhere that has to remember to.
 *
 * Everything here is raised by {@see SignalSpendGuard} or by a save path asking it, and every one of
 * them is built by a named constructor on {@see SignalsException} so the codes and the wording stay
 * in one file. It covers both shapes of refusal:
 *
 * - **A standing ceiling**: too many types, watches, watchlists, feeds or automations. A 422, and
 *   the member's way out is to remove one or change plan.
 * - **A spent allowance**: this month's searches or previews, today's preview budget, this month's
 *   article reads. A 429, and the way out is to wait or change plan.
 *
 * What is deliberately **not** here: `sourceUnavailable`, `sourceFailed`, `sourceMisconfigured` and
 * the three addressing refusals. Those are statements about a provider or about our own
 * configuration, and the whole value of this split is that the tracker still shows them.
 */
final class SignalAllowanceExceeded extends SignalsException {}
