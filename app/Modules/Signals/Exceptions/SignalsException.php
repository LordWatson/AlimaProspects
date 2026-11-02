<?php

declare(strict_types=1);

namespace App\Modules\Signals\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors for Signals, rendered centrally (bootstrap/app.php): a friendly message for the
 * user, a stable machine code for the API envelope. Each cause is a named constructor so the code is
 * set in one place.
 */
final class SignalsException extends DomainException
{
    protected string $errorCode = 'signals.invalid';

    /**
     * Whether a queued run that hit this error is worth retrying. A transient news-provider failure
     * is; a configuration error (missing key, wrong driver) never will be, so the S3 job fails fast
     * instead of burning its retries.
     */
    protected bool $retryable = false;

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * The news provider failed (timeout, 5xx/429, or a tripped circuit breaker). Transient —
     * retryable.
     */
    public static function newsProviderFailed(): self
    {
        $e = new self('We couldn’t reach the news service. Please try again shortly.');
        $e->errorCode = 'signals.news_provider_failed';
        $e->status = 502;
        $e->retryable = true;

        return $e;
    }

    /**
     * The news provider is missing required configuration (e.g. the Serper API key) — never
     * retryable.
     */
    public static function newsProviderMisconfigured(): self
    {
        $e = new self('Signals isn’t set up yet. An administrator needs to add the news service’s API key.');
        $e->errorCode = 'signals.news_provider_misconfigured';
        $e->status = 503;

        return $e;
    }

    /**
     * A news driver other than the supported one was configured — a config error, not transient.
     */
    public static function newsProviderUnavailable(): self
    {
        $e = new self('That news source isn’t available.');
        $e->errorCode = 'signals.news_provider_unavailable';
        $e->status = 503;

        return $e;
    }

    /**
     * The signal has already been converted into a lead, so it can't be converted again.
     */
    public static function alreadyConverted(): self
    {
        $e = new self('This signal has already been converted into a lead.');
        $e->errorCode = 'signals.already_converted';
        $e->status = 409;

        return $e;
    }

    /**
     * A run was requested for a watch that isn't active.
     */
    public static function watchInactive(): self
    {
        $e = new self('This watch is paused. Turn it back on before running it.');
        $e->errorCode = 'signals.watch_inactive';

        return $e;
    }

    /**
     * "Run now" was triggered again too soon (rate-limited per watch).
     */
    public static function runThrottled(int $seconds): self
    {
        $minutes = (int) ceil($seconds / 60);
        $e = new self("This watch was run recently. Try again in about {$minutes} minute(s).");
        $e->errorCode = 'signals.run_throttled';
        $e->status = 429;

        return $e;
    }

    /**
     * The team already has as many watches as the plan allows.
     *
     * A cap exists because a watch is a standing instruction to spend money: every active one runs
     * on its cadence and every run pays for Serper queries and an AI pass. Without a ceiling, a
     * script (or an enthusiastic member) can multiply a team's bill by creating watches rather
     * than by running them, which the per-watch run throttle does nothing about.
     */
    public static function tooManyWatches(int $max): self
    {
        $e = new self("You can have up to {$max} watches. Pause or remove one to add another.");
        $e->errorCode = 'signals.too_many_watches';
        $e->status = 422;

        return $e;
    }

    /**
     * A signal type is still attached to one or more watches, so removing it would quietly change
     * what those watches look for.
     *
     * The count is named rather than just "it's in use", because the next thing the member needs to
     * know is how much work detaching it is.
     */
    public static function definitionInUse(int $watches): self
    {
        $e = new self($watches === 1
            ? 'This signal type is used by one of your watches. Take it off that watch first, or pause the type instead.'
            : "This signal type is used by {$watches} of your watches. Take it off those watches first, or pause the type instead.");
        $e->errorCode = 'signals.definition_in_use';
        $e->status = 422;

        return $e;
    }

    /**
     * The team already has as many signal types as they are allowed.
     *
     * Same reasoning as the watch ceiling: a type is multiplied by every watch that uses it and by
     * every location S8 lets a watch cover, so each one is a standing instruction to spend money on
     * searches. Pausing is the way out, which is why the message says so.
     */
    public static function tooManyDefinitions(int $max): self
    {
        $e = new self("You can have up to {$max} signal types. Remove or pause one to add another.");
        $e->errorCode = 'signals.too_many_definitions';
        $e->status = 422;

        return $e;
    }

