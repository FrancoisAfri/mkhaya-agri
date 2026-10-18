<?php

use App\Http\Controllers\ModuleHubController;
use App\Modules\Workflow\Http\Controllers\WorkflowAdminController;
use App\Modules\Workflow\Http\Controllers\WorkflowAiController;
use App\Modules\Workflow\Http\Controllers\WorkflowDashboardController;
use App\Modules\Workflow\Http\Controllers\WorkflowDefinitionController;
use App\Modules\Workflow\Http\Controllers\WorkflowDelegationController;
use App\Modules\Workflow\Http\Controllers\WorkflowFormController;
use App\Modules\Workflow\Http\Controllers\WorkflowInstanceController;
use App\Modules\Workflow\Http\Controllers\WorkflowOperationsController;
use App\Modules\Workflow\Http\Controllers\WorkflowRequestController;
use App\Modules\Workflow\Http\Controllers\WorkflowTaskController;
use App\Modules\Workflow\Support\WorkflowHubs;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workflow & Automation
|--------------------------------------------------------------------------
|
| Gated by module entitlement and hub tier; within that, every action checks its own workflow.*
| permission in the controller, and task actions check that the person is the assignee or an
| active delegate. Records bind on uuid (constrained, so a malformed address never reaches the
| database); tenant data is isolated by row-level security.
*/

