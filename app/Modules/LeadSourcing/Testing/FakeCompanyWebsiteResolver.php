<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Testing;

use App\Modules\LeadSourcing\Contracts\CompanyWebsiteResolver;

/**
 * Test double for {@see CompanyWebsiteResolver}: returns a fixed candidate (null by default, so a
 * website-less lead behaves exactly as it did before resolution existed) and records every company
 * it was asked about. Bind it in place of the contract in a test.
 */
final class FakeCompanyWebsiteResolver implements CompanyWebsiteResolver
{
    /**
     * @var list<array{company: string, location: ?string}>
     */
    public array $asked = [];

    public function __construct(private ?string $url = null) {}

    /**
     * Fix the candidate URL the resolver returns for every call.
     */
    public function returns(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function resolve(string $companyName, ?string $location = null): ?string
    {
        $this->asked[] = ['company' => $companyName, 'location' => $location];

        return $this->url;
    }
}
