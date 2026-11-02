<?php

declare(strict_types=1);

namespace App\Modules\Core\Observability;

use App\Modules\Core\Support\AuditContext;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Str;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Tracing\SamplingContext;
use Sentry\UserDataBag;
use Throwable;

/**
 * The callables `config/sentry.php` points at (Phase 20, ADR 0017).
 *
 * Static methods referenced as `[SentryOptions::class, 'beforeSend']` rather than closures in the
 * config file, for one unglamorous reason: a closure in config means `php artisan config:cache`
 * fails, and the first symptom of that is a production deploy that will not boot.
 *
 * They do three jobs.
 *
 * **Scrub.** Two mechanisms, because one of them is structurally not enough.
 *
 * By **key**: every value under a key that looks like a credential or like personal data is
 * replaced, at any depth, in request data, extra context, contexts, tags, the user bag and every
 * breadcrumb's metadata. The list lives in `config/observability.php`, because what counts as
 * sensitive is a compliance decision rather than a code one. This is aimed at *our own* code — the
 * realistic way a password or a prospect's email address reaches an error tracker is not the SDK,
 * it is a `Log::error('...', ['payload' => $request->all()])` three modules away becoming a
 * breadcrumb.
 *
 * By **name**, in {@see self::scrubRequest()}: the URL, the query string, the cookies and the
 * request body. A deny-list cannot reach those, because the *keys* (`url`, `query_string`, `data`)
 * do not look sensitive while three of the values are — and `send_default_pii => false` does **not**
 * stop the SDK collecting them, which is the thing an earlier version of this docblock got wrong.
 * It gates the cookies, the headers and the IP; the URL, the query string and (in the default
 * legacy `data_collection` mode) the body are collected regardless.
 *
 * **Annotate.** An error with no tenant on it is an error nobody can act on: "AI request failed"
 * is a shrug, "AI request failed for team 41, request `0f2e…`" is a thing to go and look at. The
 * tags come from {@see TeamContext} and {@see AuditContext}, which both already hold the answer in
 * a request *and* in a queue job — so this works for the worker that has no request at all, which
 * is where the interesting failures are. The user is attached as an **id and nothing else**: not
 * the name, not the email address. Sentry would happily show both; a bug report does not need to
 * know who.
 *
 * It also records whether the session was **impersonated**, because the same error from an operator
 * standing in a customer's shoes and from the customer themselves are different incidents.
 *
 * **Sample.** The health check runs every ten seconds and the two operator dashboards poll; tracing
 * all three buys a bill and no information. They are dropped in the *sampler*, so they are never
 * measured, rather than in `before_send_transaction`, which drops them after the work is done.
 * `before_send_transaction` keeps the same list anyway, because a transaction can also be started
 * by an integration that never consults the sampler.
 */
final class SentryOptions
{
    /**
     * Scrub and annotate an error event.
     *
     * Returns an `Event` and never `null`, and that is deliberate rather than incidental: Sentry's
     * `before_send` may drop an event by returning null, and this one never does. Expected
     * refusals are filtered by `ignore_exceptions` in `config/sentry.php` instead, which is both
     * declarative — a reviewable list rather than a condition in a method — and cheaper, because
     * the event is never built.
     */
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        self::annotate($event);
        self::scrubEvent($event);

