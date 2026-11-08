<?php

namespace Tests;

use App\Modules\LeadSourcing\Contracts\CompanyWebsiteResolver;
use App\Modules\LeadSourcing\Testing\FakeCompanyWebsiteResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Website resolution (Enrichment) reaches the live web-search provider in production; in
        // tests it must NEVER touch the network, so it defaults to a fake that resolves nothing —
        // exactly as external AI is always faked (CLAUDE.md test rules). A test that exercises
        // resolution overrides this with its own instance.
        $this->app->instance(CompanyWebsiteResolver::class, new FakeCompanyWebsiteResolver);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
