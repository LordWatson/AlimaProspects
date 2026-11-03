<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Support\CompanyNameKey;
use App\Modules\Signals\Actions\ResolveWatchlistCompanyNumbersAction;
use App\Modules\Signals\Exceptions\SignalsException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turn a company name into a Companies House number, or refuse (S13b).
 *
 * Used by {@see ResolveWatchlistCompanyNumbersAction} for the entries the team's own data cannot
 * answer for. Separate from {@see CompaniesHouseSignalSource} because it is a different question —
 * "which company is this?" rather than "what has this company filed?" — and because the Action
 * needs it without needing a `SignalSourceProvider`.
 *
 * **The whole value of this class is that it says no.** Companies House search is fuzzy and ranked:
 * `q=Greggs` returns a page of companies with Greggs somewhere in the name, and the top hit is not
 * necessarily the one a member meant. Accepting it would pin filings to the wrong company — and
 * because a registry filing arrives with confidence 100 and skips the AI (ADR 0023 §6), nothing
 * downstream would question it. So a candidate is accepted only when its name matches **exactly**
 * under {@see CompanyNameKey}, the one name rule this product has, and every other outcome is null.
 *
 * It shares Lead Sourcing's credential and breaker for the reason the source does: one API key, one
 * 600-per-five-minutes quota, one circuit.
 */
final class CompaniesHouseNumberLookup
{
    /** How many candidates to consider. The exact match, if there is one, is near the top. */
    private const int CANDIDATES = 10;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * The number of the company whose registered name is exactly one of these, or null.
     *
     * The names are the entry's own spelling and its aliases, tried in order, so a member who added
     * "Acme Ltd" as an alias of "Acme" gets the match their alias earns.
     *
     * @param  list<string>  $names
     */
    public function exactMatch(array $names): ?string
    {
        foreach ($names as $name) {
            $wanted = CompanyNameKey::for($name);

            if ($wanted === '') {
                continue;
            }

            $number = $this->searchFor($name, $wanted);

            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }

    /**
     * One search, scanned for an exact normalised-name match.
     *
     * **`CompanyNameKey::for()` and never `bare()`.** The strict form keeps the legal suffix, so
     * "Acme Ltd" and "Acme Holdings Ltd" stay different companies; `bare()` strips legal forms and
     * exists for the *lead matcher*, where a looser comparison is paired with a confidence a member
     * can see and a _Convert anyway_ button. Here there is no second chance and no visible
     * confidence — the number silently decides whose filings become somebody's signals — so the
     * loose rule is the wrong one, and the `holdings`/`group` incident ADR 0021 records is what
     * it looks like when it is used anyway.
     */
    private function searchFor(string $name, string $wanted): ?string
    {
        $response = $this->guarded(fn (): Response => $this->http
            ->baseUrl(rtrim((string) config('lead-sourcing.providers.companies_house.base_url'), '/'))
            ->withBasicAuth($this->requireApiKey(), '')
            ->timeout((int) config('lead-sourcing.providers.companies_house.timeout', 10))
            ->connectTimeout((int) config('lead-sourcing.providers.companies_house.connect_timeout', 5))
            ->retry(
                (int) config('lead-sourcing.providers.companies_house.retry_times', 2),
                (int) config('lead-sourcing.providers.companies_house.retry_sleep_ms', 250),
                $this->retryWhen(),
                throw: false,
            )
            ->acceptJson()
            ->get('/search/companies', [
                'q' => $name,
                'items_per_page' => self::CANDIDATES,
            ]));

        $items = $response->json('items');

        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = is_string($item['title'] ?? null) ? $item['title'] : '';
            $number = is_string($item['company_number'] ?? null) ? trim($item['company_number']) : '';

            if ($number === '' || CompanyNameKey::for($title) !== $wanted) {
                continue;
            }

            // A dissolved company still files nothing, so matching one would resolve a number whose
            // filing history is closed. Not an error — just not the company anybody means.
            $status = is_string($item['company_status'] ?? null) ? $item['company_status'] : '';

            if ($status !== '' && $status !== 'active') {
                continue;
            }

            return $number;
        }

        return null;
    }

    /**
     * The same hardening every other source gets, over Lead Sourcing's breaker key.
     *
     * @param  Closure(): Response  $request
     */
    private function guarded(Closure $request): Response
    {
        $key = LeadSource::CompaniesHouse->value;

        if (! $this->breaker->allows($key)) {
            throw SignalsException::sourceUnavailable('companies_house');
        }

        try {
            $response = $request();
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure($key);
            Log::channel('signals')->warning('Companies House name lookup connection failed.', [
                'exception' => $e->getMessage(),
            ]);

            throw SignalsException::sourceUnavailable('companies_house');
        } catch (Throwable $e) {
            Log::channel('signals')->error('Companies House name lookup errored.', [
                'exception' => $e->getMessage(),
            ]);

            throw SignalsException::sourceFailed('companies_house');
        }

        if ($response->failed()) {
            if ($response->serverError() || $response->status() === 429) {
                $this->breaker->recordFailure($key);

                throw SignalsException::sourceUnavailable('companies_house');
            }

            Log::channel('signals')->warning('Companies House refused a name lookup.', [
                'status' => $response->status(),
            ]);

            throw SignalsException::sourceFailed('companies_house');
        }

        $this->breaker->recordSuccess($key);

        return $response;
    }

    private function requireApiKey(): string
    {
        $apiKey = (string) config('lead-sourcing.providers.companies_house.api_key');

        if ($apiKey === '') {
            throw SignalsException::sourceMisconfigured('companies_house');
        }

        return $apiKey;
    }

    /**
     * @return Closure(Throwable): bool
     */
    private function retryWhen(): Closure
    {
        return static function (Throwable $e): bool {
            if ($e instanceof ConnectionException) {
                return true;
            }

            if ($e instanceof RequestException && $e->response !== null) {
                return $e->response->serverError() || $e->response->status() === 429;
            }

            return false;
        };
    }
}
