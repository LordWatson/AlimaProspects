<?php

declare(strict_types=1);

namespace App\Modules\Core\Testing;

use App\Modules\Core\Contracts\WebContentFetcher;

/**
 * Test double for {@see WebContentFetcher}: returns a canned body and records the URLs it was asked
 * to fetch, so tests never touch the network. Bind it in place of the contract in a test.
 */
final class FakeWebContentFetcher implements WebContentFetcher
{
    /**
     * @var list<string>
     */
    public array $fetched = [];

    public function __construct(private string $body = 'Example Co helps SMEs grow. We sell marketing software to UK agencies.') {}

    /**
     * Bodies for particular URLs, taking precedence over the default.
     *
     * @var array<string, string>
     */
    private array $byUrl = [];

    public function returns(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Answer one specific URL differently (Signals S12).
     *
     * Added for `robots.txt`: a caller that consults it before fetching makes **two** requests per
     * page, and a double that could only answer both the same way could not test the case where
     * the publisher says no — the one case that matters.
     */
    public function returnsForUrl(string $url, string $body): self
    {
        $this->byUrl[$url] = $body;

        return $this;
    }

    /** How many fetches were made — the cheap assertion for "it did not pay for this". */
    public function calls(): int
    {
        return count($this->fetched);
    }

    /**
     * URLs that should report "could not read" rather than a body.
     *
     * @var list<string>
     */
    private array $unreadable = [];

    public function fetch(?string $url): string
    {
        $this->fetched[] = (string) $url;

        return $this->byUrl[(string) $url] ?? $this->body;
    }

    /**
     * The line-oriented read (S12).
     *
     * Returns the same canned bodies as {@see self::fetch()} — a double has no markup to strip, so
     * the two differ only in what they can say about *failure*, which is the point of the method.
     */
    public function fetchLines(?string $url): ?string
    {
        $this->fetched[] = (string) $url;

        if (in_array((string) $url, $this->unreadable, strict: true)) {
            return null;
        }

        return $this->byUrl[(string) $url] ?? $this->body;
    }

    /**
     * Make one URL unreadable — a timeout or a 5xx, as distinct from a 404.
     *
     * Worth having a verb for: for `robots.txt` the two outcomes mean opposite things, and a double
     * that could not express the difference is how the production no-op went unnoticed.
     */
    public function cannotRead(string $url): self
    {
        $this->unreadable[] = $url;

        return $this;
    }
}
