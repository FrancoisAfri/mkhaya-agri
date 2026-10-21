<?php

use App\Modules\HR\Hr\Controllers\Attendance\AttendanceAnomalyController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceClockingController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceDashboardController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceRecordController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceSettingsController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceShiftController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceStatusController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceWorkCalendarController;
use App\Modules\HR\Hr\Controllers\Attendance\WorkforcePressureController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceWellnessController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceDisciplineController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendancePolicyController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceProductivityController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceAuditController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceAnalyticsController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceReportsController;
use App\Modules\HR\Hr\Controllers\Attendance\AbsenteeismCostController;
use App\Modules\HR\Hr\Controllers\Attendance\AttendanceHeatmapController;
use App\Modules\HR\Hr\Controllers\Attendance\BceaComplianceController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'module.active:time_attendance', 'module.tier:time_attendance'])->prefix('attendance')->name('attendance.')->group(function () {

    // ── Navigation hubs — role-filtered feature-card landing pages ────────────
    foreach (['manage-hub' => 'manage', 'workforce-hub' => 'workforce', 'insights-hub' => 'insights', 'compliance-hub' => 'compliance'] as $hubPath => $group) {
        Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
            ->defaults('module', 'time_attendance')->defaults('group', $group)->name('hub.' . $group);
    }

    // Dashboard
    Route::get('/', [AttendanceDashboardController::class, 'index'])->name('dashboard');

    // Records (daily attendance list)
    Route::prefix('records')->name('records.')->group(function () {
        Route::get('/',                                        [AttendanceRecordController::class, 'index'])->name('index');
        Route::post('/',                                       [AttendanceRecordController::class, 'store'])->name('store');
        Route::put('/{attendanceRecord}',                      [AttendanceRecordController::class, 'update'])->name('update');
        Route::post('/{attendanceRecord}/approve',             [AttendanceRecordController::class, 'approve'])->name('approve');
        Route::delete('/{attendanceRecord}',                   [AttendanceRecordController::class, 'destroy'])->name('destroy');
    });

    // Statuses
    Route::prefix('statuses')->name('statuses.')->group(function () {
        Route::get('/',                          [AttendanceStatusController::class, 'index'])->name('index');
        Route::post('/',                         [AttendanceStatusController::class, 'store'])->name('store');
        Route::put('/{attendanceStatus}',        [AttendanceStatusController::class, 'update'])->name('update');
        Route::delete('/{attendanceStatus}',     [AttendanceStatusController::class, 'destroy'])->name('destroy');
    });

    // Shifts
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/',                        [AttendanceShiftController::class, 'index'])->name('index');
        Route::post('/',                       [AttendanceShiftController::class, 'store'])->name('store');
        Route::put('/{attendanceShift}',       [AttendanceShiftController::class, 'update'])->name('update');
        Route::delete('/{attendanceShift}',    [AttendanceShiftController::class, 'destroy'])->name('destroy');
    });

    // Work Calendars
    Route::prefix('calendars')->name('calendars.')->group(function () {
        Route::get('/',                                [AttendanceWorkCalendarController::class, 'index'])->name('index');
        Route::post('/',                               [AttendanceWorkCalendarController::class, 'store'])->name('store');
        Route::put('/{attendanceWorkCalendar}',        [AttendanceWorkCalendarController::class, 'update'])->name('update');
        Route::delete('/{attendanceWorkCalendar}',     [AttendanceWorkCalendarController::class, 'destroy'])->name('destroy');
    });

    // ── Phase 2: Clocking & Capture ──────────────────────────────────────────
    Route::prefix('clocking')->name('clocking.')->group(function () {
        Route::get('/kiosk',                [AttendanceClockingController::class, 'kiosk'])->name('kiosk');
        Route::post('/clock-in',            [AttendanceClockingController::class, 'clockIn'])->name('clock-in');
        Route::post('/clock-out',           [AttendanceClockingController::class, 'clockOut'])->name('clock-out');
        Route::post('/gps',                 [AttendanceClockingController::class, 'clockGps'])->name('gps');
        Route::post('/wfh',                 [AttendanceClockingController::class, 'clockWfh'])->name('wfh');
        Route::post('/break',               [AttendanceClockingController::class, 'breakAction'])->name('break');
        Route::get('/missed',               [AttendanceClockingController::class, 'missedClockings'])->name('missed');
        Route::post('/detect-missed',       [AttendanceClockingController::class, 'detectMissed'])->name('detect-missed');
        Route::get('/device-logs',          [AttendanceClockingController::class, 'deviceLogs'])->name('device-logs');
    });

    // ── Phase 3 & 4: Anomaly Detection + Intelligence Engine ─────────────────
    Route::prefix('intelligence')->name('intelligence.')->group(function () {
        // Phase 3: Anomaly Detection — view
        Route::get('/anomalies',  [AttendanceAnomalyController::class, 'index'])->name('anomalies')->middleware('crud.permissions:attendance.intelligence.view');
        Route::get('/risk-scores',[AttendanceAnomalyController::class, 'riskScores'])->name('risk-scores')->middleware('crud.permissions:attendance.intelligence.view');
        Route::get('/patterns',   [AttendanceAnomalyController::class, 'patterns'])->name('patterns')->middleware('crud.permissions:attendance.intelligence.view');

        // Phase 3: Anomaly Detection — scan & manage
        Route::post('/anomalies/scan',                             [AttendanceAnomalyController::class, 'runScan'])->name('anomalies.scan')->middleware('crud.permissions:attendance.intelligence.scan');
        Route::post('/anomalies/{attendanceAnomaly}/acknowledge',  [AttendanceAnomalyController::class, 'acknowledge'])->name('anomalies.acknowledge')->middleware('crud.permissions:attendance.intelligence.manage');
        Route::post('/anomalies/{attendanceAnomaly}/resolve',      [AttendanceAnomalyController::class, 'resolve'])->name('anomalies.resolve')->middleware('crud.permissions:attendance.intelligence.manage');

        // Phase 3/4: AI explain endpoints
        Route::post('/anomalies/{attendanceAnomaly}/explain',       [AttendanceAnomalyController::class, 'explain'])->name('anomalies.explain')->middleware('crud.permissions:attendance.intelligence.ai');
        Route::post('/risk-scores/{attendanceRiskScore}/explain',   [AttendanceAnomalyController::class, 'explainRisk'])->name('risk-scores.explain')->middleware('crud.permissions:attendance.intelligence.ai');

        // Phase 4: Context-Aware Intelligence
        Route::get('/',             [AttendanceAnomalyController::class, 'intelligence'])->name('index')->middleware('crud.permissions:attendance.intelligence.view');
        Route::post('/ask',         [AttendanceAnomalyController::class, 'askIntelligence'])->name('ask')->middleware('crud.permissions:attendance.intelligence.ai');
        Route::post('/team-report', [AttendanceAnomalyController::class, 'teamReport'])->name('team-report')->middleware('crud.permissions:attendance.intelligence.ai');
    });

    // ── Phase 5: Workforce Pressure & Burnout Prediction ─────────────────────
    Route::prefix('workforce')->name('workforce.')->group(function () {
        Route::get('/',                                                       [WorkforcePressureController::class, 'index'])->name('index')->middleware('crud.permissions:attendance.workforce.view');
        Route::get('/burnout-profiles',                                       [WorkforcePressureController::class, 'burnoutProfiles'])->name('burnout')->middleware('crud.permissions:attendance.workforce.view');
        Route::get('/predictions',                                            [WorkforcePressureController::class, 'predictions'])->name('predictions')->middleware('crud.permissions:attendance.workforce.view');
        Route::post('/scan',                                                  [WorkforcePressureController::class, 'runScan'])->name('scan')->middleware('crud.permissions:attendance.workforce.scan');
        Route::post('/burnout/{burnoutRiskProfile}/narrative',                [WorkforcePressureController::class, 'generateNarrative'])->name('burnout.narrative')->middleware('crud.permissions:attendance.workforce.ai');
        Route::post('/predictions/{workforcePressurePrediction}/dismiss',     [WorkforcePressureController::class, 'dismissPrediction'])->name('predictions.dismiss')->middleware('crud.permissions:attendance.workforce.manage');
        Route::post('/wellness-summary',                                      [WorkforcePressureController::class, 'wellnessSummary'])->name('wellness-summary')->middleware('crud.permissions:attendance.workforce.ai');
    });

    // ── Phase 6: Smart Roster & Shift Intelligence ────────────────────────────
    // The roster is the attendance engine's (shifts, templates, rotating patterns, swaps): see the
    // engine group at the end of this file. The old department schedules (roster_schedules) are no
    // longer edited; their entries were carried into rostered shifts.

    // ── Phase 7: Employee Wellness Signals ────────────────────────────────────
    Route::prefix('wellness')->name('wellness.')->group(function () {
        Route::get('/',                                                            [AttendanceWellnessController::class, 'index'])->name('index')->middleware('crud.permissions:attendance.wellness.view');
        Route::get('/departments',                                                 [AttendanceWellnessController::class, 'departmentScores'])->name('departments')->middleware('crud.permissions:attendance.wellness.view');
        Route::post('/scan',                                                       [AttendanceWellnessController::class, 'runScan'])->name('scan')->middleware('crud.permissions:attendance.wellness.scan');
        Route::post('/alerts/{attendanceWellnessAlert}/acknowledge',               [AttendanceWellnessController::class, 'acknowledge'])->name('alerts.acknowledge')->middleware('crud.permissions:attendance.wellness.manage');
        Route::post('/alerts/{attendanceWellnessAlert}/resolve',                   [AttendanceWellnessController::class, 'resolve'])->name('alerts.resolve')->middleware('crud.permissions:attendance.wellness.manage');
        Route::post('/alerts/{attendanceWellnessAlert}/dismiss',                   [AttendanceWellnessController::class, 'dismiss'])->name('alerts.dismiss')->middleware('crud.permissions:attendance.wellness.manage');
        Route::post('/alerts/{attendanceWellnessAlert}/explain',                   [AttendanceWellnessController::class, 'explainAlert'])->name('alerts.explain')->middleware('crud.permissions:attendance.wellness.ai');
        Route::post('/manager-insights',                                           [AttendanceWellnessController::class, 'managerInsights'])->name('manager-insights')->middleware('crud.permissions:attendance.wellness.ai');
    });

    // ── Phase 8: Attendance Discipline Intelligence ───────────────────────────
    Route::prefix('discipline')->name('discipline.')->group(function () {
        Route::get('/',                                                                            [AttendanceDisciplineController::class, 'index'])->name('index')->middleware('crud.permissions:attendance.discipline.view');
        Route::get('/offenders',                                                                   [AttendanceDisciplineController::class, 'offenders'])->name('offenders')->middleware('crud.permissions:attendance.discipline.view');
        Route::get('/cases/{attendanceDisciplineCase}',                                            [AttendanceDisciplineController::class, 'show'])->name('cases.show')->middleware('crud.permissions:attendance.discipline.view');
        Route::post('/scan',                                                                       [AttendanceDisciplineController::class, 'runScan'])->name('scan')->middleware('crud.permissions:attendance.discipline.manage');
        Route::post('/cases/{attendanceDisciplineCase}/generate-pack',                             [AttendanceDisciplineController::class, 'generatePack'])->name('cases.generate-pack')->middleware('crud.permissions:attendance.discipline.ai');
        Route::post('/cases/{attendanceDisciplineCase}/generate-recommendation',                   [AttendanceDisciplineController::class, 'generateRecommendation'])->name('cases.generate-recommendation')->middleware('crud.permissions:attendance.discipline.ai');
        Route::post('/cases/{attendanceDisciplineCase}/resolve',                                   [AttendanceDisciplineController::class, 'resolve'])->name('cases.resolve')->middleware('crud.permissions:attendance.discipline.manage');
    });

    // ── Phase 9: Attendance Policy Intelligence ───────────────────────────────
    Route::prefix('policy')->name('policy.')->group(function () {
        Route::get('/',                                                                   [AttendancePolicyController::class, 'index'])->name('index')->middleware('crud.permissions:attendance.policy.view');
        Route::get('/violations',                                                         [AttendancePolicyController::class, 'violations'])->name('violations')->middleware('crud.permissions:attendance.policy.view');
        Route::get('/compliance',                                                         [AttendancePolicyController::class, 'compliance'])->name('compliance')->middleware('crud.permissions:attendance.policy.view');
        Route::get('/exceptions',                                                         [AttendancePolicyController::class, 'exceptions'])->name('exceptions')->middleware('crud.permissions:attendance.policy.view');
        Route::post('/scan',                                                              [AttendancePolicyController::class, 'runScan'])->name('scan')->middleware('crud.permissions:attendance.policy.scan');
        Route::post('/generate-compliance',                                               [AttendancePolicyController::class, 'generateCompliance'])->name('generate-compliance')->middleware('crud.permissions:attendance.policy.manage');
        Route::post('/rules/{attendancePolicyRule}/toggle',                               [AttendancePolicyController::class, 'toggleRule'])->name('rules.toggle')->middleware('crud.permissions:attendance.policy.manage');
        Route::post('/violations/{attendancePolicyViolation}/escalate',                   [AttendancePolicyController::class, 'escalate'])->name('violations.escalate')->middleware('crud.permissions:attendance.policy.manage');
        Route::post('/violations/{attendancePolicyViolation}/resolve',                    [AttendancePolicyController::class, 'resolve'])->name('violations.resolve')->middleware('crud.permissions:attendance.policy.manage');
        Route::post('/violations/{attendancePolicyViolation}/grant-exception',            [AttendancePolicyController::class, 'grantException'])->name('violations.grant-exception')->middleware('crud.permissions:attendance.policy.manage');
        Route::post('/violations/{attendancePolicyViolation}/ai-enforcement',             [AttendancePolicyController::class, 'generateAiEnforcement'])->name('violations.ai-enforcement')->middleware('crud.permissions:attendance.policy.ai');
        Route::post('/compliance/{attendanceComplianceScore}/ai-summary',                 [AttendancePolicyController::class, 'generateComplianceSummary'])->name('compliance.ai-summary')->middleware('crud.permissions:attendance.policy.ai');
    });

    // ── Module 9: Workforce Productivity Analytics ────────────────────────────
    Route::prefix('productivity')->name('productivity.')->group(function () {
        Route::get('/',              [AttendanceProductivityController::class, 'index'])->name('index')->middleware('crud.permissions:attendance.productivity.view');
        Route::get('/departments',   [AttendanceProductivityController::class, 'departments'])->name('departments')->middleware('crud.permissions:attendance.productivity.view');
        Route::get('/trends',        [AttendanceProductivityController::class, 'trends'])->name('trends')->middleware('crud.permissions:attendance.productivity.view');
        Route::post('/scan',         [AttendanceProductivityController::class, 'runScan'])->name('scan')->middleware('crud.permissions:attendance.productivity.scan');
        Route::post('/insights',     [AttendanceProductivityController::class, 'generateInsights'])->name('insights')->middleware('crud.permissions:attendance.productivity.ai');
        Route::post('/departments/{departmentEfficiencyScore}/insights', [AttendanceProductivityController::class, 'generateDeptInsights'])->name('departments.insights')->middleware('crud.permissions:attendance.productivity.ai');
    });

    // ── Module 10: Audit & Activity Logging ──────────────────────────────────
    Route::prefix('audit')->name('audit.')->group(function () {
        Route::get('/', [AttendanceAuditController::class, 'index'])->name('index')->middleware('crud.permissions:attendance.audit.view');
    });

    // ── Analytics ─────────────────────────────────────────────────────────────
    Route::get('/analytics', [AttendanceAnalyticsController::class, 'index'])->name('analytics');

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',                                           [AttendanceReportsController::class, 'index'])->name('index')->middleware('crud.permissions:attendance.reports.view');
        Route::get('/generate/{key}',                            [AttendanceReportsController::class, 'generate'])->name('generate')->middleware('crud.permissions:attendance.reports.view');
        Route::get('/export/{key}/{format}',                     [AttendanceReportsController::class, 'export'])->name('export')->middleware('crud.permissions:attendance.reports.export');
        Route::post('/queue/{key}',                              [AttendanceReportsController::class, 'queue'])->name('queue')->middleware('crud.permissions:attendance.reports.export');
        Route::get('/scheduled',                                 [AttendanceReportsController::class, 'scheduledReports'])->name('scheduled')->middleware('crud.permissions:attendance.reports.schedule');
        Route::post('/scheduled',                                [AttendanceReportsController::class, 'storeScheduled'])->name('scheduled.store')->middleware('crud.permissions:attendance.reports.schedule');
        Route::patch('/scheduled/{scheduledReport}',             [AttendanceReportsController::class, 'updateScheduled'])->name('scheduled.update')->middleware('crud.permissions:attendance.reports.schedule');
        Route::delete('/scheduled/{scheduledReport}',            [AttendanceReportsController::class, 'destroyScheduled'])->name('scheduled.destroy')->middleware('crud.permissions:attendance.reports.schedule');
    });

    // ── Executive Dashboard ───────────────────────────────────────────────────
    Route::prefix('executive')->name('executive.')->group(function () {
        Route::get('/',           [AttendanceReportsController::class, 'executive'])->name('index')->middleware('crud.permissions:attendance.executive.view');
        Route::post('/ai-summary',[AttendanceReportsController::class, 'executiveAiSummary'])->name('ai-summary')->middleware('crud.permissions:attendance.executive.view');
    });

    // ── Analytics: Late-Arrival Heatmap & Absenteeism Cost ───────────────────
    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/heatmap', [AttendanceHeatmapController::class, 'index'])
            ->name('heatmap')
            ->middleware('crud.permissions:attendance.productivity.view');
        Route::get('/absenteeism-cost', [AbsenteeismCostController::class, 'index'])
            ->name('absenteeism-cost')
            ->middleware('crud.permissions:attendance.productivity.view');
    });

    // ── BCEA Compliance ───────────────────────────────────────────────────────
    Route::prefix('bcea')->name('bcea.')->group(function () {
        Route::get('/', [BceaComplianceController::class, 'index'])
            ->name('index')
            ->middleware('crud.permissions:attendance.intelligence.view');
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/',               [AttendanceSettingsController::class, 'index'])->name('index');
        Route::put('/general',        [AttendanceSettingsController::class, 'updateSettings'])->name('update');
        Route::put('/ers',            [AttendanceSettingsController::class, 'updateErsSettings'])->name('ers.update');
        Route::post('/ers/test',      [AttendanceSettingsController::class, 'testErsConnection'])->name('ers.test');
        Route::put('/notifications',  [AttendanceSettingsController::class, 'updateNotifications'])->name('notifications.update');
    });
});


