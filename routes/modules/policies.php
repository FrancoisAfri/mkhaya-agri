<?php

use App\Modules\Policies\Controllers\PolicyAttestationController;
use App\Modules\Policies\Controllers\PolicyQuizController;
use App\Modules\Policies\Controllers\PolicyCategoryController;
use App\Modules\Policies\Controllers\PolicyClauseController;
use App\Modules\Policies\Controllers\PolicyController;
use App\Modules\Policies\Controllers\PolicyExceptionController;
use App\Modules\Policies\Controllers\PolicyRuleController;
use App\Modules\Policies\Controllers\PolicyAnalyticsController;
use App\Modules\Policies\Controllers\PolicyInsightsController;
use App\Modules\Policies\Controllers\PolicySimulationController;
use App\Modules\Policies\Controllers\PolicyQualityController;
use App\Modules\Policies\Controllers\PolicyViolationController;
use Illuminate\Support\Facades\Route;


//Route::resource('policies', PolicyController::class);
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->group(function () {
    Route::resource('policy-categories', PolicyCategoryController::class);
});


Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policies')->name('policies.')->group(function () {

    // ── Analytics ─────────────────────────────────────────────────────────────
    Route::get('/analytics', [PolicyAnalyticsController::class, 'index'])->name('analytics');

    // ── Policy CRUD ──────────────────────────────────────────────────────────
    Route::get('/',          [PolicyController::class, 'index'])->name('index');
    Route::get('/create',    [PolicyController::class, 'create'])->name('create');
    Route::post('/',         [PolicyController::class, 'store'])->name('store');
    Route::get('/{policy}',  [PolicyController::class, 'show'])->name('show');
    Route::get('/{policy}/edit', [PolicyController::class, 'edit'])->name('edit');
    Route::patch('/{policy}',    [PolicyController::class, 'update'])->name('update');
    Route::delete('/{policy}',   [PolicyController::class, 'destroy'])->name('destroy');

    // ── Document upload ───────────────────────────────────────────────────────
    Route::post('/{policy}/documents', [PolicyController::class, 'uploadDocument'])->name('documents.store');
    Route::post('/{policy}/documents/{document}/extract', [PolicyController::class, 'extractDocument'])->name('documents.extract');
    Route::get('/{policy}/documents/{document}/view', [PolicyController::class, 'viewDocument'])->name('documents.view');
    Route::get('/{policy}/documents/{document}/download', [PolicyController::class, 'downloadDocument'])->name('documents.download');

    // ── Clause review & approval ──────────────────────────────────────────────
    Route::prefix('/{policy}/clauses')->name('clauses.')->group(function () {
        Route::get('/',                       [PolicyClauseController::class, 'index'])->name('index');
        Route::patch('/{clause}',              [PolicyClauseController::class, 'update'])->name('update');
        Route::post('/approve',               [PolicyClauseController::class, 'approve'])->name('approve');
        Route::delete('/{clause}',             [PolicyClauseController::class, 'destroy'])->name('destroy');
    });

    // ── Rule builder ──────────────────────────────────────────────────────────
    Route::prefix('/{policy}/rules')->name('rules.')->group(function () {
        Route::get('/',                        [PolicyRuleController::class, 'index'])->name('index');
        Route::post('/',                       [PolicyRuleController::class, 'store'])->name('store');
        Route::patch('/{rule}/toggle',         [PolicyRuleController::class, 'toggle'])->name('toggle');
        Route::delete('/{rule}',               [PolicyRuleController::class, 'destroy'])->name('destroy');
        Route::post('/nl-draft/{draft}/confirm',[PolicyRuleController::class, 'confirmNlDraft'])->name('confirm-draft');
    });
});

// ── NL Rule parsing (returns JSON, no Inertia) ────────────────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])
    ->post('/api/policies/rules/parse-nl', [PolicyRuleController::class, 'parseNaturalLanguage'])
    ->name('policies.rules.parse-nl');

// ── Violations (global — not nested under a policy) ───────────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policy-violations')->name('policy-violations.')->group(function () {
    Route::get('/',               [PolicyViolationController::class, 'index'])->name('index');
    Route::get('/{violation}',    [PolicyViolationController::class, 'show'])->name('show');
    Route::patch('/{violation}',  [PolicyViolationController::class, 'update'])->name('update');
    Route::post('/{violation}/notes', [PolicyViolationController::class, 'addNote'])->name('notes.store');
});

// ── Exceptions ────────────────────────────────────────────────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policy-exceptions')->name('policy-exceptions.')->group(function () {
    Route::get('/',                    [PolicyExceptionController::class, 'index'])->name('index');
    Route::post('/',                   [PolicyExceptionController::class, 'store'])->name('store');
    Route::get('/{exception}',         [PolicyExceptionController::class, 'show'])->name('show');
    Route::post('/{exception}/decide', [PolicyExceptionController::class, 'decide'])->name('decide');
    Route::patch('/{exception}/revoke',[PolicyExceptionController::class, 'revoke'])->name('revoke');
});

