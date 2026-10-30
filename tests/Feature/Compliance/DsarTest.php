<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\EraseSubjectDataAction;
use App\Modules\Compliance\Enums\SubjectDataCategory;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Compliance\Services\PersonalDataLocator;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Data-subject access and erasure (Phase 15, docs/compliance.md §3.5).
 *
 * The tests are shaped around the two ways this feature fails: finding too little (and telling
 * somebody we hold nothing about them when we do), and erasing too much (acting on a name match
 * that was never the right person).
 */
it('finds the contact, the messages and the approaches behind an address', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = subjectLead($team, $user);

    $report = app(PersonalDataLocator::class)->locate($team, 'Dana@Acme.test ');

    // Normalised through the same code the suppression list uses, so a DSAR and the do-not-contact
    // list agree about what "the same address" means.
    expect($report->query)->toBe('dana@acme.test')
        ->and($report->matchedOn)->toBe('email');

    $counts = $report->counts();

    expect($counts[SubjectDataCategory::Contact->value])->toBe(1)
        ->and($counts[SubjectDataCategory::Message->value])->toBe(1)
        ->and($counts[SubjectDataCategory::Attempt->value])->toBe(1)
        ->and($lead->getKey())->not->toBeNull()
        ->and($contact->getKey())->not->toBeNull();
});

it('finds a contact by phone number however it was written down', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    // The column holds whatever a web page printed; the query is what a person reads off a phone.
    $report = app(PersonalDataLocator::class)->locate($team, '00442079460000');

    expect($report->matchedOn)->toBe('phone')
        ->and($report->counts()[SubjectDataCategory::Contact->value])->toBe(1);
});

it('finds a company named after the person, which a contacts-only search would miss', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    app(TeamContext::class)->run($team, fn () => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'company_name' => 'Dana Buyer Consulting',
    ]));

    $report = app(PersonalDataLocator::class)->locate($team, 'Dana Buyer');

    expect($report->matchedOn)->toBe('name')
        ->and($report->counts()[SubjectDataCategory::Lead->value])->toBe(1)
        ->and($report->counts()[SubjectDataCategory::Contact->value])->toBe(1);
});

it('includes the news signals Signals holds, and reports a converted one as retained', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = subjectLead($team, $user);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'subject_person_name' => 'Dana Buyer',
            'status' => SignalStatus::New,
        ]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'subject_person_name' => 'Dana Buyer',
            'status' => SignalStatus::Converted,
            'lead_id' => $lead->getKey(),
        ]);
    });

    $report = app(PersonalDataLocator::class)->locate($team, 'Dana Buyer');

    $signals = array_values(array_filter(
        $report->records,
        static fn ($record): bool => $record->category === SubjectDataCategory::Signal,
    ));

    expect($signals)->toHaveCount(2);

    // A converted signal is the provenance of a lead somebody is working, and §5a says it is
    // reported rather than erased — with the reason beside it.
    $retained = array_values(array_filter($signals, static fn ($record): bool => ! $record->erasable));

    expect($retained)->toHaveCount(1)
        ->and($retained[0]->retainedReason)->not->toBeNull();
});

it('reports a do-not-contact entry and refuses to call it erasable', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    app(SuppressionList::class)->suppress(
        SuppressionType::Email,
        'dana@acme.test',
        SuppressionScope::Global,
        SuppressionReason::OptOut,
    );

    $report = app(PersonalDataLocator::class)->locate($team, 'dana@acme.test');

    $entries = array_values(array_filter(
        $report->records,
        static fn ($record): bool => $record->category === SubjectDataCategory::Suppression,
    ));

    expect($report->suppressed)->toBeTrue()
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->erasable)->toBeFalse()
        // Only the mask — the table holds a hash and this must not be the place an address appears.
        ->and($entries[0]->detail)->toBe('d***@acme.test');
});

it('refuses a query too short to be about one person', function (): void {
    [, $team] = Fixtures::team();

    expect(fn () => app(PersonalDataLocator::class)->locate($team, 'a'))
        ->toThrow(ComplianceException::class);
});

it('never searches another team’s records', function (): void {
    [$userA, $teamA] = Fixtures::team();
    subjectLead($teamA, $userA);

    $teamB = Team::factory()->create();

    // A data-subject request is answered per controller: one team's records are not another's to
    // search, and a platform-wide search would disclose one customer's data to another (§5).
    $report = app(PersonalDataLocator::class)->locate($teamB, 'dana@acme.test');

    expect($report->total())->toBe(0);
});

// --- Erasure ------------------------------------------------------------------------------------

it('erases the contact, clears the message text and suppresses the identity', function (): void {
    [$user, $team] = Fixtures::team();
    [, $contact, $message, $attempt] = subjectLead($team, $user);

    $result = app(EraseSubjectDataAction::class)->handle($team, 'dana@acme.test', $user);

    expect($result['erased'][SubjectDataCategory::Contact->value])->toBe(1)
        ->and($result['suppressed'])->toBeTrue()
        ->and(LeadContact::withoutGlobalScopes()->whereKey($contact->getKey())->exists())->toBeFalse();

    $message = OutreachMessage::withoutGlobalScopes()->findOrFail($message->getKey());

    // The row stays — that something was sent is the team's accountability record — and holds no
    // personal data once the text and the address are gone.
    expect($message->body)->toBe('')
        ->and($message->sent_to)->toBeNull()
        ->and($attempt->refresh()->notes)->toBeNull();
});

