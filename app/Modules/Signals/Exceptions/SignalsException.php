<?php

declare(strict_types=1);

namespace App\Modules\Signals\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors for Signals, rendered centrally (bootstrap/app.php): a friendly message for the
 * user, a stable machine code for the API envelope. Each cause is a named constructor so the code is
 * set in one place.
 */
/*
 * **Not `final`, and that is the only reason it is not.** Every other module's domain exception in
 * this codebase is, and so was this one until S13a. `SourceQueryRejected` subclasses it so the run
 * can dispatch on a *type* rather than on an error-code string in the one place that decides
 * whether a provider failure is survivable (ADR 0023 §4) — and subclassing rather than branching is
 * what keeps `catch (SignalsException)` elsewhere, such as the preview's, working unchanged.
 */
class SignalsException extends DomainException
{
    protected string $errorCode = 'signals.invalid';

    /**
     * Whether a queued run that hit this error is worth retrying. A transient news-provider failure
     * is; a configuration error (missing key, wrong driver) never will be, so the S3 job fails fast
     * instead of burning its retries.
     */
    protected bool $retryable = false;

    /**
     * Which signal source this error is about (S13), or null for an error that is not about one.
     *
     * @see self::source()
     */
    protected ?string $source = null;

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * A signal source failed transiently (timeout, 5xx/429, or a tripped circuit breaker) —
     * **retryable**, so a queued run bubbles it and the job retries with backoff.
     *
     * Renamed from `newsProviderFailed()` at S13 (ADR 0023): there is more than one source now, and
     * the message a member reads should not say "news" when their web-search source is the one that
     * is unwell.
     */
    public static function sourceUnavailable(string $source = 'news'): self
    {
        $e = new self('We couldn’t reach the '.self::sourceLabel($source).'. Please try again shortly.');
        $e->errorCode = 'signals.source_unavailable';
        $e->status = 502;
        $e->retryable = true;
        $e->source = $source;

        return $e;
    }

    /**
     * A signal source answered, and the answer was unusable: a malformed 200, an HTML error page
     * behind a success status, a 401/403, a 404 on the endpoint.
     *
     * **Not retryable, and the breaker stays shut.** Every case here is ours — a wrong key, a wrong
     * endpoint, a provider changing its payload — and none of them is evidence the source is down,
     * so opening the circuit for every team would be the wrong conclusion drawn loudly.
     */
    public static function sourceFailed(string $source = 'news'): self
    {
        $e = new self('The '.self::sourceLabel($source).' returned something we couldn’t read.');
        $e->errorCode = 'signals.source_failed';
        $e->status = 502;
        $e->source = $source;

        return $e;
    }

    /**
     * A source refused **this particular query** — a verdict about the query, not about the source
     * (ADR 0023 §4).
     *
     * Serper answers a `site:` or quoted-phrase pattern with `HTTP 400 "Query pattern not allowed
     * for free accounts."`, which the S13a spike found. Before S13 that raised the retryable failure
     * above, so one template a member had typed with a quote in it failed their whole watch, three
     * times over, re-paying for every other query in the plan each time — and reported that we
     * couldn't reach the news service, which was untrue and unfixable by them.
     *
     * So it is **not retryable**, it does **not** trip the breaker, and `RunSignalWatchAction`
     * catches it **per query**: the query is counted as rejected in the run's source breakdown and
     * the rest of the plan proceeds. A run whose every query was rejected completes having found
     * nothing, rather than failing — nothing is broken; we asked badly.
     */
    public static function sourceQueryRejected(string $source = 'news'): SourceQueryRejected
    {
        // The **subclass**, so `RunSignalWatchAction` can catch it by type. Still built here, so the
        // error code and the message stay where every other failure mode's are.
        $e = new SourceQueryRejected('The '.self::sourceLabel($source).' wouldn’t accept one of your search templates. Try plain keywords, without quotation marks or `site:`.');
        $e->errorCode = 'signals.source_query_rejected';
        $e->status = 422;
        $e->source = $source;

        return $e;
    }

