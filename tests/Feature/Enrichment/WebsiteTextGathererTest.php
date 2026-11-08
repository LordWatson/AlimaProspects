<?php

declare(strict_types=1);

use App\Modules\Core\Testing\FakeWebContentFetcher;
use App\Modules\Enrichment\Services\WebsiteTextGatherer;

beforeEach(function (): void {
    config()->set('enrichment.contact_paths', ['/contact', '/about', '/team']);
    config()->set('enrichment.max_pages_per_lead', 4);
    config()->set('enrichment.max_combined_chars', 18_000);
});

it('gathers the homepage then the configured contact pages', function (): void {
    $fetcher = (new FakeWebContentFetcher('Homepage text.'))
        ->returnsForUrl('https://acme.test/contact', 'Contact: hello@acme.test');

    $text = (new WebsiteTextGatherer($fetcher))->gather('https://acme.test');

    expect($fetcher->fetched)->toBe([
        'https://acme.test',
        'https://acme.test/contact',
        'https://acme.test/about',
        'https://acme.test/team',
    ])
        ->and($text)->toContain('Homepage text.')
        ->and($text)->toContain('hello@acme.test');
});

it('returns nothing and probes no sub-pages when the homepage is empty', function (): void {
    $fetcher = new FakeWebContentFetcher(''); // every URL empty

    $text = (new WebsiteTextGatherer($fetcher))->gather('https://acme.test');

    expect($text)->toBe('')
        ->and($fetcher->fetched)->toBe(['https://acme.test']);
});

it('probes sub-pages against the site origin, not the homepage path', function (): void {
    $fetcher = new FakeWebContentFetcher('Homepage.');

    (new WebsiteTextGatherer($fetcher))->gather('https://acme.test/en/home');

    expect($fetcher->fetched)->toContain('https://acme.test/contact');
});

it('honours the page cap, counting the homepage as one', function (): void {
    config()->set('enrichment.max_pages_per_lead', 2);
    $fetcher = new FakeWebContentFetcher('Body.');

    (new WebsiteTextGatherer($fetcher))->gather('https://acme.test');

    // Homepage + exactly one contact page.
    expect($fetcher->fetched)->toBe(['https://acme.test', 'https://acme.test/contact']);
});

it('returns nothing for a missing website', function (): void {
    $fetcher = new FakeWebContentFetcher('Body.');

    expect((new WebsiteTextGatherer($fetcher))->gather(null))->toBe('')
        ->and($fetcher->fetched)->toBe([]);
});
