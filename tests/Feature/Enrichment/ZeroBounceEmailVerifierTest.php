<?php

declare(strict_types=1);

use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;
use App\Modules\Enrichment\Services\ZeroBounceEmailVerifier;
use Illuminate\Support\Facades\Http;

/*
 * The reference verifier's mapping (PL2), against `Http::fake()` fixtures only.
 *
 * **No live call has ever validated this.** The request and the status vocabulary come from
 * ZeroBounce's published v2 documentation; the adapter is disabled by default and stays disabled
 * until ADR 0027 §2 is completed. What this suite proves is that *our half* is right: that each
 * documented status maps to the one column vocabulary, that a status we have never seen does not
 * fail a job, and — the part that costs real money — that a refusal and an outage are told apart.
 */

beforeEach(function (): void {
    config([
        'enrichment.providers.zerobounce.enabled' => true,
        'enrichment.providers.zerobounce.api_key' => 'test-key',
        'enrichment.providers.zerobounce.cost_micros' => 700,
    ]);

    $this->verifier = app(ZeroBounceEmailVerifier::class);

    // `enrichment.breaker`, **not** `CircuitBreaker::class` — that binding is Lead Sourcing's,
    // keyed under its own prefix, and asserting against it here would have passed vacuously
    // whatever this adapter did.
    $this->breaker = app('enrichment.breaker');
    $this->breaker->reset('zerobounce');
});

/**
 * @param  array<string, mixed>  $body
 */
function zeroBounceReturns(array $body, int $status = 200): void
{
    Http::fake(['api.zerobounce.net/*' => Http::response($body, $status)]);
}

it('maps every documented status onto the one column vocabulary', function (
    string $status,
    ?string $subStatus,
    EmailVerificationStatus $expected,
): void {
    zeroBounceReturns(['address' => 'jane@acme.com', 'status' => $status, 'sub_status' => $subStatus]);

    expect($this->verifier->verifyEmail('jane@acme.com')->status)->toBe($expected);
})->with([
    // A vendor's own words, mapped. The mapping is lossy on purpose: one column, one vocabulary,
    // whoever filled it in.
    ['valid', '', EmailVerificationStatus::Verified],
    ['catch-all', '', EmailVerificationStatus::AcceptAll],
    ['invalid', 'mailbox_not_found', EmailVerificationStatus::Undeliverable],
    ['unknown', 'timeout', EmailVerificationStatus::Unverifiable],
    ['spamtrap', '', EmailVerificationStatus::Risky],
    ['abuse', '', EmailVerificationStatus::Risky],
    ['do_not_mail', 'role_based', EmailVerificationStatus::Risky],
    // The one place `sub_status` refines the verdict: a throwaway domain is something we already
    // have a case for, and it reads better to a member than "do not mail".
    ['do_not_mail', 'disposable', EmailVerificationStatus::DisposableDomain],
]);

it('treats a status it has never seen as “could not tell”, not as a failure', function (): void {
    zeroBounceReturns(['address' => 'jane@acme.com', 'status' => 'something_new_in_2027']);

    // A vendor may add a status tomorrow. The honest reading of a word we do not know is "I could
    // not tell" — a job that failed on it would be a job that fails on a vendor's changelog.
    expect($this->verifier->verifyEmail('jane@acme.com')->status)
        ->toBe(EmailVerificationStatus::Unverifiable);
});

it('does not pay to ask about something that is not an address', function (): void {
    Http::fake();

    $result = $this->verifier->verifyEmail('not-an-address');

    // Our own syntax check already knows the answer, and a vendor that bills per attempt would
    // have charged us for asking it.
    expect($result->status)->toBe(EmailVerificationStatus::InvalidSyntax)
        ->and($result->costMicros)->toBe(0);

    Http::assertNothingSent();
});

it('records what the call cost, per attempt', function (): void {
    zeroBounceReturns(['address' => 'jane@acme.com', 'status' => 'valid']);

    // A verifier bills per lookup, which is why the figure sits on `perAttemptMicros` — every "no"
    // is chargeable, and PL4's margin figures are wrong without that.
    expect($this->verifier->verifyEmail('jane@acme.com')->costMicros)->toBe(700)
        ->and($this->verifier->describesCost()->perAttemptMicros)->toBe(700)
        ->and($this->verifier->describesCost()->perSuccessMicros)->toBe(0);
});