// ── Policy Analytics Dashboard & Reports (Phase 10/11) ───────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policy-dashboard')->name('policy-dashboard.')->group(function () {
    Route::get('/', [PolicyInsightsController::class, 'dashboard'])->name('index');
});

Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policy-reports')->name('policy-reports.')->group(function () {
    Route::get('/', [PolicyInsightsController::class, 'reports'])->name('index');
    Route::get('/generate/{reportKey}', [PolicyInsightsController::class, 'generate'])->name('generate');
    Route::get('/export/{reportKey}/{format}', [PolicyInsightsController::class, 'export'])->name('export');

    // Scheduled reports
    Route::get('/scheduled', [PolicyInsightsController::class, 'scheduledReports'])->name('scheduled.index');
    Route::post('/scheduled', [PolicyInsightsController::class, 'storeScheduled'])->name('scheduled.store');
    Route::patch('/scheduled/{scheduledReport}', [PolicyInsightsController::class, 'updateScheduled'])->name('scheduled.update');
    Route::delete('/scheduled/{scheduledReport}', [PolicyInsightsController::class, 'destroyScheduled'])->name('scheduled.destroy');
});

// ── Policy Simulation Engine (Phase 12) ──────────────────────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policy-simulation')->name('policy-simulation.')->group(function () {
    Route::get('/',                          [PolicySimulationController::class, 'index'])->name('index');
    Route::post('/',                         [PolicySimulationController::class, 'store'])->name('store');
    Route::get('/{simulation}',              [PolicySimulationController::class, 'show'])->name('show');
});

// ── Policy Quality & Conflict Detection (Phase 13) ───────────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policy-quality')->name('policy-quality.')->group(function () {
    Route::get('/',                                   [PolicyQualityController::class, 'index'])->name('index');
    Route::post('/detect',                            [PolicyQualityController::class, 'detectConflicts'])->name('detect');
    Route::post('/suggest',                           [PolicyQualityController::class, 'suggest'])->name('suggest');
    Route::patch('/conflicts/{conflict}/acknowledge', [PolicyQualityController::class, 'acknowledgeConflict'])->name('conflicts.acknowledge');
    Route::patch('/conflicts/{conflict}/resolve',     [PolicyQualityController::class, 'resolveConflict'])->name('conflicts.resolve');
    Route::patch('/conflicts/{conflict}/false-positive', [PolicyQualityController::class, 'falsePositiveConflict'])->name('conflicts.false-positive');
});

// ── Event Mappings (public API) ────────────────────────────────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('api/event-mappings')->name('event-mappings.')->group(function () {
    Route::get('/',                              [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'index'])->name('index');
    Route::post('/',                             [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'store'])->name('store');
    Route::get('/summary',                       [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'summary'])->name('summary');
    Route::get('/modules',                       [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'getModules'])->name('modules');
    Route::get('/modules/{module}/actions',      [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'getActionsForModule'])->name('module-actions');
    Route::get('/report/module/{module}',        [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'reportForModule'])->name('report-module');
    Route::get('/dependencies',                  [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'getDependencies'])->name('dependencies');
    Route::post('/test',                         [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'testMapping'])->name('test');
    Route::get('/event-rules',                   [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'getApplicableRules'])->name('event-rules');
    Route::get('/policy/{policyId}/rules',       [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'getRulesForPolicy'])->name('policy-rules');
    Route::get('/policy/{policyId}/events',      [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'getEventsForPolicy'])->name('policy-events');
    Route::get('/{id}',                          [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'show'])->name('show');
    Route::put('/{id}',                          [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'update'])->name('update');
    Route::patch('/{id}/deactivate',             [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'deactivate'])->name('deactivate');
    Route::patch('/{id}/activate',               [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'activate'])->name('activate');
    Route::delete('/{id}',                       [\App\Modules\Policies\Http\Controllers\EventMappingController::class, 'destroy'])->name('destroy');
});

// ── Policy Attestation & Employee Awareness ───────────────────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policy-attestations')->name('policies.attestations.')->group(function () {
    // Employee: my attestations list
    Route::get('/',                                     [PolicyAttestationController::class, 'index'])->name('index');
    // Employee: view + sign a specific attestation
    Route::get('/{attestation}',                        [PolicyAttestationController::class, 'show'])->name('show');
    // Employee: submit acknowledgement
    Route::post('/{attestation}/submit',                [PolicyAttestationController::class, 'submit'])->name('submit');
    // Admin/Manager: compliance dashboard
    Route::get('/dashboard/overview',                   [PolicyAttestationController::class, 'dashboard'])->name('dashboard');
    // Admin: assign attestation to users for a policy
    Route::post('/policies/{policy}/assign',            [PolicyAttestationController::class, 'assign'])->name('assign');
});

// ── Policy Understanding Quiz ─────────────────────────────────────────────────
Route::middleware(['web', 'auth', 'module.active:policy_enforcement'])->prefix('policy-quiz')->name('policies.quiz.')->group(function () {
    // Admin: manage questions + settings for a specific policy
    Route::get('/{policy}/manage',                      [PolicyQuizController::class, 'manage'])->name('manage');
    Route::post('/{policy}/questions',                  [PolicyQuizController::class, 'storeQuestion'])->name('questions.store');
    Route::patch('/{policy}/questions/{question}',      [PolicyQuizController::class, 'updateQuestion'])->name('questions.update');
    Route::delete('/{policy}/questions/{question}',     [PolicyQuizController::class, 'destroyQuestion'])->name('questions.destroy');
    Route::post('/{policy}/generate',                   [PolicyQuizController::class, 'generate'])->name('generate');
    Route::patch('/{policy}/settings',                  [PolicyQuizController::class, 'updateSettings'])->name('settings.update');
    // Employee: take quiz
    Route::get('/{policy}/take',                        [PolicyQuizController::class, 'take'])->name('take');
    Route::post('/{policy}/submit',                     [PolicyQuizController::class, 'submit'])->name('submit');
    // Employee + Admin: view attempt history for a policy
    Route::get('/{policy}/history',                     [PolicyQuizController::class, 'history'])->name('history');
});
