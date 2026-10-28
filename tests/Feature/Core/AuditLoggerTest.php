<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes an immutable audit entry scoped to the active team', function (): void {
    $team = Team::factory()->create();
    app(TeamContext::class)->set($team);

    $log = app(AuditLogger::class)->log('team.created', $team, ['name' => $team->name]);

    expect($log->team_id)->toBe($team->id)
        ->and($log->action)->toBe('team.created')
        ->and($log->subject_type)->toBe(Team::class)
        ->and($log->subject_id)->toBe($team->id)
        ->and($log->properties)->toBe(['name' => $team->name]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'team.created',
        'team_id' => $team->id,
        'subject_id' => $team->id,
    ]);
});
