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

it('builds a dedupe key from the company number first', function (): void {
    expect(sourcedLead(['companyNumber' => 'SC123456'])->dedupeKey())
        ->toBe('companies_house:no:sc123456');
});

it('falls back to the website domain when there is no number', function (): void {
    expect(sourcedLead(['website' => 'https://www.Acme-Widgets.CO.uk/about'])->dedupeKey())
        ->toBe('domain:acme-widgets.co.uk');
});

it('falls back to a slug + name hash when there is no number or website', function (): void {
    // slug prefix stays readable; the short sha1 suffix keeps distinct same-prefix names apart.
    expect(sourcedLead()->dedupeKey())
        ->toBe('name:acme-widgets-ltd:'.substr(sha1('Acme Widgets Ltd'), 0, 12));
});

it('normalises the website to a bare domain', function (): void {
    expect(sourcedLead(['website' => 'www.Example.com'])->domain())->toBe('example.com')
        ->and(sourcedLead(['website' => 'https://sub.example.com/path'])->domain())->toBe('sub.example.com')
        ->and(sourcedLead()->domain())->toBeNull();
});