it('writes an unliftable suppression, so the next search cannot reach the person again', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    app(EraseSubjectDataAction::class)->handle($team, 'dana@acme.test', $user);

    $entry = OutreachSuppression::query()
        ->where('reason', SuppressionReason::Erasure->value)
        ->sole();

    // Team scope, because the request was made to this controller — but unliftable, or the
    // safeguard that makes the erasure stick could be removed with a button.
    expect($entry->scope)->toBe(SuppressionScope::Team)
        ->and($entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->isRemovable())->toBeFalse()
        ->and(app(SuppressionList::class)->isEmailSuppressed('dana@acme.test', (int) $team->getKey()))->toBeTrue();
});

it('anonymises a company matched by name rather than deleting it', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'company_name' => 'Dana Buyer Consulting',
    ]));

    app(EraseSubjectDataAction::class)->handle($team, 'Dana Buyer', $user, [(int) $lead->getKey()]);

    // The match is a fuzzy one on a name, and deleting a real business on a fuzzy match would be
    // its own data-protection failure. The record stays, stripped.
    $lead = Lead::withoutGlobalScopes()->findOrFail($lead->getKey());

    expect($lead->anonymised_at)->not->toBeNull();
});

it('erases only the records confirmed on a name match', function (): void {
    [$user, $team] = Fixtures::team();

    // Two different people who happen to share a first name. One made the request.
    [$wanted, $bystander] = app(TeamContext::class)->run($team, function () use ($team): array {
        $lead = Lead::factory()->create(['team_id' => $team->getKey(), 'company_name' => 'Acme Ltd']);

        return [
            LeadContact::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'name' => 'John Smith',
                'email' => 'john.smith@acme.test',
            ]),
            LeadContact::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'name' => 'John Smithson',
                'email' => 'john.smithson@other.test',
            ]),
        ];
    });

    $result = app(EraseSubjectDataAction::class)->handle($team, 'John Smith', $user, [(int) $wanted->getKey()]);

    // Erasing an unrelated person on nobody's say-so would itself be a data-protection failure, so
    // a name match honours the tick rather than the query.
    expect($result['erased'][SubjectDataCategory::Contact->value])->toBe(1)
        ->and(LeadContact::withoutGlobalScopes()->whereKey($wanted->getKey())->exists())->toBeFalse()
        ->and(LeadContact::withoutGlobalScopes()->whereKey($bystander->getKey())->exists())->toBeTrue();
});

it('erases nothing on a name match with no selection', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    $result = app(EraseSubjectDataAction::class)->handle($team, 'Dana Buyer', $user, null);

    // A request that arrives without a selection is a request nobody chose. Deliberately not
    // "erase everything".
    expect(array_sum($result['erased']))->toBe(0)
        ->and(LeadContact::withoutGlobalScopes()->count())->toBe(1);
});

it('still erases the whole re-located set for an exact address', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    // No ids passed at all: an address is an exact identity, so everything matching it is the same
    // person's and belongs in the erasure — including a contact derived since the search.
    $result = app(EraseSubjectDataAction::class)->handle($team, 'dana@acme.test', $user, null);

    expect($result['erased'][SubjectDataCategory::Contact->value])->toBe(1);
});

it('keeps the erasure and its suppression in one transaction', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    // The suppression is what makes the erasure stick, so it must not be able to fail *after* the
    // data is gone — that would leave the person erased and re-contactable, which is worse than
    // doing nothing. The baseline is whatever `RefreshDatabase` already has open around the test.
    $baseline = DB::transactionLevel();
    $levelAtInsert = null;

    DB::beforeExecuting(function (string $query) use (&$levelAtInsert): void {
        if (str_contains($query, 'outreach_suppressions') && str_starts_with($query, 'insert')) {
            $levelAtInsert = DB::transactionLevel();
        }
    });

    app(EraseSubjectDataAction::class)->handle($team, 'dana@acme.test', $user);

    expect($levelAtInsert)->not->toBeNull()
        ->and($levelAtInsert)->toBeGreaterThan($baseline)
        ->and(OutreachSuppression::query()->where('reason', SuppressionReason::Erasure->value)->exists())->toBeTrue();
});