Route::middleware(['module.active:workflow', 'module.tier:workflow'])
    ->prefix('workflow')->name('workflow.')
    ->group(function () {
        Route::get('/', [WorkflowTaskController::class, 'myWork'])->name('my_work');

        foreach (WorkflowHubs::hubs() as $group => $hub) {
            Route::get('/' . basename($hub['path']), [ModuleHubController::class, 'show'])
                ->defaults('module', 'workflow')->defaults('group', $group)->name('hub.' . $group)
                ->middleware('can:' . WorkflowPermissions::VIEW);
        }

        // Requests
        Route::get('/start', [WorkflowRequestController::class, 'catalog'])->name('start');
        Route::get('/start/{definition}', [WorkflowRequestController::class, 'create'])->name('start.form')->whereUuid('definition');
        Route::post('/start/{definition}', [WorkflowRequestController::class, 'store'])->name('start.store')->whereUuid('definition');
        Route::post('/start/{definition}/draft', [WorkflowRequestController::class, 'saveDraft'])->name('start.draft')->whereUuid('definition');
        Route::post('/attachments', [WorkflowRequestController::class, 'upload'])->name('attachments.upload');
        Route::get('/requests', [WorkflowRequestController::class, 'mine'])->name('requests');
        Route::get('/people', [WorkflowRequestController::class, 'searchPeople'])->name('people');

        // Running requests
        Route::get('/instances', [WorkflowInstanceController::class, 'index'])->name('instances.index');
        Route::get('/instances/{instance}', [WorkflowInstanceController::class, 'show'])->name('instances.show')->whereUuid('instance');
        Route::post('/instances/{instance}/cancel', [WorkflowInstanceController::class, 'cancel'])->name('instances.cancel')->whereUuid('instance');
        Route::post('/instances/{instance}/comments', [WorkflowInstanceController::class, 'comment'])->name('instances.comment')->whereUuid('instance');
        Route::post('/instances/{instance}/respond', [WorkflowInstanceController::class, 'respond'])->name('instances.respond')->whereUuid('instance');
        Route::post('/instances/{instance}/attachments', [WorkflowInstanceController::class, 'attach'])->name('instances.attach')->whereUuid('instance');

        // Tasks
        Route::get('/tasks/{task}', [WorkflowTaskController::class, 'show'])->name('tasks.show')->whereUuid('task');
        Route::post('/tasks/{task}/decide', [WorkflowTaskController::class, 'decide'])->name('tasks.decide')->whereUuid('task');
        Route::post('/tasks/{task}/claim', [WorkflowTaskController::class, 'claim'])->name('tasks.claim')->whereUuid('task');
        Route::post('/tasks/{task}/release', [WorkflowTaskController::class, 'release'])->name('tasks.release')->whereUuid('task');
        Route::post('/tasks/{task}/reassign', [WorkflowTaskController::class, 'reassign'])->name('tasks.reassign')->whereUuid('task');
        Route::post('/tasks/{task}/summary', [WorkflowAiController::class, 'summarise'])->name('tasks.summary')->whereUuid('task');

        // Design
        Route::get('/definitions', [WorkflowDefinitionController::class, 'index'])->name('definitions.index');
        Route::post('/definitions', [WorkflowDefinitionController::class, 'store'])->name('definitions.store');
        Route::post('/definitions/import', [WorkflowDefinitionController::class, 'import'])->name('definitions.import');
        Route::post('/definitions/ai-draft', [WorkflowAiController::class, 'draft'])->name('definitions.ai_draft');
        Route::get('/definitions/{definition}', [WorkflowDefinitionController::class, 'show'])->name('definitions.show')->whereUuid('definition');
        Route::patch('/definitions/{definition}', [WorkflowDefinitionController::class, 'update'])->name('definitions.update')->whereUuid('definition');
        Route::get('/definitions/{definition}/designer', [WorkflowDefinitionController::class, 'designer'])->name('definitions.designer')->whereUuid('definition');
        Route::post('/definitions/{definition}/draft', [WorkflowDefinitionController::class, 'saveDraft'])->name('definitions.draft')->whereUuid('definition');
        Route::post('/definitions/{definition}/validate', [WorkflowDefinitionController::class, 'validateDraft'])->name('definitions.validate')->whereUuid('definition');
        Route::post('/definitions/{definition}/submit', [WorkflowDefinitionController::class, 'submit'])->name('definitions.submit')->whereUuid('definition');
        Route::post('/definitions/{definition}/withdraw', [WorkflowDefinitionController::class, 'withdraw'])->name('definitions.withdraw')->whereUuid('definition');
        Route::post('/definitions/{definition}/review', [WorkflowDefinitionController::class, 'review'])->name('definitions.review')->whereUuid('definition');
        Route::post('/definitions/{definition}/status', [WorkflowDefinitionController::class, 'status'])->name('definitions.status')->whereUuid('definition');
        Route::post('/definitions/{definition}/discard', [WorkflowDefinitionController::class, 'discard'])->name('definitions.discard')->whereUuid('definition');
        Route::post('/definitions/{definition}/rollback/{version}', [WorkflowDefinitionController::class, 'rollback'])->name('definitions.rollback')->whereUuid(['definition', 'version']);
        Route::get('/definitions/{definition}/compare', [WorkflowDefinitionController::class, 'compare'])->name('definitions.compare')->whereUuid('definition');
        Route::get('/definitions/{definition}/export', [WorkflowDefinitionController::class, 'export'])->name('definitions.export')->whereUuid('definition');
        Route::post('/definitions/{definition}/migrate', [WorkflowDefinitionController::class, 'migrate'])->name('definitions.migrate')->whereUuid('definition');
        Route::post('/definitions/{definition}/explain', [WorkflowAiController::class, 'explain'])->name('definitions.explain')->whereUuid('definition');

        Route::get('/forms', [WorkflowFormController::class, 'index'])->name('forms.index');
        Route::post('/forms', [WorkflowFormController::class, 'store'])->name('forms.store');
        Route::get('/forms/library', [WorkflowFormController::class, 'library'])->name('forms.library');
        Route::post('/forms/library/{key}', [WorkflowFormController::class, 'installFromLibrary'])->name('forms.library.install')->where('key', '[a-z0-9_]{1,120}');
        Route::get('/forms/{form}', [WorkflowFormController::class, 'show'])->name('forms.show')->whereUuid('form');
        Route::post('/forms/{form}/versions', [WorkflowFormController::class, 'saveVersion'])->name('forms.save')->whereUuid('form');
        Route::post('/forms/ai-suggest', [WorkflowAiController::class, 'suggestFields'])->name('forms.ai_suggest');

        Route::get('/templates', [WorkflowDefinitionController::class, 'templates'])->name('templates.index');
        Route::post('/templates/{key}/install', [WorkflowDefinitionController::class, 'installTemplate'])->name('templates.install')->where('key', '[a-z_]{1,60}');

        // Delegation
        Route::get('/delegations', [WorkflowDelegationController::class, 'index'])->name('delegations.index');
        Route::post('/delegations', [WorkflowDelegationController::class, 'store'])->name('delegations.store');
        Route::post('/delegations/{delegation}/revoke', [WorkflowDelegationController::class, 'revoke'])->name('delegations.revoke')->whereUuid('delegation');

        // Operations
        Route::get('/dashboard', [WorkflowDashboardController::class, 'index'])->name('dashboard');
        Route::get('/reports/export', [WorkflowDashboardController::class, 'export'])->name('reports.export');
        Route::post('/reports/ask', [WorkflowAiController::class, 'ask'])->name('reports.ask');
        Route::get('/exceptions', [WorkflowOperationsController::class, 'exceptions'])->name('exceptions');
        Route::post('/action-runs/{run}/retry', [WorkflowOperationsController::class, 'retryAction'])->name('actions.retry')->whereNumber('run');
        Route::post('/action-runs/{run}/skip', [WorkflowOperationsController::class, 'skipAction'])->name('actions.skip')->whereNumber('run');
        Route::post('/events/{event}/retry', [WorkflowOperationsController::class, 'retryEvent'])->name('events.retry')->whereNumber('event');

        // Administration
        Route::get('/admin', [WorkflowAdminController::class, 'index'])->name('admin');
        Route::post('/admin/settings', [WorkflowAdminController::class, 'update'])->name('admin.update');
        Route::get('/calendars', [WorkflowAdminController::class, 'calendars'])->name('calendars.index');
        Route::post('/calendars', [WorkflowAdminController::class, 'storeCalendar'])->name('calendars.store');
        Route::patch('/calendars/{calendar}', [WorkflowAdminController::class, 'updateCalendar'])->name('calendars.update')->whereUuid('calendar');
        Route::get('/audit', [WorkflowAdminController::class, 'audit'])->name('audit');
        Route::get('/audit/export', [WorkflowAdminController::class, 'auditExport'])->name('audit.export');
        Route::post('/audit/verify', [WorkflowAdminController::class, 'verify'])->name('audit.verify');
    });