/*
 * The time-and-attendance engine: people (workers, crews, consent, badges), the roster (shifts,
 * templates, rotating patterns, swaps), timesheets (clocking, sessions, corrections, overtime),
 * piecework, the payroll handoff and their settings — for everyone, and on a farm for its people.
 *
 * Its own group: the controllers ask for a T&A permission or the farm permission a farm role
 * carries (EngineController), so a farm manager without a T&A access tier still reaches their
 * farms' people. Times typed in are read in the site's zone (FarmTimeInput).
 */
Route::middleware(['web', 'auth', 'module.active:time_attendance', \App\Modules\Agri\Http\Middleware\FarmTimeInput::class])
    ->prefix('attendance')->name('attendance.')->group(function () {
    Route::get('/people', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'index'])->name('people.index');
    Route::post('/people', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'enlist'])->name('people.store');
    Route::post('/people/bulk', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'registerMany'])->name('people.bulk');
    Route::patch('/people/{worker}', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'update'])->name('people.update');
    Route::post('/people/{worker}/status', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'status'])->name('people.status');
    Route::post('/people/{worker}/consent', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'consent'])->name('people.consent');
    Route::get('/people/{worker}/badge', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'badge'])->name('people.badge');
    Route::post('/people/{worker}/badge', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'newBadge'])->name('people.badge.renew');
    Route::post('/people/{worker}/unavailable', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'unavailable'])->name('people.unavailable');
    Route::post('/crews', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'storeCrew'])->name('crews.store');
    Route::get('/time-settings', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'settings'])->name('time-settings');
    Route::post('/time-settings/devices', [\App\Modules\HR\Hr\Attendance\Http\Controllers\ClockDeviceController::class, 'store'])->name('time-settings.devices.store');
    Route::post('/time-settings/devices/{device}/secret', [\App\Modules\HR\Hr\Attendance\Http\Controllers\ClockDeviceController::class, 'renew'])->name('time-settings.devices.renew');
    Route::post('/time-settings/devices/{device}/toggle', [\App\Modules\HR\Hr\Attendance\Http\Controllers\ClockDeviceController::class, 'toggle'])->name('time-settings.devices.toggle');
    Route::post('/time-settings/rules', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'saveRuleSet'])->name('time-settings.rules.store');
    Route::patch('/time-settings/rules/{ruleSet}', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'saveRuleSet'])->name('time-settings.rules.update');
    Route::post('/time-settings/templates', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'saveTemplate'])->name('time-settings.templates.store');
    Route::patch('/time-settings/templates/{template}', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'saveTemplate'])->name('time-settings.templates.update');
    Route::post('/time-settings/rates', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'saveRate'])->name('time-settings.rates.store');
    Route::post('/time-settings/rates/{rate}/approve', [\App\Modules\HR\Hr\Attendance\Http\Controllers\WorkforceController::class, 'approveRate'])->name('time-settings.rates.approve');
    Route::get('/roster', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'index'])->name('roster.index');
    Route::post('/roster/shifts', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'storeShift'])->name('roster.shifts.store');
    Route::patch('/roster/shifts/{shift}', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'updateShift'])->name('roster.shifts.update');
    Route::post('/roster/shifts/{shift}/cancel', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'cancelShift'])->name('roster.shifts.cancel');
    Route::post('/roster/shifts/{shift}/assign', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'assign'])->name('roster.shifts.assign');
    Route::get('/roster/shifts/{shift}/check', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'check'])->name('roster.shifts.check');
    Route::get('/roster/shifts/{shift}/history', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'history'])->name('roster.shifts.history');
    Route::post('/roster/assignments/{assignment}/remove', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'unassign'])->name('roster.assignments.remove');
    Route::post('/roster/assignments/{assignment}/swap', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'requestSwap'])->name('roster.swaps.store');
    Route::post('/roster/swaps/{swap}', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'reviewSwap'])->name('roster.swaps.review');
    Route::post('/roster/rotate', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'rotate'])->name('roster.rotate');
    Route::post('/roster/patterns', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'savePattern'])->name('roster.patterns.store');
    Route::post('/roster/patterns/{pattern}/apply', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'applyPattern'])->name('roster.patterns.apply');
    Route::post('/roster/patterns/{pattern}/retire', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'retirePattern'])->name('roster.patterns.retire');
    Route::post('/roster/publish', [\App\Modules\HR\Hr\Attendance\Http\Controllers\RosterController::class, 'publish'])->name('roster.publish');
    Route::get('/timesheets', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'index'])->name('timesheets.index');
    Route::get('/badge-kiosk', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'kiosk'])->name('badge-kiosk');
    Route::post('/timesheets/clock', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'clock'])->name('timesheets.clock');
    Route::post('/timesheets/approve', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'approveMany'])->name('timesheets.approve-many');
    Route::post('/timesheets/{timesheet}/decide', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'decide'])->name('timesheets.decide');
    Route::get('/timesheets/{timesheet}/events', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'events'])->name('timesheets.events');
    Route::post('/timesheets/{timesheet}/corrections', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'requestCorrection'])->name('timesheets.corrections.store');
    Route::post('/timesheets/corrections/{correction}', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'reviewCorrection'])->name('timesheets.corrections.review');
    Route::post('/timesheets/overtime', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'requestOvertime'])->name('timesheets.overtime.store');
    Route::post('/timesheets/overtime/{overtime}', [\App\Modules\HR\Hr\Attendance\Http\Controllers\AttendanceController::class, 'reviewOvertime'])->name('timesheets.overtime.review');
    Route::get('/piecework', [\App\Modules\HR\Hr\Attendance\Http\Controllers\PieceworkController::class, 'index'])->name('piecework.index');
    Route::post('/piecework', [\App\Modules\HR\Hr\Attendance\Http\Controllers\PieceworkController::class, 'store'])->name('piecework.store');
    Route::post('/piecework/{entry}/review', [\App\Modules\HR\Hr\Attendance\Http\Controllers\PieceworkController::class, 'review'])->name('piecework.review');
    Route::get('/payroll-handoff', [\App\Modules\HR\Hr\Attendance\Http\Controllers\PayrollHandoffController::class, 'index'])->name('payroll-handoff.index');
    Route::post('/payroll-handoff', [\App\Modules\HR\Hr\Attendance\Http\Controllers\PayrollHandoffController::class, 'prepare'])->name('payroll-handoff.prepare');
    Route::post('/payroll-handoff/{handoff}/send', [\App\Modules\HR\Hr\Attendance\Http\Controllers\PayrollHandoffController::class, 'send'])->name('payroll-handoff.send');
    Route::post('/payroll-handoff/{handoff}/reconcile', [\App\Modules\HR\Hr\Attendance\Http\Controllers\PayrollHandoffController::class, 'reconcile'])->name('payroll-handoff.reconcile');
    Route::get('/payroll-handoff/{handoff}/export', [\App\Modules\HR\Hr\Attendance\Http\Controllers\PayrollHandoffController::class, 'export'])->name('payroll-handoff.export');
});
