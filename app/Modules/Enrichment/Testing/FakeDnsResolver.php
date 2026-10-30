<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Testing;

use App\Modules\Enrichment\Contracts\DnsResolver;

/**
 * Test double for {@see DnsResolver}: answers from a per-domain map with a default for everything
 * else, and records which domains it was asked about — so the email check runs without touching
 * real DNS. Bind it in place of the contract in a test.
 */
final class FakeDnsResolver implements DnsResolver
{
    /**
     * @var array<string, bool>
     */
    private array $answers = [];

    /**
     * @var list<string>
     */
    public array $looked = [];

    public function __construct(private bool $default = true) {}

    /**
     * Fix the answer for a specific domain (case-insensitive).
     */
    public function set(string $domain, bool $hasMx): self
    {
        $this->answers[mb_strtolower(trim($domain))] = $hasMx;

        return $this;
    }

    /**
     * Change the answer returned for any domain not explicitly set.
     */
    public function defaultsTo(bool $hasMx): self
    {
        $this->default = $hasMx;

        return $this;
    }

    public function hasMailExchanger(string $domain): bool
    {
        $domain = mb_strtolower(trim($domain));
        $this->looked[] = $domain;

        return $this->answers[$domain] ?? $this->default;
    }
}
