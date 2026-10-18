<?php

use App\Modules\Workflow\Http\Controllers\WorkflowApiController;
use Illuminate\Support\Facades\Route;

/*
| Workflow & Automation — versioned API (PRS §21). Sanctum token; the token user's workflow.*
| permissions and task assignments apply exactly as on the screens. Rate limited per token.
*/
Route::middleware(['auth:sanctum', 'module.active:workflow', 'throttle:120,1'])
    ->prefix('v1/workflow')->name('api.workflow.')
    ->group(function () {
        Route::post('/instances', [WorkflowApiController::class, 'start'])->name('instances.start');
        Route::get('/instances/{instance}', [WorkflowApiController::class, 'show'])->name('instances.show')->whereUuid('instance');
        Route::post('/instances/{instance}/comments', [WorkflowApiController::class, 'comment'])->name('instances.comment')->whereUuid('instance');
        Route::post('/instances/{instance}/cancel', [WorkflowApiController::class, 'cancel'])->name('instances.cancel')->whereUuid('instance');
        Route::get('/instances/{instance}/audit', [WorkflowApiController::class, 'audit'])->name('instances.audit')->whereUuid('instance');
        Route::get('/tasks', [WorkflowApiController::class, 'tasks'])->name('tasks.index');
        Route::post('/tasks/{task}/claim', [WorkflowApiController::class, 'claim'])->name('tasks.claim')->whereUuid('task');
        Route::post('/tasks/{task}/decision', [WorkflowApiController::class, 'decide'])->name('tasks.decide')->whereUuid('task');
        Route::post('/events', [WorkflowApiController::class, 'event'])->name('events.publish');
        Route::post('/action-runs/{run}/retry', [WorkflowApiController::class, 'retry'])->name('actions.retry')->whereNumber('run');
    });
