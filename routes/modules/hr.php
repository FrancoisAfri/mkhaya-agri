<?php

use App\Modules\HR\Hr\Controllers\AiChatbotController;
use App\Modules\HR\Hr\Controllers\LeaveAbsenceUploadController;
use App\Modules\HR\Hr\Controllers\LeaveAnalyticsController;
use App\Modules\HR\Hr\Controllers\LeaveImportController;
use App\Modules\HR\Hr\Controllers\LeaveAnomaliesController;
use App\Modules\HR\Hr\Controllers\LeaveManagementController;
use App\Modules\HR\Hr\Controllers\TimeAttendanceController;
use App\Modules\HR\SelfService\SelfServiceController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {

    // ── Employee Self-Service ─────────────────────────────────────────────────
    Route::get('/my', [SelfServiceController::class, 'index'])->name('self-service.index');

    // ── Time & Attendance ────────────────────────────────────────────────────
    Route::middleware('module.active:time_attendance')
        ->get('/time-attendance', [TimeAttendanceController::class, 'index'])->name('hr.attendance.index');

    // ── Leave Management ─────────────────────────────────────────────────────
    Route::middleware(['module.active:leave_management', 'module.tier:leave_management'])->prefix('leave-management')->name('hr.leave.')->group(function () {

        // ── Navigation hubs — role-filtered feature-card landing pages ────────
        foreach (['my-leave-hub' => 'my_leave', 'approvals-hub' => 'approvals', 'workforce-hub' => 'workforce', 'insights-hub' => 'insights'] as $hubPath => $group) {
            Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
                ->defaults('module', 'leave_management')->defaults('group', $group)->name('hub.' . $group);
        }

        // Dashboard / overview
        Route::get('/', [LeaveManagementController::class, 'index'])->name('index');

        // Employee self-service
        Route::get('/apply', [LeaveManagementController::class, 'apply'])->name('apply');
        Route::post('/apply', [LeaveManagementController::class, 'store'])->name('store');
        // Supervisor applies leave on behalf of an employee (incl. non-login staff).
        Route::get('/apply-for-staff', [LeaveManagementController::class, 'applyForStaff'])->name('apply-for-staff');
        Route::post('/apply-for-staff', [LeaveManagementController::class, 'storeForStaff'])->name('apply-for-staff.store');
        Route::post('/on-behalf-policy', [LeaveManagementController::class, 'updateOnBehalfPolicy'])->name('on-behalf-policy');
        Route::post('/apply/overtime', [LeaveManagementController::class, 'storeOvertime'])->name('store.overtime');
        Route::post('/apply/bulk', [LeaveManagementController::class, 'storeBulk'])->name('store.bulk');
        Route::get('/my-applications', [LeaveManagementController::class, 'myApplications'])->name('my-applications');
        Route::get('/my-applications/{leaveRequest}', [LeaveManagementController::class, 'show'])->name('show');
        Route::post('/my-applications/{leaveRequest}/cancel', [LeaveManagementController::class, 'cancel'])->name('cancel');

        // AI Leave Advisor
        Route::post('/ai-advisor', [LeaveManagementController::class, 'aiAdvisor'])->name('ai-advisor');

        // Working days preview (called client-side when dates are selected on the apply form)
        Route::get('/working-days-preview', [LeaveManagementController::class, 'workingDaysPreview'])->name('working-days-preview');
        Route::get('/teammate-leave', [LeaveManagementController::class, 'teammateLeaveApi'])->name('teammate-leave');

        // Documents
        Route::post('/my-applications/{leaveRequest}/documents', [LeaveManagementController::class, 'uploadDocument'])->name('documents.upload');
        Route::get('/my-applications/{leaveRequest}/documents/{document}/view', [LeaveManagementController::class, 'viewDocument'])->name('documents.view');
        Route::get('/my-applications/{leaveRequest}/documents/{document}/download', [LeaveManagementController::class, 'downloadDocument'])->name('documents.download');

        // Balances
        Route::get('/balances', [LeaveManagementController::class, 'balances'])->name('balances');

        // Calendar
        Route::get('/calendar', [LeaveManagementController::class, 'calendar'])->name('calendar');

        // HR Dashboard — restricted to HR, super admin, administrator, executive roles
        Route::get('/hr-dashboard', [LeaveManagementController::class, 'hrDashboard'])
            ->name('hr-dashboard')
            ->middleware('role:HR|super admin|administrator|executive');

        // Import wizard
        Route::prefix('import')->name('import.')->group(function () {
            Route::get('/',                           [LeaveImportController::class, 'index'])->name('index');
            Route::get('/template/{type}',            [LeaveImportController::class, 'downloadTemplate'])->name('template');
            Route::post('/preview',                   [LeaveImportController::class, 'preview'])->name('preview');
            Route::post('/run',                       [LeaveImportController::class, 'import'])->name('run');
            Route::post('/rollback',                  [LeaveImportController::class, 'rollback'])->name('rollback');
            Route::post('/download-errors',           [LeaveImportController::class, 'downloadErrors'])->name('download-errors');
        });

        // Executive Dashboard
        Route::get('/executive-dashboard', [LeaveManagementController::class, 'executiveDashboard'])->name('executive-dashboard');

        // Workforce planning
        Route::get('/workforce-dashboard', [LeaveManagementController::class, 'workforceDashboard'])->name('workforce-dashboard');
        Route::post('/alternative-dates',  [LeaveManagementController::class, 'alternativeDates'])->name('alternative-dates');

        // Manager dashboard
        Route::get('/manager-dashboard', [LeaveManagementController::class, 'managerDashboard'])->name('manager-dashboard');

        // Reports — queued generation
        Route::post('/reports/queue/{key}', [LeaveManagementController::class, 'queueReport'])->name('reports.queue');

        // Approvals (manager / HR)
        Route::get('/approvals', [LeaveManagementController::class, 'approvals'])->name('approvals');
        Route::post('/approvals/{leaveRequest}/approve', [LeaveManagementController::class, 'approve'])->name('approve');
        Route::post('/approvals/{leaveRequest}/reject', [LeaveManagementController::class, 'reject'])->name('reject');
        Route::post('/approvals/{leaveRequest}/policy-override', [LeaveManagementController::class, 'policyOverride'])->name('policy-override');

        // Approver delegations
        Route::get('/delegations', [LeaveManagementController::class, 'delegations'])->name('delegations');
        Route::post('/delegations', [LeaveManagementController::class, 'storeDelegation'])->name('delegations.store');
        Route::delete('/delegations/{delegation}', [LeaveManagementController::class, 'revokeDelegation'])->name('delegations.revoke');
        Route::post('/delegations/{delegation}/approve', [LeaveManagementController::class, 'approveDelegation'])->name('delegations.approve');
        Route::post('/delegations/{delegation}/decline', [LeaveManagementController::class, 'declineDelegation'])->name('delegations.decline');

        // Parental leave (Van Wyk 2025)
        Route::get('/parental-leave', [LeaveManagementController::class, 'parentalLeave'])->name('parental-leave');
        Route::post('/parental-leave', [LeaveManagementController::class, 'storeParentalLeave'])->name('parental-leave.store');
        Route::post('/parental-leave/{pool}/approve', [LeaveManagementController::class, 'approveParentalPool'])->name('parental-leave.approve');

        // Reports
        Route::get('/analytics', [LeaveAnalyticsController::class, 'index'])->name('analytics');
        Route::get('/reports', [LeaveManagementController::class, 'reports'])->name('reports');

        // Employee leave payout self-service (any authenticated employee)
        Route::post('/my-payouts', [LeaveManagementController::class, 'storeEmployeePayout'])->name('my-payouts.store');

        // Leave Payouts — HR/Admin only
        Route::middleware('role:HR|super admin|administrator')->group(function () {
            Route::get('/payouts',              [LeaveManagementController::class, 'leavePayouts'])->name('payouts');
            Route::post('/payouts',             [LeaveManagementController::class, 'storeLeavePayouts'])->name('payouts.store');
            Route::patch('/payouts/{payoutRecord}/status', [LeaveManagementController::class, 'updatePayoutStatus'])->name('payouts.update-status');
        });
        Route::get('/reports/balance-as-of', [LeaveManagementController::class, 'balanceAsOfReport'])->name('reports.balance-as-of');
        Route::get('/reports/generate/{key}', [LeaveManagementController::class, 'generateReport'])->name('reports.generate');
        Route::get('/reports/export/{key}/{format}', [LeaveManagementController::class, 'exportReport'])->name('reports.export');

        // Policy governance
        Route::get('/policy-violations', [LeaveManagementController::class, 'policyViolations'])->name('policy-violations');
        Route::get('/policy-overrides', [LeaveManagementController::class, 'policyOverrides'])->name('policy-overrides');
        Route::post('/policy-sync', [LeaveManagementController::class, 'syncPolicies'])->name('policy-sync');
        Route::post('/policy-violations/{violation}/resolve', [LeaveManagementController::class, 'resolveViolation'])->name('policy-violations.resolve');
        Route::get('/policy-impact/{leaveRequest}', [LeaveManagementController::class, 'policyImpact'])->name('policy-impact');

        // ── Manual Leave Allocation ──────────────────────────────────────────
        Route::get('/manual-allocations',               [LeaveManagementController::class, 'manualAllocations'])->name('manual-allocations');
        Route::get('/manual-allocations/search',        [LeaveManagementController::class, 'searchEmployeesForAllocation'])->name('manual-allocations.search');
        Route::get('/manual-allocations/{hrPerson}/balances', [LeaveManagementController::class, 'employeeBalancesForAllocation'])->name('manual-allocations.balances');
        Route::post('/manual-allocations/adjust',        [LeaveManagementController::class, 'adjustSingleBalance'])->name('manual-allocations.adjust');
        Route::post('/manual-allocations/send-bulk-otp', [LeaveManagementController::class, 'sendBulkAdjustOtp'])->name('manual-allocations.send-bulk-otp');
        Route::post('/manual-allocations/bulk-adjust',   [LeaveManagementController::class, 'bulkAdjustBalances'])->name('manual-allocations.bulk-adjust');
        Route::post('/manual-allocations/preview-scope', [LeaveManagementController::class, 'previewBulkScope'])->name('manual-allocations.preview-scope');
        // ── Bulk-applied leave: withdraw a whole run ──────────────────────────
        Route::get('/apply/bulk/applied',                [LeaveManagementController::class, 'bulkAppliedLeave'])->name('apply.bulk.applied');
        Route::post('/apply/bulk/delete',                [LeaveManagementController::class, 'bulkDeleteLeave'])->name('apply.bulk.delete');

        // ── Leave reset (preview first — it writes balances off) ──────────────
        Route::post('/manual-allocations/reset/preview', [LeaveManagementController::class, 'previewLeaveReset'])->name('manual-allocations.reset.preview');
        Route::post('/manual-allocations/reset',         [LeaveManagementController::class, 'resetLeaveBalances'])->name('manual-allocations.reset');

        // ── Attendance-driven absence upload ──────────────────────────────────
        // Spreadsheet of non-clock-in days → preview → confirm → reverse batch.
        Route::get('/absence-upload',                    [LeaveAbsenceUploadController::class, 'index'])->name('absence-upload.index');
        Route::post('/absence-upload/preview',           [LeaveAbsenceUploadController::class, 'preview'])->name('absence-upload.preview');
        Route::post('/absence-upload/confirm',           [LeaveAbsenceUploadController::class, 'confirm'])->name('absence-upload.confirm');
        Route::get('/absence-upload/batches/{batch}',    [LeaveAbsenceUploadController::class, 'show'])->name('absence-upload.show');
        Route::post('/absence-upload/batches/{batch}/reverse', [LeaveAbsenceUploadController::class, 'reverse'])->name('absence-upload.reverse');

        // Full per-employee preview (balance before → change → after) before confirming.
        Route::post('/manual-allocations/preview',       [LeaveManagementController::class, 'previewManualAllocation'])->name('manual-allocations.preview');
        // Past allocations, and reversing one.
        Route::get('/manual-allocations/batches',        [LeaveManagementController::class, 'manualAllocationBatches'])->name('manual-allocations.batches');
        Route::get('/manual-allocations/batches/{batch}', [LeaveManagementController::class, 'manualAllocationBatchItems'])->name('manual-allocations.batches.items');
        Route::post('/manual-allocations/batches/{batch}/reverse', [LeaveManagementController::class, 'reverseManualAllocation'])->name('manual-allocations.batches.reverse');

        // ── Admin Leave Cancellation ─────────────────────────────────────────
        Route::middleware('role:administrator|super admin|HR')->group(function () {
            Route::get('/admin-cancel',                    [LeaveManagementController::class, 'adminCancelSearch'])->name('admin-cancel');
            Route::post('/admin-cancel/{leaveRequest}',    [LeaveManagementController::class, 'adminCancelLeave'])->name('admin-cancel.submit');
        });

        // ── Leave Anomalies (account-setup / ledger integrity + AI remediation) ──
        Route::middleware('role:administrator|super admin|HR')->prefix('anomalies')->name('anomalies.')->group(function () {
            Route::get('/',                    [LeaveAnomaliesController::class, 'index'])->name('index');
            Route::post('/scan',               [LeaveAnomaliesController::class, 'scan'])->name('scan');
            Route::post('/{flag}/propose-fix', [LeaveAnomaliesController::class, 'proposeFix'])->name('propose-fix');
            Route::post('/{flag}/apply-fix',   [LeaveAnomaliesController::class, 'applyFix'])->name('apply-fix');
            Route::post('/{flag}/reverse',     [LeaveAnomaliesController::class, 'reverseFix'])->name('reverse');
            Route::post('/{flag}/dismiss',     [LeaveAnomaliesController::class, 'dismiss'])->name('dismiss');
        });

        // ── HR Setup ─────────────────────────────────────────────────────────
        // ── AI Intelligence Engine (Phase 7) ──────────────────────────────────
        Route::prefix('ai')->name('ai.')->group(function () {
            Route::get('/',             [AiChatbotController::class, 'index'])->name('index');
            Route::get('/capabilities', [AiChatbotController::class, 'capabilities'])->name('capabilities');
            Route::post('/chat',        [AiChatbotController::class, 'chat'])->name('chat');
            Route::get('/anomalies',    [AiChatbotController::class, 'anomalies'])->name('anomalies');
            Route::post('/workforce',   [AiChatbotController::class, 'workforceReport'])->name('workforce');
        });

        Route::prefix('setup')->name('setup.')->middleware('role:administrator|super admin|HR')->group(function () {
            // Scheduled reports management
            Route::get('/scheduled-reports',                           [LeaveManagementController::class, 'scheduledReports'])->name('scheduled-reports');
            Route::post('/scheduled-reports',                          [LeaveManagementController::class, 'storeScheduledReport'])->name('scheduled-reports.store');
            Route::patch('/scheduled-reports/{scheduledReport}',       [LeaveManagementController::class, 'updateScheduledReport'])->name('scheduled-reports.update');
            Route::delete('/scheduled-reports/{scheduledReport}',      [LeaveManagementController::class, 'destroyScheduledReport'])->name('scheduled-reports.destroy');

            Route::get('/leave-types', [LeaveManagementController::class, 'leaveTypes'])->name('leave-types');
            Route::post('/leave-types', [LeaveManagementController::class, 'storeLeaveType'])->name('leave-types.store');
            Route::patch('/leave-types/{leaveType}', [LeaveManagementController::class, 'updateLeaveType'])->name('leave-types.update');

            Route::get('/profiles', [LeaveManagementController::class, 'profiles'])->name('profiles');
            Route::post('/profiles', [LeaveManagementController::class, 'storeProfile'])->name('profiles.store');
            Route::patch('/profiles/{profile}', [LeaveManagementController::class, 'updateProfile'])->name('profiles.update');
            Route::post('/profiles/{profile}/allocations', [LeaveManagementController::class, 'storeAllocation'])->name('profiles.allocations.store');
            Route::patch('/profiles/{profile}/allocations/{allocation}', [LeaveManagementController::class, 'updateAllocation'])->name('profiles.allocations.update');
            Route::delete('/profiles/{profile}/allocations/{allocation}', [LeaveManagementController::class, 'destroyAllocation'])->name('profiles.allocations.destroy');

            Route::get('/approval-config', [LeaveManagementController::class, 'approvalConfig'])->name('approval-config');
            Route::patch('/approval-config', [LeaveManagementController::class, 'updateApprovalConfig'])->name('approval-config.update');

            Route::get('/configuration', [LeaveManagementController::class, 'configuration'])->name('configuration');
            Route::patch('/configuration', [LeaveManagementController::class, 'updateConfiguration'])->name('configuration.update');
        });
    });

    // ── HR Executive Analytics Dashboard ─────────────────────────────────────
    Route::get('/hr/analytics', [\App\Modules\HR\Hr\Controllers\HrAnalyticsController::class, 'index'])
        ->name('hr.analytics.index');
});