    /**
     * What a query was addressed to is not there — an unknown company number, a deleted feed
     * (S13b review, findings 1 and 2).
     *
     * The long version is {@see SourceAddressNotFound}'s docblock. In short: not retryable, the
     * breaker stays shut, caught **per query**, counted under `skipped` rather than `rejected`, and
     * excluded from the "refused everything" rule — because a feed is asked exactly one query, so
     * for it those two are the same event, and failing a run over a 404 that will never change is
     * how one dead address took a whole watch down on every run.
     *
     * The message names no company and no URL. It is rendered to whoever triggered the run, and the
     * run may have been scheduled rather than asked for; the addressee is on the breakdown.
     */
    public static function sourceAddressNotFound(string $source = 'news'): SourceAddressNotFound
    {
        // The **subclass**, for the same reason `sourceQueryRejected()` builds one: the run catches
        // it by type, and the code and message stay where every other failure mode's are.
        $e = new SourceAddressNotFound('The '.self::sourceLabel($source).' has nothing at one of the addresses this watch asks about. That query was skipped.');
        $e->errorCode = 'signals.source_address_not_found';
        $e->status = 422;
        $e->source = $source;

        return $e;
    }

    /**
     * A signal type handed to an Action is not the acting team's own (S13b review).
     *
     * The controllers resolve a definition **within** the team before calling anything, so this is
     * a guard and not a user-facing condition. It exists because the one Action that mints a
     * credential did `updateOrCreate` on `(team_id, signal_definition_id)`: handed a foreign
     * definition it would have *created* a row pairing this team with somebody else's type, which
     * is the one shape of tenancy bug a global scope cannot catch — the write is correctly scoped
     * and still wrong. CLAUDE.md's "policies double-check ownership", one layer in.
     */
    public static function definitionNotYours(): self
    {
        $e = new self('That signal type isn’t yours.');
        $e->errorCode = 'signals.definition_not_yours';
        $e->status = 403;

        return $e;
    }

    /**
     * A source is missing required configuration (e.g. the Serper API key) — never retryable, so the
     * job fails fast instead of burning its retries on a request that can never succeed.
     */
    public static function sourceMisconfigured(string $source = 'news'): self
    {
        $e = new self('Signals isn’t set up yet. An administrator needs to add the '.self::sourceLabel($source).'’s API key.');
        $e->errorCode = 'signals.source_misconfigured';
        $e->status = 503;
        $e->source = $source;

        return $e;
    }

    /**
     * A driver other than the supported one was configured for a source — a config error, not
     * transient.
     */
    public static function sourceDriverUnsupported(string $source = 'news'): self
    {
        $e = new self('That signal source isn’t available.');
        $e->errorCode = 'signals.source_driver_unsupported';
        $e->status = 503;
        $e->source = $source;

        return $e;
    }

    /**
     * An inbound event could not be accepted (S13b, ADR 0023 §7).
     *
     * **One answer for every reason**, and that is the security property rather than laziness: a bad
     * signature, an unknown signal-type key, a stale timestamp and a type belonging to another team
     * all produce this. The differences are an oracle — "that key exists but the signature is wrong"
     * is how somebody enumerates another tenant's signal types, and "your timestamp is stale" tells
     * a replayer exactly what to fix.
     *
     * Raised only where a refusal has escaped the request's `authorize()` (which answers 403 on its
     * own), so in practice this is the guard on `definition()` being read without verification.
     */
    public static function webhookRefused(): self
    {
        $e = new self('That event could not be accepted.');
        $e->errorCode = 'signals.webhook_refused';
        $e->status = 403;

        return $e;
    }

    /**
     * The team already has as many feeds as their plan allows (S13b).
     *
     * A ceiling on **creating** them, like the watch and type ceilings and for the same reason: each
     * feed is a standing outbound request from our infrastructure to an address a customer chose,
     * and polite rate limiting bounds how often one is fetched rather than how many exist.
     */
    public static function tooManyFeeds(int $max): self
    {
        $e = new self('You can watch up to '.$max.' feeds. Remove one from another signal type first.');
        $e->errorCode = 'signals.too_many_feeds';
        $e->status = 422;

        return $e;
    }

