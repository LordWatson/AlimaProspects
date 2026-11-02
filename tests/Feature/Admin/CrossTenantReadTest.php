<?php

declare(strict_types=1);

use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * The panel reads across tenants, and every read of tenant data leaves a trail (Phase 19a).
 *
 * Two properties, and they pull in opposite directions, which is why both are tested here rather
 * than separately. The index has to see **more than one tenant**, because that is the whole point of
 * an operations tool. The drill-down has to see **exactly one**, because an operator answering a
 * ticket about one business should not be looking at another's. A bug in either direction is
 * invisible from a single-tenant test.
 */

/**
 * Audit entries written for one action, newest first, read without the tenant scope.
 *
 * @return Collection<int, AuditLog>
 */
function adminAuditEntries(string $action): Collection
{
    return AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', $action)
        ->orderByDesc('id')
        ->get();
}

it('reads across every tenant on an index', function (): void {
    [, $alpha] = Fixtures::tenant('Alpha Ltd');
    [, $beta] = Fixtures::tenant('Beta Ltd');

    Fixtures::lead($alpha, 'Alpha Prospect');
    Fixtures::lead($beta, 'Beta Prospect');

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/resources/Index')
            ->where('meta.total', 2));
});

it('narrows to one tenant when asked, and nothing leaks in', function (): void {
    [, $alpha] = Fixtures::tenant('Alpha Ltd');
    [, $beta] = Fixtures::tenant('Beta Ltd');

    Fixtures::lead($alpha, 'Alpha Prospect');
    Fixtures::lead($beta, 'Beta Prospect One');
    Fixtures::lead($beta, 'Beta Prospect Two');

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads?team_id='.$beta->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('meta.total', 2));
});

it('leaves the request outside every tenant', function (): void {
    // The mechanism, asserted directly. `BelongsToTeam`'s global scope is a no-op when no team is
    // active, and clearing the context is what makes the index above possible — so a regression
    // that left the operator's own team in context would quietly narrow every list in the panel.
    [, $team] = Fixtures::tenant();
    Fixtures::lead($team, 'Prospect');

    $operator = Fixtures::operator();

    $this->actingAs($operator)->get('/admin/resources/leads')->assertOk();

    expect(app(TeamContext::class)->id())->toBeNull();
});

it('stamps an audited read with the tenant it was about', function (): void {
    [, $alpha] = Fixtures::tenant('Alpha Ltd');
    Fixtures::lead($alpha, 'Alpha Prospect');

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads?team_id='.$alpha->getKey())
        ->assertOk();

    $entry = adminAuditEntries(AdminAudit::RESOURCE_VIEWED)->first();

    // The entry has to carry the **target** team, not the operator's (they have none). Without it
    // every admin entry lands with `team_id = null` and the per-tenant audit view — the one a
    // customer's support ticket is answered from — shows nothing at all.
    expect($entry)->not->toBeNull()
        ->and((int) $entry->team_id)->toBe((int) $alpha->getKey())
        ->and($entry->properties['resource'])->toBe('leads')
        ->and($entry->properties['results'])->toBe(1);
});

it('records an unfiltered read against no tenant, which is the truth', function (): void {
    Fixtures::tenant();

    $this->actingAs(Fixtures::operator())->get('/admin/resources/leads')->assertOk();

    $entry = adminAuditEntries(AdminAudit::RESOURCE_VIEWED)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->team_id)->toBeNull();
});

it('audits opening one record against that record’s tenant', function (): void {
    [, $team] = Fixtures::tenant();
    $lead = Fixtures::lead($team, 'Acme Ltd');

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads/'.$lead->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/resources/Show'));

    $entry = adminAuditEntries(AdminAudit::RECORD_VIEWED)->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->subject_id)->toBe((int) $lead->getKey());
});

it('never puts the search term or the rows into the audit entry', function (): void {
    [, $team] = Fixtures::tenant();
    Fixtures::lead($team, 'Very Distinctive Company Name Ltd');

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads?search=jane@acme.test')
        ->assertOk();

    $entry = adminAuditEntries(AdminAudit::RESOURCE_VIEWED)->first();

    // A log of who looked at personal data must not itself be a copy of it — and the **search
    // term** is the one field here that can be personal data, because operators search by email
    // address and by name. `searched` records that a term was used, which is what an audit reader
    // needs; the term itself would put `jane@acme.test` into an append-only table that Compliance's
    // retention sweep does not reach.
    expect($entry->properties)->toHaveKeys(['resource', 'results', 'searched'])
        ->and($entry->properties['searched'])->toBeTrue()
        ->and($entry->properties)->not->toHaveKey('search')
        ->and(json_encode($entry->properties))->not->toContain('jane@acme.test');
});

it('searches without letting a wildcard match everything by accident', function (): void {
    [, $team] = Fixtures::tenant();
    Fixtures::lead($team, 'Acme Ltd');
    Fixtures::lead($team, 'Globex Ltd');

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads?search=Acme')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('meta.total', 1));
});

it('refuses a page size beyond the ceiling', function (): void {
    Fixtures::tenant();

    // An unbounded page is a way to ask the database to perform an export nobody authorised — and
    // on a cross-tenant table that is every tenant's data in one response.
    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads?per_page=100000')
        ->assertSessionHasErrors('per_page');
});