    /**
     * Someone tried to edit or delete a platform preset.
     *
     * A preset belongs to the product, not to a team — a team *clones* one and tunes their copy
     * (ADR 0018 §3). This is the policy's answer rendered for a client that asked anyway; the
     * message names the way forward rather than just refusing.
     */
    public static function presetNotEditable(): self
    {
        $e = new self('This is one of Alima’s standard signal types. Add it to your own types first, then tune your copy.');
        $e->errorCode = 'signals.preset_not_editable';
        $e->status = 422;

        return $e;
    }

    /**
     * A preset was asked for by a key the library doesn't have.
     */
    public static function unknownPreset(): self
    {
        $e = new self('That signal type isn’t in the library any more.');
        $e->errorCode = 'signals.unknown_preset';
        $e->status = 404;

        return $e;
    }

    /**
     * The composer could not produce a draft: no AI credit, or no schema-valid answer after a retry.
     *
     * One failure mode for both, deliberately. A member cannot act differently on "we are out of
     * credit" and "the model answered badly twice" — both mean *try again later, or write it
     * yourself* — and the library and the manual form are still there, which is what the message
     * says. The distinction is in the log, where somebody can do something about it.
     */
    public static function composerUnavailable(): self
    {
        $e = new self('Alima could not draft that one. Try describing the event in a single sentence, or add a type from the library.');
        $e->errorCode = 'signals.composer_unavailable';
        $e->status = 503;

        return $e;
    }

    /**
     * A preview was asked for and the team has spent its preview budget for today.
     *
     * **A refusal with a number in it**, because the alternative is a member pressing a button that
     * silently does nothing. The budget is a team-wide ceiling over the `signal_previews` ledger
     * (S9): per-member rate limiting cannot express it, since twelve members each inside their own
     * limit can still empty an allowance in an afternoon.
     */
    public static function previewBudgetSpent(): self
    {
        $e = new self('Your team has used its preview allowance for today. Previews reset tomorrow — you can still save this and try it on a watch.');
        $e->errorCode = 'signals.preview_budget_spent';
        $e->status = 429;

        return $e;
    }

    /**
     * A preview was asked for on a draft with nothing to search for.
     */
    public static function nothingToPreview(): self
    {
        $e = new self('Add at least one search template before previewing.');
        $e->errorCode = 'signals.nothing_to_preview';
        $e->status = 422;

        return $e;
    }

    /**
     * A team is at its watchlist ceiling.
     *
     * Every list is searched by every watch pointed at it and every entry on it is a paid search,
     * so the ceiling is on creating them as well as on their size — the same reasoning as the watch
     * and definition ceilings (S10).
     */
    public static function tooManyWatchlists(int $max): self
    {
        $e = new self("You can have up to {$max} watchlists. Remove one, or add these accounts to a list you already have.");
        $e->errorCode = 'signals.too_many_watchlists';
        $e->status = 422;

        return $e;
    }

    /**
     * Somebody tried to hand-edit a list a job maintains.
     *
     * A pipeline list's entries are an **output**: adding a name the next sync would delete is a
     * screen that argues with itself, so the message points at what to change instead.
     */
    public static function watchlistIsSynced(): self
    {
        $e = new self('This list mirrors your pipeline, so Alima keeps it up to date. Change which stages it follows, or start a list of your own.');
        $e->errorCode = 'signals.watchlist_is_synced';
        $e->status = 422;

        return $e;
    }

    /**
     * An "add to this lead" arrived for a signal with no lead to add it to.
     *
     * Reachable from a stale screen: the match was shown, somebody opened a second tab, and the
     * lead was deleted or anonymised in between. The member is told plainly rather than handed a
     * new lead they did not ask for.
     */
    public static function noLeadToAttachTo(): self
    {
        $e = new self('That lead is no longer there. Convert this signal into a new lead instead.');
        $e->errorCode = 'signals.no_lead_to_attach_to';
        $e->status = 422;

        return $e;
    }

    /**
     * A watch was saved naming no signal type that this team actually has.
     *
     * Distinct from a validation error on purpose: ids are dropped rather than rejected when they
     * belong to another team (the same treatment as an out-of-team ICP), so "none of them were
     * yours" has to be caught here rather than by a rule.
     */
    public static function noDefinitionsOnWatch(): self
    {
        $e = new self('Choose at least one signal type for this watch.');
        $e->errorCode = 'signals.no_definitions';

        return $e;
    }

    /**
     * The signal names no company to build a lead from, and none was supplied.
     */
    public static function noSubjectToConvert(): self
    {
        $e = new self('This signal has no company to convert. Add a company name — the person’s business or holding entity — so Alima knows who the lead is.');
        $e->errorCode = 'signals.no_subject';

        return $e;
    }
}
