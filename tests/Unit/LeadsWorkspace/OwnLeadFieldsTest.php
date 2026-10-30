<?php

declare(strict_types=1);

use App\Modules\LeadsWorkspace\Support\OwnLeadFields;

/**
 * The keying and clamping the add-a-lead form and the CSV importer share. Pinned on its own,
 * because both paths now depend on it agreeing with itself: a company typed in at an event and the
 * same company in a spreadsheet are one lead only while these two produce the same key.
 */

// --- The identity key ----------------------------------------------------------------------

it('keys on the company number first', function (): void {
    expect(OwnLeadFields::dedupeKey([
        'company_name' => 'Bright Bean Coffee',
        'company_number' => ' 11112222 ',
        'website' => 'brightbean.co.uk',
    ]))->toBe('no:11112222');
});

it('falls back to the website’s registrable domain', function (): void {
    // Source-agnostic and `www.`-insensitive, so a company the team typed in and one a provider
    // discovered collapse into one lead.
    expect(OwnLeadFields::dedupeKey([
        'company_name' => 'Bright Bean Coffee',
        'website' => 'https://WWW.brightbean.co.uk/about',
    ]))->toBe('domain:brightbean.co.uk')
        ->and(OwnLeadFields::dedupeKey([
            'company_name' => 'Bright Bean Coffee',
            'website' => 'brightbean.co.uk',
        ]))->toBe('domain:brightbean.co.uk');
});

it('falls back to the normalised name, and is never empty', function (): void {
    expect(OwnLeadFields::dedupeKey(['company_name' => '  Bright  Bean   Coffee ']))
        ->toBe('name:bright-bean-coffee')
        // A name with nothing sluggable in it still has to produce a key.
        ->and(OwnLeadFields::dedupeKey(['company_name' => 'شركة التمور']))->not->toBe('name:');
});

it('clamps the key to its column', function (): void {
    $key = OwnLeadFields::dedupeKey(['company_name' => str_repeat('a', 400)]);

    expect(mb_strlen($key))->toBeLessThanOrEqual(191);
});

it('ignores a website it would not render as a link when keying', function (): void {
    // `javascript:…` is dropped rather than keyed on, so the name is what identifies the company.
    expect(OwnLeadFields::dedupeKey([
        'company_name' => 'Bright Bean Coffee',
        'website' => 'javascript:alert(1)',
    ]))->toBe('name:bright-bean-coffee');
});

// --- The company columns -------------------------------------------------------------------

it('drops empty columns and clamps the rest to their widths', function (): void {
    $fields = OwnLeadFields::companyFields([
        'company_name' => 'Bright Bean Coffee',
        'company_number' => str_repeat('9', 300),
        'website' => '',
        'industry' => '  Hospitality  ',
        'employee_range' => str_repeat('x', 100),
        'locality' => 'London',
    ]);

    expect($fields)->not->toHaveKey('website')
        ->and($fields)->not->toHaveKey('region')
        ->and($fields['industry'])->toBe('Hospitality')
        ->and(mb_strlen($fields['company_number']))->toBe(255)
        ->and(mb_strlen($fields['employee_range']))->toBe(64)
        ->and($fields['locality'])->toBe('London');
});

it('assumes https for a bare domain and drops anything that is not a link', function (): void {
    expect(OwnLeadFields::safeUrl('brightbean.co.uk'))->toBe('https://brightbean.co.uk')
        ->and(OwnLeadFields::safeUrl('http://brightbean.co.uk'))->toBe('http://brightbean.co.uk')
        ->and(OwnLeadFields::safeUrl('javascript:alert(1)'))->toBe('')
        ->and(OwnLeadFields::safeUrl('not a website'))->toBe('')
        ->and(OwnLeadFields::safeUrl(''))->toBe('');
});

it('takes a two-letter country code and nothing else', function (): void {
    // The column is char(2), so "United Kingdom" must not be stored as "UN".
    expect(OwnLeadFields::country('gb'))->toBe('GB')
        ->and(OwnLeadFields::country(' ae '))->toBe('AE')
        ->and(OwnLeadFields::country('United Kingdom'))->toBe('')
        ->and(OwnLeadFields::country(''))->toBe('');
});

it('clamps multibyte text without leaving half a character behind', function (): void {
    $clamped = OwnLeadFields::clamp(str_repeat('ش', 400), 255);

    expect(mb_strlen($clamped))->toBe(255)
        ->and(mb_check_encoding($clamped, 'UTF-8'))->toBeTrue();
});

// --- Tags ----------------------------------------------------------------------------------

it('splits tags on any separator a spreadsheet or an Arabic keyboard uses', function (): void {
    expect(OwnLeadFields::splitTags('warm, expo; priority|  london ،دبي؛ riyadh'))
        ->toBe(['warm', 'expo', 'priority', 'london', 'دبي', 'riyadh']);
});

it('de-duplicates tags, drops blanks and clamps each to its column', function (): void {
    $tags = OwnLeadFields::splitTags('warm,,warm,  '.str_repeat('z', 80));

    expect($tags)->toHaveCount(2)
        ->and($tags[0])->toBe('warm')
        ->and(mb_strlen($tags[1]))->toBe(40);
});
