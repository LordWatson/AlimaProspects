<?php

declare(strict_types=1);

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('auto-assigns team_id and scopes tenant models to the active team', function (): void {
    $context = app(TeamContext::class);

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $context->set($teamA);
    $logA = AuditLog::query()->create(['action' => 'a.created']);

    $context->set($teamB);
    $logB = AuditLog::query()->create(['action' => 'b.created']);

    // team_id is auto-assigned from the active team context.
    expect($logA->team_id)->toBe($teamA->id)
        ->and($logB->team_id)->toBe($teamB->id);

    // Under team B, only team B's records are visible.
    expect(AuditLog::query()->pluck('id')->all())->toBe([$logB->id]);

    // Cross-tenant access fails: team A's record is invisible under team B.
    expect(AuditLog::query()->find($logA->id))->toBeNull();

    // Switching back to team A reveals only team A's records.
    $context->set($teamA);
    expect(AuditLog::query()->pluck('id')->all())->toBe([$logA->id]);
});

it('does not scope when no team is active', function (): void {
    $context = app(TeamContext::class);
    $context->forget();

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $context->run($teamA, fn () => AuditLog::query()->create(['action' => 'a']));
    $context->run($teamB, fn () => AuditLog::query()->create(['action' => 'b']));

    $context->forget();

    // Unscoped context (console/migrations) sees everything; Policies are the backstop.
    expect(AuditLog::query()->count())->toBe(2);
});
