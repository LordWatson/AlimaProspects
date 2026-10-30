<?php

declare(strict_types=1);

use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Services\EmailVerifier;
use App\Modules\Enrichment\Testing\FakeDnsResolver;

/**
 * Build the verifier over a fake resolver so no real DNS is touched (CLAUDE.md test rules).
 */
function verifier(FakeDnsResolver $dns): EmailVerifier
{
    return new EmailVerifier($dns);
}

it('rejects a syntactically invalid address', function (): void {
    $result = verifier(new FakeDnsResolver)->verify('not-an-email');

    expect($result->status)->toBe(EmailVerificationStatus::InvalidSyntax)
        ->and($result->confidence)->toBe(0)
        ->and($result->isPersonal)->toBeFalse();
});

it('flags a disposable domain without hitting DNS', function (): void {
    $dns = (new FakeDnsResolver)->defaultsTo(true);

    $result = verifier($dns)->verify('someone@mailinator.com');

    expect($result->status)->toBe(EmailVerificationStatus::DisposableDomain)
        ->and($result->confidence)->toBe(10)
        // Disposable domains are a known list, so a DNS lookup is never needed.
        ->and($dns->looked)->toBe([]);
});

it('marks an address with no mail server as unroutable', function (): void {
    $dns = (new FakeDnsResolver)->set('acme.test', false);

    $result = verifier($dns)->verify('jane@acme.test');

    expect($result->status)->toBe(EmailVerificationStatus::NoMxRecord)
        ->and($result->confidence)->toBe(20);
});

it('confirms routability when the domain has a mail exchanger', function (): void {
    $dns = (new FakeDnsResolver)->set('acme.test', true);

    $result = verifier($dns)->verify('jane@acme.test');

    expect($result->status)->toBe(EmailVerificationStatus::MxFound)
        ->and($result->confidence)->toBe(60);
});

it('classifies a role address as business and a named address as personal', function (): void {
    $dns = (new FakeDnsResolver)->defaultsTo(true);

    $role = verifier($dns)->verify('INFO@acme.test');
    $personal = verifier($dns)->verify('jane.doe@acme.test');

    expect($role->isRoleBased)->toBeTrue()
        ->and($role->isPersonal)->toBeFalse()
        ->and($personal->isRoleBased)->toBeFalse()
        ->and($personal->isPersonal)->toBeTrue()
        // Normalised to lower case.
        ->and($role->email)->toBe('info@acme.test');
});
