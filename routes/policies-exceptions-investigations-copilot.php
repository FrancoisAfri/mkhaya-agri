<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Policies\Http\Controllers\ExceptionRequestController;
use App\Modules\Policies\Http\Controllers\ViolationInvestigationController;
use App\Modules\Policies\Http\Controllers\PolicyCopilotController;

/**
 * Policy Exception Management Routes
 * PHASE 7
 */
Route::group([], function () {

    // User exception endpoints
    Route::get('/exceptions/pending', [ExceptionRequestController::class, 'getUserPending']);
    Route::get('/exceptions/active', [ExceptionRequestController::class, 'getUserActive']);
    Route::post('/exceptions', [ExceptionRequestController::class, 'create']);
    Route::get('/exceptions/{id}', [ExceptionRequestController::class, 'show']);
    Route::post('/exceptions/{id}/submit', [ExceptionRequestController::class, 'submit']);
    Route::post('/exceptions/{id}/check-valid', [ExceptionRequestController::class, 'checkValid']);

    // Manager/Approver endpoints
    Route::post('/exceptions/{id}/approve', [ExceptionRequestController::class, 'approve'])
        ->middleware('can:approve-exception');
    Route::post('/exceptions/{id}/reject', [ExceptionRequestController::class, 'reject'])
        ->middleware('can:reject-exception');
    Route::post('/exceptions/{id}/escalate', [ExceptionRequestController::class, 'escalate'])
        ->middleware('can:escalate-exception');
});

/**
 * Violation Investigation Routes
 * PHASE 8
 */
Route::group([], function () {

    // Investigation management
    Route::post('/investigations', [ViolationInvestigationController::class, 'create'])
        ->middleware('can:create-investigation');
    Route::get('/investigations/{id}', [ViolationInvestigationController::class, 'show']);
    Route::post('/investigations/{id}/assign', [ViolationInvestigationController::class, 'assign'])
        ->middleware('can:assign-investigator');
    Route::patch('/investigations/{id}/status', [ViolationInvestigationController::class, 'updateStatus']);
    Route::post('/investigations/{id}/resolve', [ViolationInvestigationController::class, 'resolve'])
        ->middleware('can:resolve-investigation');
    Route::post('/investigations/{id}/close', [ViolationInvestigationController::class, 'close'])
        ->middleware('can:close-investigation');

    // Investigation notes
    Route::post('/investigations/{id}/notes', [ViolationInvestigationController::class, 'addNote']);
    Route::get('/investigations/{id}/notes', [ViolationInvestigationController::class, 'getNotes']);

    // Investigation evidence
    Route::post('/investigations/{id}/evidence', [ViolationInvestigationController::class, 'addEvidence']);
    Route::get('/investigations/{id}/evidence', [ViolationInvestigationController::class, 'getEvidence']);

    // Investigator views
    Route::get('/investigations/pending/mine', [ViolationInvestigationController::class, 'getPending']);
    Route::get('/investigations/overdue/all', [ViolationInvestigationController::class, 'getOverdue'])
        ->middleware('can:view-overdue-investigations');
    Route::get('/investigations/statistics/all', [ViolationInvestigationController::class, 'getStatistics'])
        ->middleware('can:view-investigation-statistics');
});

/**
 * Policy Copilot & Decision Advisor Routes
 * PHASE 9
 */
Route::group([], function () {

    // Copilot chat interface
    Route::post('/copilot/chat', [PolicyCopilotController::class, 'chat']);
    Route::post('/copilot/ask', [PolicyCopilotController::class, 'askQuestion']);
    Route::get('/copilot/policies/{policy}', [PolicyCopilotController::class, 'getPolicy']);
    Route::post('/copilot/search', [PolicyCopilotController::class, 'searchPolicies']);
    Route::post('/copilot/by-topic', [PolicyCopilotController::class, 'getPoliciesByTopic']);

    // Decision advisor
    Route::post('/copilot/advice', [PolicyCopilotController::class, 'adviseOnAction']);
    Route::post('/copilot/predict', [PolicyCopilotController::class, 'predictOutcome']);
    Route::post('/copilot/recommendations', [PolicyCopilotController::class, 'getComplianceRecommendations']);
    Route::get('/copilot/rejections/{policy}', [PolicyCopilotController::class, 'getRejectionReasons']);
});
