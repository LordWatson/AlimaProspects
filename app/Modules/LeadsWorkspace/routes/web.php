<?php

declare(strict_types=1);

use App\Modules\LeadsWorkspace\Http\Controllers\Web\LeadCollaborationController;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\LeadController;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\LeadTransferController;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\PipelineController;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\SavedViewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');

    // One lead, typed in by hand — a company a member met rather than one Alima found. Any member
    // but Read-only (`LeadPolicy::create`), unlike the bulk import below.
    Route::get('leads/create', [LeadController::class, 'create'])->name('leads.create');
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store');

    // The board.
    Route::get('leads/pipeline', [PipelineController::class, 'index'])->name('leads.pipeline');
    Route::post('leads/pipeline/stages', [PipelineController::class, 'store'])->name('leads.stages.store');
    Route::put('leads/pipeline/stages/order', [PipelineController::class, 'reorder'])->name('leads.stages.reorder');
    Route::put('leads/pipeline/stages/{stage}', [PipelineController::class, 'update'])
        ->whereNumber('stage')
        ->name('leads.stages.update');
    Route::delete('leads/pipeline/stages/{stage}', [PipelineController::class, 'destroy'])
        ->whereNumber('stage')
        ->name('leads.stages.destroy');

    // Saved views.
    Route::post('leads/views', [SavedViewController::class, 'store'])->name('leads.views.store');
    Route::delete('leads/views/{view}', [SavedViewController::class, 'destroy'])
        ->whereNumber('view')
        ->name('leads.views.destroy');

    // In and out in bulk. Import and export are Owner/Admin and metered by their own rate limiter,
    // because either can move a team's whole lead list in one request.
    Route::post('leads/bulk', [LeadTransferController::class, 'bulk'])
        ->middleware('throttle:leads-bulk')
        ->name('leads.bulk');
    Route::get('leads/import', [LeadTransferController::class, 'importForm'])->name('leads.import');
    Route::post('leads/import', [LeadTransferController::class, 'import'])
        ->middleware('throttle:leads-bulk')
        ->name('leads.import.store');
    // `capability:csv_export` (Phase 19b): taking the data out is a plan feature. Only the
    // export is gated, not the import — a team on any plan should be able to bring its own data in,
    // and gating that would be charging somebody for the privilege of giving us their list.
    Route::get('leads/export', [LeadTransferController::class, 'export'])
        ->middleware(['throttle:leads-bulk', 'capability:csv_export'])
        ->name('leads.export');

    // Notes and tasks are addressed by their own id, not the lead's — they are already team-scoped,
    // and carrying the lead in the URL would just be a second thing to keep in step.
    Route::delete('leads/notes/{note}', [LeadCollaborationController::class, 'destroyNote'])
        ->whereNumber('note')
        ->name('leads.notes.destroy');
    Route::put('leads/tasks/{task}', [LeadCollaborationController::class, 'completeTask'])
        ->whereNumber('task')
        ->name('leads.tasks.complete');
    Route::delete('leads/tasks/{task}', [LeadCollaborationController::class, 'destroyTask'])
        ->whereNumber('task')
        ->name('leads.tasks.destroy');

    // The lead itself, and the team's work on it. Declared after every literal path above, and
    // numeric-only, so neither can swallow the other however the file is re-ordered later.
    Route::get('leads/{lead}', [LeadController::class, 'show'])
        ->whereNumber('lead')
        ->name('leads.show');

    Route::prefix('leads/{lead}')->whereNumber('lead')->group(function (): void {
        Route::put('stage', [PipelineController::class, 'move'])->name('leads.stage.move');
        Route::put('tags', [LeadCollaborationController::class, 'tags'])->name('leads.tags.sync');
        Route::put('assignees', [LeadCollaborationController::class, 'assignees'])->name('leads.assignees.set');
        Route::post('notes', [LeadCollaborationController::class, 'storeNote'])->name('leads.notes.store');
        Route::post('tasks', [LeadCollaborationController::class, 'storeTask'])->name('leads.tasks.store');
    });
});
