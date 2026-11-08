<?php

declare(strict_types=1);

use App\Modules\Enrichment\Services\EmailPatternInferrer;

beforeEach(function (): void {
    config()->set('enrichment.inferred_email_pattern', '{first}.{last}');
    config()->set('enrichment.max_inferred_contacts', 3);
});

function inferrer(): EmailPatternInferrer
{
    return new EmailPatternInferrer;
}

it('infers one candidate per named person from the configured pattern', function (): void {
    $candidates = inferrer()->infer('acme.test', [
        ['name' => 'Jane Smith', 'title' => 'Founder'],
    ]);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->email)->toBe('jane.smith@acme.test')
        ->and($candidates[0]->name)->toBe('Jane Smith')
        ->and($candidates[0]->title)->toBe('Founder');
});

it('honours an alternative pattern', function (): void {
    config()->set('enrichment.inferred_email_pattern', '{f}{last}');

    expect(inferrer()->infer('acme.test', [['name' => 'Jane Smith', 'title' => null]])[0]->email)
        ->toBe('jsmith@acme.test');
});

it('skips a one-name person when the pattern needs a surname', function (): void {
    expect(inferrer()->infer('acme.test', [['name' => 'Cher', 'title' => null]]))->toBe([]);
});

it('de-duplicates people who collapse to the same address', function (): void {
    $candidates = inferrer()->infer('acme.test', [
        ['name' => 'Jane Smith', 'title' => 'CEO'],
        ['name' => 'Jane Smith', 'title' => 'Founder'],
    ]);

    expect($candidates)->toHaveCount(1);
});

it('caps how many it infers', function (): void {
    config()->set('enrichment.max_inferred_contacts', 2);

    $candidates = inferrer()->infer('acme.test', [
        ['name' => 'Ann Alpha', 'title' => null],
        ['name' => 'Ben Beta', 'title' => null],
        ['name' => 'Cara Gamma', 'title' => null],
    ]);

    expect($candidates)->toHaveCount(2);
});

it('normalises the domain and rejects a bare word', function (): void {
    expect(inferrer()->infer('WWW.Acme.Test', [['name' => 'Jane Smith', 'title' => null]])[0]->email)
        ->toBe('jane.smith@acme.test')
        ->and(inferrer()->infer('not-a-domain', [['name' => 'Jane Smith', 'title' => null]]))->toBe([]);
});

it('infers nothing when disabled is expressed as a zero cap', function (): void {
    config()->set('enrichment.max_inferred_contacts', 0);

    expect(inferrer()->infer('acme.test', [['name' => 'Jane Smith', 'title' => null]]))->toBe([]);
});
