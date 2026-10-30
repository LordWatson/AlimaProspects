<?php

declare(strict_types=1);

use App\Modules\LeadsWorkspace\Http\Controllers\Api\V1\LeadController;
use App\Modules\LeadsWorkspace\Http\Controllers\Api\V1\LeadWorkController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');

    // One lead, typed in by hand — the phone's version of the add-a-lead form, which is where this
    // mostly happens: a member at an event with a business card.
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store');

    // The board, so a mobile stage picker knows what the columns are. Declared before
    // `leads/{lead}`, which is numeric-only, so neither can swallow the other.
    Route::get('leads/stages', [LeadWorkController::class, 'stages'])->name('leads.stages');

    Route::get('leads/{lead}', [LeadController::class, 'show'])
        ->whereNumber('lead')
        ->name('leads.show');

    // The team's work on a lead (Phase 10b) — the mobile app's stage move, note, task, tags and
    // assignment, through the same Actions the web controllers use.
    Route::prefix('leads/{lead}')->whereNumber('lead')->group(function (): void {
        Route::put('stage', [LeadWorkController::class, 'move'])->name('leads.stage.move');
        Route::put('tags', [LeadWorkController::class, 'tags'])->name('leads.tags.sync');
        Route::put('assignees', [LeadWorkController::class, 'assignees'])->name('leads.assignees.set');
        Route::post('notes', [LeadWorkController::class, 'storeNote'])->name('leads.notes.store');
        Route::post('tasks', [LeadWorkController::class, 'storeTask'])->name('leads.tasks.store');
    });

    Route::put('leads/tasks/{task}', [LeadWorkController::class, 'completeTask'])
        ->whereNumber('task')
        ->name('leads.tasks.complete');
});