    /**
     * No provider is registered for a source key (S13) — the registry's refusal.
     *
     * Raised where a *member* named the source, so it is worth telling them. A **run** meeting a
     * stored key with no provider asks `SignalSourceRegistry::find()` instead and skips it: a source
     * withdrawn between deploys, or one whose credential was pulled, must not crash a scheduled
     * watch at three in the morning.
     */
    public static function unknownSource(string $source): self
    {
        $e = new self('That signal source isn’t available.');
        $e->errorCode = 'signals.unknown_source';
        $e->status = 422;
        $e->source = $source;

        return $e;
    }

    /**
     * The source this error is about, for the run's per-source breakdown.
     *
     * A field rather than something parsed back out of the message, because the breakdown keys on it
     * and a translated or reworded sentence must not be able to move a cost onto the wrong row.
     */
    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * A source's name as a sentence reads it. Deliberately not `SignalSourceKind::label()`: that is
     * a form label ("Web search") and this is the middle of a sentence ("the web-search service"),
     * and a key this build does not know still has to produce a readable line — which is why the
     * parameter is a string rather than the enum.
     */
    private static function sourceLabel(string $source): string
    {
        return match ($source) {
            'news' => 'news service',
            'web_search' => 'web-search service',
            'companies_house' => 'Companies House service',
            'feed' => 'feed',
            'webhook' => 'events API',
            default => 'signal source',
        };
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
     * The team already has as many playbooks as they are allowed (S14).
     *
     * The same reasoning as every other ceiling in this module, with one addition: a rule is
     * evaluated against **every** signal, so each one is a standing cost on every run as well as a
     * standing instruction to act. Pausing is the way out, which is why the message says so.
     */
    public static function tooManyPlaybooks(int $max): self
    {
        $e = new self("You can have up to {$max} rules. Pause or remove one to add another.");
        $e->errorCode = 'signals.too_many_playbooks';
        $e->status = 422;

        return $e;
    }

    /**
     * A playbook was saved with a step that cannot do anything — a stage move with no stage, a
     * draft with no channel this deployment offers (S14).
     *
     * The web form reports these field by field through its own error bag; this is the answer for
     * the API and for any future caller with no form, so an invalid rule cannot be stored by the
     * path that happened not to validate it.
     */
    public static function playbookIncomplete(string $problem): self
    {
        $e = new self($problem);
        $e->errorCode = 'signals.playbook_incomplete';
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

    /**
     * Somebody already accepted or declined this suggestion (S12).
     *
     * Refused rather than ignored, because the two ways this happens both deserve to be told
     * about: two tabs, and a second click on a slow one. Applying a ten-point threshold nudge
     * twice is a twenty-point change nobody asked for.
     */
    public static function tuningSuggestionSettled(): self
    {
        $e = new self('Someone has already answered this suggestion. Reload to see where it stands.');
        $e->errorCode = 'signals.tuning_settled';

        return $e;
    }

    /**
     * The suggestion is about something that has since changed (S12) — a type that was deleted, a
     * template that was already removed, a threshold the member has edited by hand.
     *
     * The honest answer is to refuse and let the advisor propose again from what is true now,
     * rather than apply a change built on evidence about a thing that no longer exists.
     */
    public static function tuningSuggestionStale(): self
    {
        $e = new self('This suggestion is out of date — what it was about has changed since. Alima will suggest again if it still applies.');
        $e->errorCode = 'signals.tuning_stale';

        return $e;
    }

    /**
     * This team has spent its monthly allowance for reading full articles (S12).
     *
     * A cost ceiling rather than a failure: the run carries on and the signals it found are kept,
     * snippet-only, which is exactly what every signal was before S12. So this is raised only
     * where a caller asked for a fetch explicitly, never from inside a run.
     */
    public static function articleFetchBudgetSpent(): self
    {
        $e = new self('You have used this month’s allowance for reading full articles. Signals keep arriving with the summary from the headline and snippet.');
        $e->errorCode = 'signals.article_budget_spent';

        return $e;
    }
}
