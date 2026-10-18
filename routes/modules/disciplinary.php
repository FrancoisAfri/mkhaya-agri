<?php

use App\Modules\Disciplinary\Controllers\DisciplinaryAiInsightController;
use App\Modules\Disciplinary\Controllers\DisciplinaryAppealController;
use App\Modules\Disciplinary\Controllers\DisciplinaryCorrectiveActionController;
use App\Modules\Disciplinary\Controllers\DisciplinaryPolicyEnforcementController;
use App\Modules\Disciplinary\Controllers\DisciplinaryCaseController;
use App\Modules\Disciplinary\Controllers\DisciplinaryDashboardController;
use App\Modules\Disciplinary\Controllers\DisciplinaryEvidenceIntelligenceController;
use App\Modules\Disciplinary\Controllers\DisciplinaryHearingController;
use App\Modules\Disciplinary\Controllers\DisciplinaryIncidentController;
use App\Modules\Disciplinary\Controllers\DisciplinaryInvestigationController;
use App\Modules\Disciplinary\Controllers\DisciplinaryMyDashboardController;
use App\Modules\Disciplinary\Controllers\DisciplinaryProgressiveDisciplineController;
use App\Modules\Disciplinary\Controllers\DisciplinaryExecutiveAiController;
use App\Modules\Disciplinary\Controllers\DisciplinaryExecutiveDashboardController;
use App\Modules\Disciplinary\Controllers\DisciplinaryNotificationsEscalationsController;
use App\Modules\Disciplinary\Controllers\DisciplinaryReportController;
use App\Modules\Disciplinary\Models\DisciplinaryScheduledReport;
use App\Modules\Disciplinary\Controllers\DisciplinaryRiskIntelligenceController;
use App\Modules\Disciplinary\Controllers\DisciplinarySetupController;
use App\Modules\Disciplinary\Controllers\DisciplinaryWorkforceIntelligenceController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['module.active:disciplinary', 'module.tier:disciplinary'])->prefix('disciplinary')->name('disciplinary.')->group(function () {

    // ── Navigation hubs — role-filtered feature-card landing pages ────────────
    foreach (['cases-hub' => 'cases', 'hearings-hub' => 'hearings', 'intelligence-hub' => 'intelligence', 'insights-hub' => 'insights', 'configure-hub' => 'configure'] as $hubPath => $group) {
        Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
            ->defaults('module', 'disciplinary')->defaults('group', $group)->name('hub.' . $group);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/', [DisciplinaryDashboardController::class, 'index'])
        ->name('dashboard');

    // ── My Dashboard ──────────────────────────────────────────────────────────
    Route::get('/my-dashboard', [DisciplinaryMyDashboardController::class, 'index'])
        ->name('my-dashboard');
    Route::post('/my-dashboard/cases/{case}/notes', [DisciplinaryMyDashboardController::class, 'saveNotes'])
        ->name('my-dashboard.save-notes');

    // ── Cases ─────────────────────────────────────────────────────────────────
    Route::prefix('cases')->name('cases.')->group(function () {
        Route::get('/', [DisciplinaryCaseController::class, 'index'])->name('index');
        Route::post('/', [DisciplinaryCaseController::class, 'store'])->name('store');
        Route::get('/{case}', [DisciplinaryCaseController::class, 'show'])->name('show');
        Route::put('/{case}', [DisciplinaryCaseController::class, 'update'])->name('update');

        Route::post('/{case}/status', [DisciplinaryCaseController::class, 'updateStatus'])->name('update-status');
        Route::post('/{case}/investigator', [DisciplinaryCaseController::class, 'assignInvestigator'])->name('assign-investigator');
        Route::post('/{case}/chairperson', [DisciplinaryCaseController::class, 'assignChairperson'])->name('assign-chairperson');
        Route::post('/{case}/close', [DisciplinaryCaseController::class, 'closeCase'])->name('close');
    });

    // ── Incidents ─────────────────────────────────────────────────────────────
    Route::prefix('incidents')->name('incidents.')->group(function () {
        Route::get('/',                                          [DisciplinaryIncidentController::class, 'index'])->name('index');
        Route::get('/report',                                    [DisciplinaryIncidentController::class, 'showReport'])->name('report');
        Route::post('/report',                                   [DisciplinaryIncidentController::class, 'report'])->name('submit');
        Route::get('/generate-number',                           [DisciplinaryIncidentController::class, 'generateCaseNumber'])->name('generate-number');
        Route::get('/{incident}',                                [DisciplinaryIncidentController::class, 'show'])->name('show');
        Route::post('/{incident}/upload-evidence',               [DisciplinaryIncidentController::class, 'uploadEvidence'])->name('upload-evidence');
        Route::get('/evidence/{evidence}/download',              [DisciplinaryIncidentController::class, 'downloadEvidence'])->name('evidence.download');
        Route::post('/{incident}/witness-statement',             [DisciplinaryIncidentController::class, 'captureWitnessStatement'])->name('witness-statement');
        Route::post('/{incident}/add-witness',                   [DisciplinaryIncidentController::class, 'addWitness'])->name('add-witness');
        Route::post('/{incident}/status',                        [DisciplinaryIncidentController::class, 'updateStatus'])->name('update-status');
        Route::post('/{incident}/assign-investigator',           [DisciplinaryIncidentController::class, 'assignInvestigator'])->name('assign-investigator');
    });

    // ── AI Insights (Module 3) ────────────────────────────────────────────────
    Route::prefix('ai-insights')->name('ai-insights.')->group(function () {
        Route::get('/', [DisciplinaryAiInsightController::class, 'index'])->name('index');
        Route::post('/analyze', [DisciplinaryAiInsightController::class, 'runAnalysis'])->name('analyze');
        Route::post('/cases/{case}/summary', [DisciplinaryAiInsightController::class, 'generateCaseSummary'])->name('case-summary');
        Route::post('/cases/{case}/sanction', [DisciplinaryAiInsightController::class, 'generateSanctionRecommendation'])->name('sanction-recommendation');
        Route::get('/cases/{case}/summaries', [DisciplinaryAiInsightController::class, 'caseSummaries'])->name('case-summaries');
        Route::post('/patterns/{pattern}/acknowledge', [DisciplinaryAiInsightController::class, 'acknowledgePattern'])->name('acknowledge-pattern');
    });

    // ── Investigations (Module 4) ──────────────────────────────────────────────
    Route::prefix('investigations')->name('investigations.')->group(function () {
        Route::get('/', [DisciplinaryInvestigationController::class, 'index'])->name('index');
        Route::post('/', [DisciplinaryInvestigationController::class, 'store'])->name('store');
        Route::get('/{investigation}', [DisciplinaryInvestigationController::class, 'show'])->name('show');
        Route::put('/{investigation}', [DisciplinaryInvestigationController::class, 'update'])->name('update');
        Route::post('/{investigation}/notes', [DisciplinaryInvestigationController::class, 'addNote'])->name('add-note');
        Route::post('/{investigation}/report', [DisciplinaryInvestigationController::class, 'generateReport'])->name('generate-report');
        Route::get('/{investigation}/reports/{report}/download', [DisciplinaryInvestigationController::class, 'downloadReport'])->name('report.download');
        Route::post('/{investigation}/assign-investigator', [DisciplinaryInvestigationController::class, 'assignInvestigator'])->name('assign-investigator');
    });

    // ── Hearings (Module 5) ────────────────────────────────────────────────────
    Route::prefix('hearings')->name('hearings.')->group(function () {
        Route::get('/', [DisciplinaryHearingController::class, 'index'])->name('index');
        Route::post('/', [DisciplinaryHearingController::class, 'store'])->name('store');
        Route::get('/{hearing}', [DisciplinaryHearingController::class, 'show'])->name('show');
        Route::put('/{hearing}', [DisciplinaryHearingController::class, 'update'])->name('update');
        Route::post('/{hearing}/issue-notice', [DisciplinaryHearingController::class, 'issueNotice'])->name('issue-notice');
        Route::post('/{hearing}/generate-pack', [DisciplinaryHearingController::class, 'generatePack'])->name('generate-pack');
        Route::get('/{hearing}/download-pack', [DisciplinaryHearingController::class, 'downloadPack'])->name('download-pack');
        Route::post('/{hearing}/add-minute', [DisciplinaryHearingController::class, 'addMinute'])->name('add-minute');
        Route::post('/{hearing}/add-attendee', [DisciplinaryHearingController::class, 'addAttendee'])->name('add-attendee');
        Route::post('/{hearing}/mark-attendance', [DisciplinaryHearingController::class, 'markAttendance'])->name('mark-attendance');
        Route::post('/{hearing}/capture-outcome', [DisciplinaryHearingController::class, 'captureOutcome'])->name('capture-outcome');
    });

    // ── Evidence Intelligence (Module 6) ──────────────────────────────────────
    Route::prefix('intelligence')->name('intelligence.')->group(function () {
        Route::get('/cases/{case}/timeline', [DisciplinaryEvidenceIntelligenceController::class, 'caseTimeline'])->name('case-timeline');
        Route::get('/cases/{case}/reports', [DisciplinaryEvidenceIntelligenceController::class, 'caseReports'])->name('case-reports');
        Route::post('/cases/{case}/analyze-witnesses', [DisciplinaryEvidenceIntelligenceController::class, 'analyzeWitnessConsistency'])->name('analyze-witnesses');
        Route::post('/cases/{case}/evidence-summary', [DisciplinaryEvidenceIntelligenceController::class, 'caseEvidenceSummary'])->name('case-evidence-summary');
        Route::post('/cases/{case}/hearing-pack', [DisciplinaryEvidenceIntelligenceController::class, 'generateHearingPack'])->name('generate-hearing-pack');
        Route::post('/incidents/{incident}/analyze-witnesses', [DisciplinaryEvidenceIntelligenceController::class, 'analyzeIncidentWitnesses'])->name('analyze-incident-witnesses');
        Route::post('/incidents/{incident}/evidence-summary', [DisciplinaryEvidenceIntelligenceController::class, 'incidentEvidenceSummary'])->name('incident-evidence-summary');
        Route::get('/reports/{report}/download', [DisciplinaryEvidenceIntelligenceController::class, 'downloadReport'])->name('report.download');
    });

    // ── Policy Enforcement (Module 7) ────────────────────────────────────────
    Route::prefix('policy-enforcement')->name('policy-enforcement.')->group(function () {
        Route::get('/', [DisciplinaryPolicyEnforcementController::class, 'index'])->name('index');
        Route::post('/violations/{violation}/request-exception', [DisciplinaryPolicyEnforcementController::class, 'requestException'])->name('request-exception');
        Route::post('/violations/{violation}/resolve', [DisciplinaryPolicyEnforcementController::class, 'resolveViolation'])->name('resolve-violation');
        Route::post('/exceptions/{exception}/review', [DisciplinaryPolicyEnforcementController::class, 'reviewException'])->name('review-exception');
    });

    // ── Progressive Discipline (Module 8) ───────────────────────────────────
    Route::prefix('progressive-discipline')->name('progressive-discipline.')->group(function () {
        Route::get('/warnings',                                    [DisciplinaryProgressiveDisciplineController::class, 'indexWarnings'])->name('warnings.index');
        Route::post('/warnings',                                   [DisciplinaryProgressiveDisciplineController::class, 'storeWarning'])->name('warnings.store');
        Route::get('/suspensions',                                 [DisciplinaryProgressiveDisciplineController::class, 'indexSuspensions'])->name('suspensions.index');
        Route::post('/suspensions',                                [DisciplinaryProgressiveDisciplineController::class, 'storeSuspension'])->name('suspensions.store');
        Route::post('/suspensions/{suspension}/end',               [DisciplinaryProgressiveDisciplineController::class, 'endSuspension'])->name('suspensions.end');
        Route::post('/suspensions/{suspension}/cancel',            [DisciplinaryProgressiveDisciplineController::class, 'cancelSuspension'])->name('suspensions.cancel');
        Route::post('/suspensions/{suspension}/extend',            [DisciplinaryProgressiveDisciplineController::class, 'extendSuspension'])->name('suspensions.extend');
        Route::post('/suspensions/{suspension}/convert',           [DisciplinaryProgressiveDisciplineController::class, 'convertSuspension'])->name('suspensions.convert');
        Route::post('/suspensions/{suspension}/note',              [DisciplinaryProgressiveDisciplineController::class, 'addSuspensionNote'])->name('suspensions.note');
        Route::get('/escalations',                                 [DisciplinaryProgressiveDisciplineController::class, 'indexEscalations'])->name('escalations.index');
        Route::post('/escalations/recommend',                      [DisciplinaryProgressiveDisciplineController::class, 'recommendEscalation'])->name('escalations.recommend');
        Route::post('/escalations/{escalation}/approve',           [DisciplinaryProgressiveDisciplineController::class, 'approveEscalation'])->name('escalations.approve');
        Route::get('/employees/{employeeId}/history',              [DisciplinaryProgressiveDisciplineController::class, 'employeeHistory'])->name('employee-history');
    });

    // ── Workforce Intelligence (Module 9) ────────────────────────────────────
    Route::prefix('workforce-intelligence')->name('workforce-intelligence.')->group(function () {
        Route::get('/',                                            [DisciplinaryWorkforceIntelligenceController::class, 'index'])->name('index');
        Route::post('/generate',                                   [DisciplinaryWorkforceIntelligenceController::class, 'generate'])->name('generate');
        Route::get('/employees/{employeeId}',                      [DisciplinaryWorkforceIntelligenceController::class, 'showEmployee'])->name('employee');
    });

    // ── Corrective Actions (Module 10) ───────────────────────────────────────
    Route::prefix('corrective-actions')->name('corrective-actions.')->group(function () {
        Route::get('/',                                            [DisciplinaryCorrectiveActionController::class, 'index'])->name('index');
        Route::post('/',                                           [DisciplinaryCorrectiveActionController::class, 'store'])->name('store');
        Route::get('/{action}',                                    [DisciplinaryCorrectiveActionController::class, 'show'])->name('show');
        Route::post('/{action}/progress',                          [DisciplinaryCorrectiveActionController::class, 'updateProgress'])->name('update-progress');
        Route::post('/ai-recommend',                               [DisciplinaryCorrectiveActionController::class, 'recommendActions'])->name('ai-recommend');
        Route::post('/sessions',                                   [DisciplinaryCorrectiveActionController::class, 'storeSession'])->name('sessions.store');
        Route::post('/sessions/{session}/complete',                [DisciplinaryCorrectiveActionController::class, 'completeSession'])->name('sessions.complete');
        Route::post('/sessions/{session}/reschedule',              [DisciplinaryCorrectiveActionController::class, 'rescheduleSession'])->name('sessions.reschedule');
        Route::post('/rehabilitation',                             [DisciplinaryCorrectiveActionController::class, 'storeRehabilitation'])->name('rehabilitation.store');
    });

    // ── Appeals (Module 11) ──────────────────────────────────────────────────
    Route::prefix('appeals')->name('appeals.')->group(function () {
        Route::get('/',                                            [DisciplinaryAppealController::class, 'index'])->name('index');
        Route::post('/',                                           [DisciplinaryAppealController::class, 'submitAppeal'])->name('submit');
        Route::get('/{appeal}',                                    [DisciplinaryAppealController::class, 'show'])->name('show');
        Route::post('/{appeal}/assign-chairperson',                [DisciplinaryAppealController::class, 'assignChairperson'])->name('assign-chairperson');
        Route::post('/{appeal}/schedule-hearing',                  [DisciplinaryAppealController::class, 'scheduleHearing'])->name('schedule-hearing');
        Route::post('/{appeal}/evidence',                          [DisciplinaryAppealController::class, 'captureEvidence'])->name('evidence.store');
        Route::get('/evidence/{evidence}/download',                [DisciplinaryAppealController::class, 'downloadEvidence'])->name('evidence.download');
        Route::post('/{appeal}/generate-pack',                     [DisciplinaryAppealController::class, 'generatePack'])->name('generate-pack');
        Route::post('/{appeal}/outcome',                           [DisciplinaryAppealController::class, 'captureOutcome'])->name('outcome');
        Route::post('/{appeal}/withdraw',                          [DisciplinaryAppealController::class, 'withdraw'])->name('withdraw');
    });

    // ── Risk Intelligence (Module 12) ────────────────────────────────────────
    Route::prefix('risk-intelligence')->name('risk-intelligence.')->group(function () {
        Route::get('/',                                            [DisciplinaryRiskIntelligenceController::class, 'index'])->name('index');
        Route::post('/predict-employee',                           [DisciplinaryRiskIntelligenceController::class, 'predictEmployee'])->name('predict-employee');
        Route::post('/predictions/{prediction}/action',            [DisciplinaryRiskIntelligenceController::class, 'actionPrediction'])->name('action-prediction');
        Route::post('/analyze-department',                         [DisciplinaryRiskIntelligenceController::class, 'analyzeDepartment'])->name('analyze-department');
        Route::post('/heatmap',                                    [DisciplinaryRiskIntelligenceController::class, 'generateHeatmap'])->name('heatmap');
    });

    // ── Setup ─────────────────────────────────────────────────────────────────
    Route::prefix('setup')->name('setup.')->group(function () {
        Route::get('/', [DisciplinarySetupController::class, 'index'])->name('index');

        // Categories
        Route::post('/categories', [DisciplinarySetupController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{id}', [DisciplinarySetupController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{id}', [DisciplinarySetupController::class, 'destroyCategory'])->name('categories.destroy');

        // Statuses
        Route::post('/statuses', [DisciplinarySetupController::class, 'storeStatus'])->name('statuses.store');
        Route::put('/statuses/{id}', [DisciplinarySetupController::class, 'updateStatus'])->name('statuses.update');
        Route::delete('/statuses/{id}', [DisciplinarySetupController::class, 'destroyStatus'])->name('statuses.destroy');

        // Outcomes
        Route::post('/outcomes', [DisciplinarySetupController::class, 'storeOutcome'])->name('outcomes.store');
        Route::put('/outcomes/{id}', [DisciplinarySetupController::class, 'updateOutcome'])->name('outcomes.update');
        Route::delete('/outcomes/{id}', [DisciplinarySetupController::class, 'destroyOutcome'])->name('outcomes.destroy');

        // Sanctions
        Route::post('/sanctions', [DisciplinarySetupController::class, 'storeSanction'])->name('sanctions.store');
        Route::put('/sanctions/{id}', [DisciplinarySetupController::class, 'updateSanction'])->name('sanctions.update');
        Route::delete('/sanctions/{id}', [DisciplinarySetupController::class, 'destroySanction'])->name('sanctions.destroy');

        // Settings
        Route::post('/settings', [DisciplinarySetupController::class, 'saveSettings'])->name('settings.save');
    });

    // ── Module 14: Notifications & Escalations ────────────────────────────────
    Route::prefix('notifications-escalations')->name('notifications-escalations.')->group(function () {
        Route::get('/',                                             [DisciplinaryNotificationsEscalationsController::class, 'index'])->name('index');
        Route::post('/run-escalations',                            [DisciplinaryNotificationsEscalationsController::class, 'runEscalations'])->name('run');
        Route::post('/cases/{case}/notify-case',                   [DisciplinaryNotificationsEscalationsController::class, 'notifyCase'])->name('notify-case');
        Route::post('/hearings/{hearing}/notify',                  [DisciplinaryNotificationsEscalationsController::class, 'notifyHearing'])->name('notify-hearing');
        Route::post('/cases/{case}/notify-investigator',           [DisciplinaryNotificationsEscalationsController::class, 'notifyInvestigator'])->name('notify-investigator');
        Route::post('/cases/{case}/notify-employee',               [DisciplinaryNotificationsEscalationsController::class, 'notifyEmployee'])->name('notify-employee');
        Route::post('/cases/{case}/notify-dismissal',              [DisciplinaryNotificationsEscalationsController::class, 'notifyDismissal'])->name('notify-dismissal');
    });

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',                                             [DisciplinaryReportController::class, 'index'])->name('index');
        Route::get('/scheduled',                                    [DisciplinaryReportController::class, 'scheduledReports'])->name('scheduled');
        Route::post('/scheduled',                                   [DisciplinaryReportController::class, 'storeScheduledReport'])->name('scheduled.store');
        Route::patch('/scheduled/{scheduledReport}',               [DisciplinaryReportController::class, 'updateScheduledReport'])->name('scheduled.update');
        Route::delete('/scheduled/{scheduledReport}',              [DisciplinaryReportController::class, 'destroyScheduledReport'])->name('scheduled.destroy');
        Route::get('/export/{key}/{format}',                        [DisciplinaryReportController::class, 'exportReport'])->name('export');
    });

    // ── Module 15: Executive Dashboard ────────────────────────────────────────
    Route::get('/executive-dashboard', [DisciplinaryExecutiveDashboardController::class, 'index'])
        ->name('executive-dashboard');

    // ── Module 16: AI Workforce Intelligence ──────────────────────────────────
    Route::prefix('executive-ai-insights')->name('executive-ai.')->group(function () {
        Route::get('/',                       [DisciplinaryExecutiveAiController::class, 'index'])->name('index');
        Route::post('/generate-all',          [DisciplinaryExecutiveAiController::class, 'generateAll'])->name('generate-all');
        Route::post('/summary',               [DisciplinaryExecutiveAiController::class, 'generateSummary'])->name('summary');
        Route::post('/trend-analysis',        [DisciplinaryExecutiveAiController::class, 'generateTrendAnalysis'])->name('trend-analysis');
        Route::post('/compliance-insights',   [DisciplinaryExecutiveAiController::class, 'generateComplianceInsights'])->name('compliance-insights');
        Route::post('/behavioral-risk',       [DisciplinaryExecutiveAiController::class, 'generateBehavioralRisk'])->name('behavioral-risk');
        Route::post('/corrective-report',     [DisciplinaryExecutiveAiController::class, 'generateCorrectiveReport'])->name('corrective-report');
    });
});
