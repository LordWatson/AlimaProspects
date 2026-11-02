<?php

declare(strict_types=1);

use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Core\Models\AuditLog;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * The generic write path (Phase 19a).
 *
 * The claim worth testing is not "a form saves a field" — it is the three limits that keep the
 * registry from becoming a second, unvalidated write path around the module Actions:
 *
 *  - only declared fields are written, whatever the browser posts;
 *  - delete is refused unless the descriptor opted in, which is the default;
 *  - every write leaves an audit entry stamped with the tenant, carrying the names of what changed
 *    and not the values.
 */

it('updates a declared field and audits the change', function (): void {
    [, $team] = Fixtures::tenant();
    $lead = Fixtures::lead($team, 'Acme Limted');

    $this->actingAs(Fixtures::operator())
        ->patch('/admin/resources/leads/'.$lead->getKey(), [
            'company_name' => 'Acme Limited',
            'status' => $lead->status->value,
        ])
        ->assertRedirect('/admin/resources/leads/'.$lead->getKey());

    expect($lead->refresh()->company_name)->toBe('Acme Limited');

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::RECORD_UPDATED)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->properties['resource'])->toBe('leads')
        ->and($entry->properties['changed'])->toContain('company_name')
        // The names of what changed, never the values — an audit entry about a correction to
        // somebody's record must not be a copy of the record.
        ->and(json_encode($entry->properties))->not->toContain('Acme Limited');
});

it('ignores a posted field the descriptor never declared', function (): void {
    [, $team] = Fixtures::tenant();
    $lead = Fixtures::lead($team, 'Acme Ltd');
    $originalDedupe = $lead->dedupe_key;

    $this->actingAs(Fixtures::operator())
        ->patch('/admin/resources/leads/'.$lead->getKey(), [
            'company_name' => 'Acme Ltd',
            'status' => $lead->status->value,
            // Not in `fields()`. It is a real column, and on `$fillable` — which is exactly why
            // the payload being built from the descriptor rather than the request matters.
            'dedupe_key' => 'tampered',
            'team_id' => 999999,
        ])
        ->assertRedirect();

    expect($lead->refresh()->dedupe_key)->toBe($originalDedupe)
        ->and((int) $lead->team_id)->toBe((int) $team->getKey());
});

it('validates against the rules the descriptor declared', function (): void {
    [, $team] = Fixtures::tenant();
    $lead = Fixtures::lead($team, 'Acme Ltd');

    $this->actingAs(Fixtures::operator())
        ->patch('/admin/resources/leads/'.$lead->getKey(), [
            'company_name' => '',
            'status' => $lead->status->value,
        ])
        ->assertSessionHasErrors('company_name');

    expect($lead->refresh()->company_name)->toBe('Acme Ltd');
});

it('refuses a delete the descriptor did not opt into', function (): void {
    [, $team] = Fixtures::tenant();
    $lead = Fixtures::lead($team, 'Acme Ltd');

    // A lead has contacts, scores, notes, tasks, activities, messages and attempts hanging off it,
    // and the workspace's own bulk delete is what knows the order to take them down in.
    $this->withoutExceptionHandling()
        ->actingAs(Fixtures::operator())
        ->delete('/admin/resources/leads/'.$lead->getKey());
})->throws(AdminException::class, 'cannot be deleted from the admin panel');

it('deletes where the descriptor did opt in, and audits it', function (): void {
    [, $team] = Fixtures::tenant();
    $tag = LeadTag::factory()->create(['team_id' => $team->getKey(), 'name' => 'Warm']);

    $this->actingAs(Fixtures::operator())
        ->delete('/admin/resources/lead-tags/'.$tag->getKey())
        ->assertRedirect('/admin/resources/lead-tags');

    expect(LeadTag::query()->withoutGlobalScopes()->whereKey($tag->getKey())->exists())->toBeFalse();

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::RECORD_DELETED)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->properties['record_id'])->toBe((int) $tag->getKey());
});

it('creates a tenant-owned record with the tenant named explicitly', function (): void {
    [, $team] = Fixtures::tenant();

    // The panel runs outside every tenant, so `BelongsToTeam`'s auto-assign has nothing to assign:
    // a row created without one would belong to nobody and be invisible to the customer it was
    // made for. The form makes it a required choice for exactly that reason.
    $this->actingAs(Fixtures::operator())
        ->post('/admin/resources/icps', [
            'name' => 'Mid-market logistics',
            'description' => 'Set up on the onboarding call.',
            'team_id' => $team->getKey(),
        ])
        ->assertRedirect();

    $icp = Icp::query()->withoutGlobalScopes()->where('name', 'Mid-market logistics')->first();

    expect($icp)->not->toBeNull()
        ->and((int) $icp->team_id)->toBe((int) $team->getKey());
});

it('refuses a create naming a tenant that does not exist', function (): void {
    Fixtures::tenant();

    // A form error rather than a foreign-key violation: a mistyped id is an ordinary typo, and a
    // 500 is the wrong answer to one.
    $this->actingAs(Fixtures::operator())
        ->post('/admin/resources/icps', ['name' => 'Logistics', 'team_id' => 999999])
        ->assertSessionHasErrors('team_id');

    expect(Icp::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a create with no tenant named at all', function (): void {
    Fixtures::tenant();

    // The panel runs outside every tenant, so a row created without one belongs to nobody and is
    // invisible to the customer it was made for.
    $this->actingAs(Fixtures::operator())
        ->post('/admin/resources/icps', ['name' => 'Logistics'])
        ->assertSessionHasErrors('team_id');

    expect(Icp::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a create where the table needs a column no field declares', function (): void {
    [, $team] = Fixtures::tenant();

    // `lead-tags` judges uniqueness on a derived `slug`, and deriving a column is logic a
    // declarative descriptor must not grow — so create is off, and the writer refuses it whatever
    // a form posts.
    $this->withoutExceptionHandling()
        ->actingAs(Fixtures::operator())
        ->post('/admin/resources/lead-tags', ['name' => 'Partner', 'team_id' => $team->getKey()]);
})->throws(AdminException::class, 'read-only in the admin panel');

it('refuses a create on a read-only resource', function (): void {
    Fixtures::tenant();

    // `ai-requests` is the AI spend ledger: append-only, because a team's allowance, its usage
    // meter and (from Phase 19c) its margin are all computed from it.
    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/ai-requests/create')
        ->assertForbidden();
});

it('offers no edit screen for a read-only resource', function (): void {
    [, $team] = Fixtures::tenant();
    $lead = Fixtures::lead($team, 'Acme Ltd');

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/lead-contacts/'.$lead->getKey().'/edit')
        ->assertForbidden();
});

it('shows the detail columns on a record page and not on the index', function (): void {
    [, $team] = Fixtures::tenant();
    $lead = Fixtures::lead($team, 'Acme Ltd');

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads/'.$lead->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('record.dedupe_key')
            ->has('record.company_name'));

    $this->actingAs(Fixtures::operator())
        ->get('/admin/resources/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('rows.0.cells.dedupe_key'));
});
