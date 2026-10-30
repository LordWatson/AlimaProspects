<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;

function sourcedLead(array $overrides = []): SourcedLead
{
    return new SourcedLead(...array_merge([
        'source' => LeadSource::CompaniesHouse,
        'companyName' => 'Acme Widgets Ltd',
    ], $overrides));
}

it('builds a source-agnostic dedupe key from the company number first', function (): void {
    // No source prefix — the same number from any provider collapses to one lead (Phase 7b).
    expect(sourcedLead(['companyNumber' => 'SC123456'])->dedupeKey())
        ->toBe('no:sc123456');
});

it('falls back to the website domain when there is no number', function (): void {
    expect(sourcedLead(['website' => 'https://www.Acme-Widgets.CO.uk/about'])->dedupeKey())
        ->toBe('domain:acme-widgets.co.uk');
});

it('falls back to a provider-scoped slug + hash when there is no number, domain or external id', function (): void {
    // Name-only keys are provider-scoped (a bare name can't be trusted across sources); the hash is
    // of the slug so it is case-insensitive ("ACME" and "Acme" collapse).
    $slug = 'acme-widgets-ltd';
    expect(sourcedLead()->dedupeKey())
        ->toBe('name:companies_house:'.$slug.':'.substr(sha1($slug), 0, 12));
});

it('uses a provider-scoped external id before the name fallback', function (): void {
    expect(sourcedLead(['externalId' => 'PLACE-xyz'])->dedupeKey())
        ->toBe('ext:companies_house:place-xyz');
});

it('matches names case-insensitively via the slug hash', function (): void {
    expect(sourcedLead(['companyName' => 'ACME'])->dedupeKey())
        ->toBe(sourcedLead(['companyName' => 'acme'])->dedupeKey());
});

it('normalises the website to a bare domain', function (): void {
    expect(sourcedLead(['website' => 'www.Example.com'])->domain())->toBe('example.com')
        ->and(sourcedLead(['website' => 'https://sub.example.com/path'])->domain())->toBe('sub.example.com')
        ->and(sourcedLead()->domain())->toBeNull();
});

it('clamps a source URL to its own wide column, not the text one', function (): void {
    // An article URL is routinely longer than 255 — Serper answers some articles with a Google
    // redirect of ~280 characters — and `leads.source_url` is 2048 wide to hold it. Clamping to
    // 255 stored a link that no longer resolves and said nothing about it.
    $long = 'https://google.com/goto?url='.str_repeat('A', 400);

    expect(sourcedLead(['sourceUrl' => $long])->toLeadAttributes()['source_url'])->toBe($long);

    // Past the column, it is cut rather than allowed to throw on insert.
    $absurd = 'https://example.com/?q='.str_repeat('B', 3000);

    expect(strlen((string) sourcedLead(['sourceUrl' => $absurd])->toLeadAttributes()['source_url']))
        ->toBe(2048);
});

it('still holds a website to the width of its own column', function (): void {
    // `leads.website` is a homepage and stays at 255; a clamp wider than the column would throw.
    $long = 'https://example.com/'.str_repeat('c', 400);

    expect(strlen((string) sourcedLead(['website' => $long])->toLeadAttributes()['website']))
        ->toBe(255);
});

it('drops a non-http source URL however long it is', function (): void {
    expect(sourcedLead(['sourceUrl' => 'javascript:alert(1)'])->toLeadAttributes()['source_url'])
        ->toBeNull()
        ->and(sourcedLead(['sourceUrl' => 'data:text/html;base64,'.str_repeat('A', 400)])
            ->toLeadAttributes()['source_url'])->toBeNull();
});
