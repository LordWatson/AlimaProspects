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

    public function returns(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function fetch(?string $url): string
    {
        $this->fetched[] = (string) $url;

        return $this->body;
    }
}
