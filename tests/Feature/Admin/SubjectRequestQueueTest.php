<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Compliance\Enums\SubjectRequestStatus;
use App\Modules\Compliance\Enums\SubjectRequestType;
use App\Modules\Compliance\Models\DataSubjectRequest;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The public data-subject request queue in the admin panel (PL5).
 *
 * Two routes, and most of this file is about what is **not** there. The queue can close a request
 * and cannot decide whether to honour one: the suppression and the purge both happen at
 * verification, and an operator who could choose not to suppress would be a compliance hole with a
 * permission on it. And an unverified request never appears, because a list of addresses anybody can
 * type into a public form is the attack the verification step prevents.
 */
function verifiedRequest(SubjectRequestType $type = SubjectRequestType::Access): DataSubjectRequest
{
    $request = DataSubjectRequest::query()->create([
        'type' => $type->value,
        'status' => SubjectRequestStatus::Open->value,
        'email' => 'dana@example.com',
        'name' => 'Dana Example',
    ]);

    $request->forceFill([
        'verified_at' => now()->subDay(),
        'due_at' => now()->addDays(29),
    ])->save();

    return $request;
}

it('lists verified requests to an operator who may write platform data', function (): void {
    $request = verifiedRequest();

    $this->actingAs(Fixtures::operator(AdminRole::SuperAdmin))
        ->get('/admin/subject-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/compliance/Requests')
            ->where('requests.0.id', (int) $request->getKey())
            ->where('requests.0.status', SubjectRequestStatus::Open->value));
});

it('never lists an unverified request', function (): void {
    // Nothing has happened to it and nobody has proved it is theirs. Showing it here would move
    // the attack the verification prevents one screen along.
    DataSubjectRequest::query()->create([
        'type' => SubjectRequestType::Erasure->value,
        'status' => SubjectRequestStatus::PendingVerification->value,
        'email' => 'someone@example.com',
    ]);

    $this->actingAs(Fixtures::operator())
        ->get('/admin/subject-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('requests', []));
});

it('audits the read, because the rows name people', function (): void {
    verifiedRequest();

    $this->actingAs(Fixtures::operator())->get('/admin/subject-requests')->assertOk();

    expect(AuditLog::query()->where('action', AdminAudit::RESOURCE_VIEWED)->count())->toBe(1);
});

it('refuses an operator without platform.write', function (): void {
    verifiedRequest();

    // Support deliberately does not hold it: fixing a customer's record is a different act from
    // closing a statutory request made to us (`config/admin.php`).
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->get('/admin/subject-requests')
        ->assertForbidden();
});

it('answers 404 to a customer who guesses the path', function (): void {
    [, $team] = Fixtures::tenant();

    // 404 rather than 403 everywhere in the panel: a 403 would confirm the route is there.
    $this->actingAs(Fixtures::member($team))
        ->get('/admin/subject-requests')
        ->assertNotFound();

    // And a guest gets the same 404 rather than a login redirect, because `EnsurePlatformAdmin`
    // answers before `auth` has anything to say about where to send them.
    $this->get('/admin/subject-requests')->assertNotFound();
});

it('closes a request and records who did it', function (): void {
    $request = verifiedRequest();
    $operator = Fixtures::operator();

    $this->actingAs($operator)
        ->patch('/admin/subject-requests/'.$request->getKey(), [
            'status' => SubjectRequestStatus::Completed->value,
            'note' => 'Answered by email with everything we hold.',
        ])->assertRedirect();

    $request->refresh();

    expect($request->status)->toBe(SubjectRequestStatus::Completed)
        ->and($request->handled_by)->toBe($operator->getKey())
        ->and($request->completed_at)->not->toBeNull();
});

it('will not accept a status that is not an outcome', function (): void {
    $request = verifiedRequest();

    // No route back to `open`: a request that has been answered has been answered, and re-opening
    // one would reset a statutory clock that has already run.
    $this->actingAs(Fixtures::operator())
        ->patch('/admin/subject-requests/'.$request->getKey(), [
            'status' => SubjectRequestStatus::PendingVerification->value,
        ])->assertSessionHasErrors('status');

    expect($request->refresh()->status)->toBe(SubjectRequestStatus::Open);
});

it('will not accept a rejection with no reason', function (): void {
    $request = verifiedRequest();

    $this->actingAs(Fixtures::operator())
        ->patch('/admin/subject-requests/'.$request->getKey(), [
            'status' => SubjectRequestStatus::Rejected->value,
        ])->assertSessionHasErrors('note');

    expect($request->refresh()->status)->toBe(SubjectRequestStatus::Open);
});

it('refuses the write to an operator without platform.write', function (): void {
    $request = verifiedRequest();

    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->patch('/admin/subject-requests/'.$request->getKey(), [
            'status' => SubjectRequestStatus::Completed->value,
        ])->assertForbidden();

    expect($request->refresh()->status)->toBe(SubjectRequestStatus::Open);
});

it('offers no route that suppresses or purges', function (): void {
    // The absence is the decision, asserted over the route table rather than trusted to a comment:
    // both already happened at verification, and an operator who could choose not to suppress
    // would be a compliance hole with a permission on it.
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'admin.subject-requests.'))
        ->values()
        ->all();

    expect($names)->toEqualCanonicalizing([
        'admin.subject-requests.index',
        'admin.subject-requests.update',
    ]);
});