it('normalises the address and attributes the verdict to the provider', function (): void {
    zeroBounceReturns(['address' => 'Jane@Acme.com', 'status' => 'valid']);

    $result = $this->verifier->verifyEmail('  Jane@Acme.com ');

    expect($result->email)->toBe('jane@acme.com')
        ->and($result->provider)->toBe('zerobounce')
        ->and($result->isFromVendor())->toBeTrue()
        // The confidence comes from our own enum, not from the vendor: it is the figure Scoring
        // reads, and it has to mean the same thing whoever verified the address.
        ->and($result->confidence)->toBe(EmailVerificationStatus::Verified->confidence());
});

it('treats a 4xx as our mistake and leaves the breaker shut', function (): void {
    zeroBounceReturns(['error' => 'Invalid API key'], 401);

    expect(fn () => $this->verifier->verifyEmail('jane@acme.com'))
        ->toThrow(EnrichmentProviderException::class);

    // A key we got wrong will be wrong next time, and it is not the other vendors' problem: a
    // breaker trip here would take this vendor out of every team's waterfall for a cooldown over
    // a mistake that a cooldown cannot fix (ADR 0023 §4, applied to a vendor).
    expect($this->breaker->failures('zerobounce'))->toBe(0);
});

it('treats a 5xx as the vendor being unwell and trips the breaker', function (): void {
    zeroBounceReturns(['error' => 'oh dear'], 503);

    $breaker = $this->breaker;

    // The threshold is read when the breaker is **constructed**, so it comes from the shipped
    // config rather than from a `config()` call in a test — setting it here would have changed
    // nothing and the test would have been measuring the default all along.
    $threshold = (int) config('enrichment.circuit_breaker.threshold', 5);

    foreach (range(1, $threshold) as $ignored) {
        try {
            $this->verifier->verifyEmail('jane@acme.com');
        } catch (EnrichmentProviderException) {
            // expected
        }
    }

    expect($breaker->isOpen('zerobounce'))->toBeTrue();

    // And once it is open, nothing is sent at all.
    Http::fake();
    expect(fn () => $this->verifier->verifyEmail('jane@acme.com'))
        ->toThrow(EnrichmentProviderException::class);
    Http::assertNothingSent();
});

it('does not retry a rate limit', function (): void {
    zeroBounceReturns(['error' => 'too fast'], 429);

    expect(fn () => $this->verifier->verifyEmail('jane@acme.com'))
        ->toThrow(EnrichmentProviderException::class);

    // Retrying inside the request would turn one mistake into a ban. The fix for asking too fast
    // is the rate limiter, not asking again harder.
    expect($this->breaker->failures('zerobounce'))->toBe(0);
});

it('refuses to read a response with no status in it', function (): void {
    zeroBounceReturns(['address' => 'jane@acme.com']);

    // The one failure an operator must actually see: it means the vendor changed its contract, or
    // we mapped it wrongly from the start — and in the second case every stored result is suspect.
    expect(fn () => $this->verifier->verifyEmail('jane@acme.com'))
        ->toThrow(EnrichmentProviderException::class);
});

it('reports itself unusable with no key, rather than calling anyway', function (): void {
    config(['enrichment.providers.zerobounce.api_key' => null]);
    Http::fake();

    expect($this->verifier->isEnabled())->toBeFalse()
        ->and(fn () => $this->verifier->verifyEmail('jane@acme.com'))
        ->toThrow(EnrichmentProviderException::class);

    Http::assertNothingSent();
});

it('sends the address and the key, and nothing else', function (): void {
    zeroBounceReturns(['address' => 'jane@acme.com', 'status' => 'valid']);

    $this->verifier->verifyEmail('jane@acme.com');

    Http::assertSent(function ($request): bool {
        $query = [];
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

        return $query['email'] === 'jane@acme.com'
            && $query['api_key'] === 'test-key'
            // No `ip_address`: we do not hold one for a prospect and sending a customer's would be
            // sending a third party something about somebody who is not their business.
            && ! array_key_exists('ip_address', $query);
    });
});