it('matches a name containing a LIKE wildcard as itself', function (): void {
    [$user, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $lead = Lead::factory()->create(['team_id' => $team->getKey(), 'company_name' => 'Acme Ltd']);

        foreach (['Dana_Buyer', 'DanaXBuyer'] as $name) {
            LeadContact::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'name' => $name,
                'email' => str_replace('_', '.', mb_strtolower($name)).'@acme.test',
            ]);
        }
    });

    // `_` is a single-character wildcard in SQL. Escaped, the search finds the underscore and not
    // the X — and the escape character is `!` rather than a backslash, because `ESCAPE '\'` is an
    // unterminated string literal in MySQL and would fail every query in production.
    $report = app(PersonalDataLocator::class)->locate($team, 'Dana_Buyer');

    expect($report->counts()[SubjectDataCategory::Contact->value])->toBe(1);
});

it('leaves the do-not-contact entry alone', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    app(SuppressionList::class)->suppress(
        SuppressionType::Email,
        'dana@acme.test',
        SuppressionScope::Global,
        SuppressionReason::OptOut,
    );

    $result = app(EraseSubjectDataAction::class)->handle($team, 'dana@acme.test', $user);

    expect($result['retained'])->toBeGreaterThan(0)
        ->and(OutreachSuppression::query()->where('reason', SuppressionReason::OptOut->value)->exists())->toBeTrue();
});

it('re-locates before erasing, so a record added since the search is caught', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = subjectLead($team, $user);

    // What the member was looking at.
    app(PersonalDataLocator::class)->locate($team, 'dana@acme.test');

    // An enrichment pass adds a second contact with the same address on another lead.
    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $second = Lead::factory()->create(['team_id' => $team->getKey(), 'company_name' => 'Acme Group']);

        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $second->getKey(),
            'email' => 'dana@acme.test',
        ]);

        expect($lead->getKey())->not->toBe($second->getKey());
    });

    $result = app(EraseSubjectDataAction::class)->handle($team, 'dana@acme.test', $user);

    expect($result['erased'][SubjectDataCategory::Contact->value])->toBe(2)
        ->and(LeadContact::withoutGlobalScopes()->where('email', 'dana@acme.test')->count())->toBe(0);
});

it('audits the erasure with counts and no identity', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    app(EraseSubjectDataAction::class)->handle($team, 'dana@acme.test', $user);

    $entry = AuditLog::query()->where('action', 'compliance.subject_data_erased')->sole();

    // A log of what we erased for privacy reasons must not be a copy of it (§3.9).
    expect($entry->user_id)->toBe($user->getKey())
        ->and($entry->properties['matched_on'])->toBe('email')
        ->and(json_encode($entry->properties))->not->toContain('dana@acme.test');
});

// --- The screen ---------------------------------------------------------------------------------

it('shows the search screen, and the report once a query is given', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    $this->actingAs($user)
        ->get(route('compliance.dsar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('compliance/Dsar')->where('report', null));

    $this->actingAs($user)
        ->get(route('compliance.dsar.index', ['query' => 'dana@acme.test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.matched_on', 'email')
            ->where('report.total', 3));
});

it('audits the search as well as the erasure', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    $this->actingAs($user)
        ->get(route('compliance.dsar.index', ['query' => 'dana@acme.test']))
        ->assertOk();

    // Looking up everything a team holds about a named person is privacy-relevant whether or not
    // anything is erased (§3.8).
    $entry = AuditLog::query()->where('action', 'compliance.subject_data_located')->sole();

    expect($entry->properties['results'])->toBe(3);
});

it('erases from the screen and returns to an empty search', function (): void {
    [$user, $team] = Fixtures::team();
    subjectLead($team, $user);

    $this->actingAs($user)
        ->delete(route('compliance.dsar.erase'), ['query' => 'dana@acme.test', 'confirm' => true])
        ->assertRedirect(route('compliance.dsar.index'));

    expect(LeadContact::withoutGlobalScopes()->count())->toBe(0);
});

it('forbids a plain member from searching or erasing', function (): void {
    [$owner, $team] = Fixtures::team();
    subjectLead($team, $owner);

    $member = Fixtures::member($team, TeamRole::Member);

    $this->actingAs($member)->get(route('compliance.dsar.index'))->assertForbidden();

    $this->actingAs($member)
        ->delete(route('compliance.dsar.erase'), ['query' => 'dana@acme.test', 'confirm' => true])
        ->assertForbidden();

    expect(LeadContact::withoutGlobalScopes()->count())->toBe(1);
});

/**
 * A lead with a contact the subject owns, a sent message to them, and a logged approach.
 *
 * @return array{Lead, LeadContact, OutreachMessage, ContactAttempt}
 */
function subjectLead($team, $user): array
{
    return app(TeamContext::class)->run($team, function () use ($team, $user): array {
        $lead = Lead::factory()->create(['team_id' => $team->getKey(), 'company_name' => 'Acme Ltd']);

        $contact = LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Dana Buyer',
            'email' => 'dana@acme.test',
            'phone' => '+44 20 7946 0000',
        ]);

        $message = OutreachMessage::factory()
            ->forContact((int) $contact->getKey())
            ->sent('dana@acme.test')
            ->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'created_by' => $user->getKey(),
            ]);

        $attempt = Fixtures::attempt($team, $lead, now()->subDays(3), (int) $contact->getKey());

        return [$lead, $contact, $message, $attempt];
    });
}
