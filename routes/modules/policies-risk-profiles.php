<?php

use App\Modules\Policies\Http\Controllers\RiskProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module.active:policy_enforcement'])->group(function () {
    // User Risk Profiles
    Route::get('risk-profiles/users/{id}', [RiskProfileController::class, 'getUserRiskProfile']);
    Route::get('risk-profiles/users/{id}/behavior', [RiskProfileController::class, 'getUserBehaviorSummary']);
    Route::get('risk-profiles/users/{id}/trend', [RiskProfileController::class, 'getUserRiskTrend']);
    Route::post('risk-profiles/users/{id}/recalculate', [RiskProfileController::class, 'recalculateUserRiskProfile']);

    // Department Risk Profiles
    Route::get('risk-profiles/departments/{id}', [RiskProfileController::class, 'getDepartmentRiskProfile']);
    Route::get('risk-profiles/departments/{id}/behavior', [RiskProfileController::class, 'getDepartmentBehaviorSummary']);
    Route::post('risk-profiles/departments/{id}/recalculate', [RiskProfileController::class, 'recalculateDepartmentRiskProfile']);

    // High-Risk Lists
    Route::get('risk-profiles/high-risk-users', [RiskProfileController::class, 'getHighRiskUsers']);
    Route::get('risk-profiles/high-risk-departments', [RiskProfileController::class, 'getHighRiskDepartments']);

    // Batch Operations (admin only)
    Route::middleware('can:manage-policies')->group(function () {
        Route::post('risk-profiles/batch/recalculate-users', [RiskProfileController::class, 'batchRecalculateUserProfiles']);
        Route::post('risk-profiles/batch/recalculate-departments', [RiskProfileController::class, 'batchRecalculateDepartmentProfiles']);
    });

    // Risk Alerts
    Route::get('risk-profiles/alerts', [RiskProfileController::class, 'listRiskAlerts']);
    Route::patch('risk-profiles/alerts/{id}/acknowledge', [RiskProfileController::class, 'acknowledgeAlert']);
    Route::patch('risk-profiles/alerts/{id}/resolve', [RiskProfileController::class, 'resolveAlert']);
    Route::patch('risk-profiles/alerts/{id}/assign', [RiskProfileController::class, 'assignAlert']);

    // Dashboard
    Route::get('risk-profiles/dashboard', [RiskProfileController::class, 'getDashboardSummary']);
});