        return $event;
    }

    /**
     * Scrub and annotate a transaction, and drop the ones that are noise.
     */
    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        if (self::isIgnoredTransaction($event->getTransaction())) {
            return null;
        }

        self::annotate($event);
        self::scrubEvent($event);

        return $event;
    }

    /**
     * The trace sampling decision.
     *
     * Honours the parent's decision when one arrives — a trace that is half sampled is not a trace
     * — and otherwise falls back to the configured rate. `traces_sample_rate` being unset means
     * tracing is off, which is the default and the right one for an installation that has not
     * decided about it.
     */
    public static function tracesSampler(SamplingContext $context): float
    {
        $name = $context->getTransactionContext()?->getName();

        if (self::isIgnoredTransaction($name)) {
            return 0.0;
        }

        $parentSampled = $context->getParentSampled();

        if ($parentSampled !== null) {
            return $parentSampled ? 1.0 : 0.0;
        }

        /** @var mixed $rate */
        $rate = config('sentry.traces_sample_rate');

        return is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : 0.0;
    }

    /**
     * Whether a value under this key is sensitive.
     *
     * Substring, case-insensitive, with a short allowlist for the keys the substring rule would
     * catch wrongly (`input_tokens` is a measurement, not a credential). Public because the
     * frontend's scrubber is tested against the same rule and the posture command reports it.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $needle = Str::lower($key);

        foreach (self::configList('observability.sentry.scrub.allow') as $allowed) {
            if ($needle === Str::lower($allowed)) {
                return false;
            }
        }

        foreach (self::configList('observability.sentry.scrub.contains') as $sensitive) {
            if ($sensitive !== '' && str_contains($needle, Str::lower($sensitive))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace the value of every sensitive key, at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        $redaction = self::redaction();

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = $redaction;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }

    private static function scrubEvent(Event $event): void
    {
        $event->setRequest(self::scrubRequest($event->getRequest()));
        $event->setExtra(self::scrub($event->getExtra()));
        $event->setTags(self::scrubStrings($event->getTags()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, self::scrub($context));
        }

        $user = $event->getUser();

        if ($user !== null) {
            // The class docblock claims the actor is attached as an id and nothing else. This is
            // the line that makes that true rather than aspirational: `annotate()` only ever sets
            // an id, but an integration — or `send_default_pii` turned on by somebody in a hurry —
            // can put a name, an email address or an IP on the same bag.
            $user->setUsername(null);
            $user->setEmail(null);
            $user->setIpAddress(null);

            // Dropped rather than redacted: `[redacted]` in a user field reads like a value, and
            // the only reason a name or an email address is on a Sentry user is that something
            // put it there by accident.
            foreach (array_keys($user->getMetadata()) as $key) {
                if (self::isSensitiveKey($key)) {
                    $user->removeMetadata($key);
                }
            }
        }

        $event->setBreadcrumb(array_map(
            static fn (Breadcrumb $breadcrumb): Breadcrumb => self::scrubBreadcrumb($breadcrumb),
            $event->getBreadcrumbs(),
        ));
    }

    /**
     * The request bag, which needs four things done to it **by name** rather than by key matching.
     *
     * Found by the Phase 20 security review, and it is the one place where a key-based scrubber is
     * structurally the wrong tool: the SDK's own `RequestIntegration` writes `url`, `query_string`,
     * `cookies` and `data`, and none of those *keys* looks sensitive while three of their values
     * are. `send_default_pii` does not help — it gates the cookies, the headers and the IP, but
     * **not** the URL, the query string or (in legacy `data_collection` mode, which is the default)
     * the request body.
     *
     * What that meant concretely in this app: a 500 on a signed invitation link would have shipped
     * `GET /invitations/<secret-token>?expires=…&signature=…` to Sentry — a working credential,
     * readable by everyone with access to the project — and a failed save would have shipped the
     * prospect record that was being saved, because `name`, `company_name` and `notes` are not
     * credential-shaped words and were never going to be on a deny-list.
     *
     * So: the query string goes, the URL keeps only its path, the cookies go, and the body goes.
     * `max_request_body_size => 'none'` in `config/sentry.php` is the first line — the body is not
     * collected at all — and dropping `data` here is the second, so the claim stays true if
     * somebody changes that option or sets a body by hand.
     *
     * @param  array<array-key, mixed>  $request
     * @return array<array-key, mixed>
     */
    private static function scrubRequest(array $request): array
    {
        $request = self::scrub($request);

        if (array_key_exists('query_string', $request)) {
            $request['query_string'] = self::redaction();
        }

        if (isset($request['url']) && is_string($request['url'])) {
            // The path is what makes a report useful — which endpoint failed. The query is what
            // makes it a disclosure: a search term in this product is somebody's company name, and
            // a signature is a credential.
            $cut = strpos($request['url'], '?');

            if ($cut !== false) {
                $request['url'] = substr($request['url'], 0, $cut).'?'.self::redaction();
            }
        }

        // Dropped rather than redacted: neither is any use in a report, and an empty key invites
        // somebody to wonder what was in it.
        unset($request['cookies'], $request['data']);

        return $request;
    }

    /**
     * A breadcrumb's metadata is immutable, so a scrubbed one is a new breadcrumb — which is also
     * why this rebuilds rather than mutating in place.
     */
    private static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $metadata = $breadcrumb->getMetadata();

        if ($metadata === []) {
            return $breadcrumb;
        }

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $breadcrumb->getMessage(),
            self::scrub($metadata),
            $breadcrumb->getTimestamp(),
        );
    }

    /**
     * Tags are a flat string map; scrubbing one must not turn its value into an array.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private static function scrubStrings(array $tags): array
    {
        $redaction = self::redaction();

        foreach ($tags as $key => $value) {
            if (self::isSensitiveKey($key)) {
                $tags[$key] = $redaction;
            }
        }

        return $tags;
    }

    /**
     * Tenant, actor and request tags — in a job as well as in a request.
     *
     * Wrapped in a `try`: this runs while an exception is being reported, and an error tracker that
     * throws on its way to reporting an error is the worst possible failure mode. A missing tag is
     * a worse report; a thrown tag is no report at all.
     */
    private static function annotate(Event $event): void
    {
        try {
            $teamId = app(TeamContext::class)->id();

            if ($teamId !== null) {
                $event->setTag('team_id', (string) $teamId);
            }

            $audit = app(AuditContext::class)->all();

            if (array_key_exists('impersonated_by', $audit)) {
                $event->setTag('impersonated', 'true');
            }

            // Two **independent** facts, not a choice between them. A queue worker is a console
            // process with no request id; a web request has an id and is not console; and a test
            // simulating a request is console *and* has an id, which is how the original
            // either/or was found — it gated the request id behind "not console" and so the tag
            // was absent from every event the suite could see, including the one asserting it.
            if (app()->runningInConsole()) {
                $event->setTag('context', 'console');
            }

            $requestId = request()->headers->get('X-Request-Id');

            if (is_string($requestId) && $requestId !== '') {
                $event->setTag('request_id', $requestId);
            }

            self::annotateUser($event);
        } catch (Throwable) {
            // Reporting an error must never be the thing that fails.
        }
    }

    /**
     * The actor, as an id. Never the name or the email address — see the class docblock.
     */
    private static function annotateUser(Event $event): void
    {
        if (! app()->bound('auth')) {
            return;
        }

        /** @var int|string|null $id */
        $id = auth()->id();

        if ($id === null) {
            return;
        }

        $event->setUser(UserDataBag::createFromUserIdentifier($id));
    }

    /**
     * Whether this transaction is one of the ones never worth a trace.
     *
     * Matched **twice**: against the name as given, and against it with any leading HTTP method
     * stripped. Sentry names an HTTP transaction `GET /up` while a path-shaped pattern reads `/up`,
     * and a list that has to repeat every entry in both spellings is a list where somebody adds one
     * spelling and believes they are done — which is how `/up` ends up traced every ten seconds.
     */
    private static function isIgnoredTransaction(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        $patterns = self::configList('observability.sentry.ignore_transactions');

        if ($patterns === []) {
            return false;
        }

        $withoutMethod = preg_replace('/^[A-Z]+ /', '', $name) ?? $name;

        return Str::is($patterns, $name) || Str::is($patterns, $withoutMethod);
    }

    private static function redaction(): string
    {
        /** @var mixed $redaction */
        $redaction = config('observability.sentry.redaction', '[redacted]');

        return is_string($redaction) ? $redaction : '[redacted]';
    }

    /**
     * @return list<string>
     */
    private static function configList(string $key): array
    {
        /** @var mixed $value */
        $value = config($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
