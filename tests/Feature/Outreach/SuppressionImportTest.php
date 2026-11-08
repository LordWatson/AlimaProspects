<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\ComplianceFixtures as Fixtures;
use Tests\Support\OutreachFixtures;

uses(RefreshDatabase::class);

/**
 * The bulk do-not-contact load (PL5).
 *
 * What a customer actually arrives with is a column out of their CRM, and the reason a suppression
 * list ends up empty is that typing it in one row at a time is nobody's afternoon. So the two
 * things these tests hold down are that **every cell of whatever is pasted gets read** and that
 * **the counts come back** — an import that silently added 1,412 of 5,000 would leave a team
 * believing a list is covered when most of it is not.
 */
it('reads every cell, whatever separates them', function (): void {
    [$member, $team] = Fixtures::team();

    $this->actingAs($member)
        ->withSession(['current_team_id' => $team->getKey()])
        ->post('/outreach/do-not-contact/import', [
            'type' => SuppressionType::Email->value,
            // Newlines, commas, a trailing blank line, and a spreadsheet's quotes.
            'values' => "one@acme.com\n\"two@acme.com\", three@acme.com\n",
        ])->assertRedirect();

    expect(OutreachSuppression::query()->count())->toBe(3)
        ->and(OutreachSuppression::query()->pluck('scope')->unique()->all())
        ->toBe([SuppressionScope::Team])
        // A member cannot write a global entry from the product: a global entry binds every other
        // tenant.
        ->and(OutreachSuppression::query()->pluck('reason')->unique()->all())
        ->toBe([SuppressionReason::Manual]);
});

it('stores a hash and a mask, never the value', function (): void {
    [$member, $team] = Fixtures::team();

    $this->actingAs($member)
        ->withSession(['current_team_id' => $team->getKey()])
        ->post('/outreach/do-not-contact/import', [
            'type' => SuppressionType::Email->value,
            'values' => 'dana@acme.com',
        ])->assertRedirect();

    $entry = OutreachSuppression::query()->sole();

    expect($entry->value_hash)->toBe(app(SuppressionList::class)->hash('dana@acme.com'))
        ->and($entry->value_preview)->toBe('d***@acme.com')
        ->and($entry->getAttributes())->not->toContain('dana@acme.com');
});

it('counts duplicates and unusable values rather than hiding them', function (): void {
    [$member, $team] = Fixtures::team();

    $this->actingAs($member)
        ->withSession(['current_team_id' => $team->getKey()])
        ->post('/outreach/do-not-contact/import', [
            'type' => SuppressionType::Email->value,
            // One good, the same one again in different case, and a half-remembered address. The
            // last one must not be stored: a hash of nonsense never matches anything, and the team
            // would believe they were covered.
            'values' => "dana@acme.com\nDANA@ACME.COM\nnot an address",
        ])->assertRedirect()
        ->assertSessionHas('toast', fn (array $toast): bool => str_contains($toast['message'], '1')
            && str_contains($toast['message'], 'unusable'));

    expect(OutreachSuppression::query()->count())->toBe(1);
});

it('says so when the file was longer than one import reads', function (): void {
    [$member, $team] = Fixtures::team();

    config()->set('outreach.suppressions.max_import', 2);

    $this->actingAs($member)
        ->withSession(['current_team_id' => $team->getKey()])
        ->post('/outreach/do-not-contact/import', [
            'type' => SuppressionType::Email->value,
            'values' => "a@acme.com\nb@acme.com\nc@acme.com",
        ])->assertRedirect()
        ->assertSessionHas('toast', fn (array $toast): bool => str_contains($toast['message'], 'upload the rest'));

    expect(OutreachSuppression::query()->count())->toBe(2);
});

it('takes an uploaded file and keeps nothing of it', function (): void {
    [$member, $team] = Fixtures::team();

    $this->actingAs($member)
        ->withSession(['current_team_id' => $team->getKey()])
        ->post('/outreach/do-not-contact/import', [
            'type' => SuppressionType::CompanyNumber->value,
            'file' => UploadedFile::fake()->createWithContent(
                'clients.csv',
                "company_number\n01234567\nSC 123456\n",
            ),
        ])->assertRedirect();

    // The header cell is read like any other and the type rejects it: a registration number has
    // at least one digit, and `company_number` has none. That rule exists precisely so a pasted
    // header does not become a permanent entry.
    expect(OutreachSuppression::query()->pluck('value_preview')->all())
        ->toEqualCanonicalizing(['01234567', 'SC123456']);
});

it('needs either a paste or a file', function (): void {
    [$member, $team] = Fixtures::team();

    $this->actingAs($member)
        ->withSession(['current_team_id' => $team->getKey()])
        ->post('/outreach/do-not-contact/import', ['type' => SuppressionType::Email->value])
        ->assertSessionHasErrors(['values', 'file']);
});

it('refuses a member who may not manage the list', function (): void {
    [, $team] = Fixtures::team();
    $member = OutreachFixtures::member($team, TeamRole::Member);

    // The same authorisation as adding one by hand — it is the same act, repeated — so there is no
    // second permission here to get wrong.
    $this->actingAs($member)
        ->withSession(['current_team_id' => $team->getKey()])
        ->post('/outreach/do-not-contact/import', [
            'type' => SuppressionType::Email->value,
            'values' => 'dana@acme.com',
        ])->assertForbidden();

    expect(OutreachSuppression::query()->count())->toBe(0);
});

it('refuses a guest outright', function (): void {
    $this->post('/outreach/do-not-contact/import', [
        'type' => SuppressionType::Email->value,
        'values' => 'dana@acme.com',
    ])->assertRedirect('/login');

    expect(OutreachSuppression::query()->count())->toBe(0);
});

it('keeps one team\'s import off another team\'s list', function (): void {
    [$member, $team] = Fixtures::team();
    [, $other] = Fixtures::team();

    $this->actingAs($member)
        ->withSession(['current_team_id' => $team->getKey()])
        ->post('/outreach/do-not-contact/import', [
            'type' => SuppressionType::Email->value,
            'values' => 'dana@acme.com',
        ])->assertRedirect();

    $list = app(SuppressionList::class);

    expect($list->isEmailSuppressed('dana@acme.com', (int) $team->getKey()))->toBeTrue()
        ->and($list->isEmailSuppressed('dana@acme.com', (int) $other->getKey()))->toBeFalse();
});
