<?php

use App\Modules\HR\Payroll\Controllers\CoidaController;
use App\Modules\HR\Payroll\Controllers\LabourRateController;
use App\Modules\HR\Payroll\Controllers\EmploymentEquityController;
use App\Modules\HR\Payroll\Controllers\BargainingCouncilController;
use App\Modules\HR\Payroll\Controllers\RemittanceController;
use App\Modules\HR\Payroll\Controllers\SkillsLevyController;
use App\Modules\HR\Payroll\Controllers\EmploymentMilestoneController;
use App\Modules\HR\Payroll\Controllers\FileSpecificationController;
use App\Modules\HR\Payroll\Controllers\TaxCertificateController;
use App\Modules\HR\Payroll\Controllers\PayrollDashboardController;
use App\Modules\HR\Payroll\Controllers\PayrollRunController;
use App\Modules\HR\Payroll\Controllers\PayrollAnomalyController;
use App\Modules\HR\Payroll\Controllers\PayrollApprovalController;
use App\Modules\HR\Payroll\Controllers\PayrollCrossCheckController;
use App\Modules\HR\Payroll\Controllers\PayrollLeakageController;
use App\Modules\HR\Payroll\Controllers\PayrollGhostController;
use App\Modules\HR\Payroll\Controllers\PayrollBehaviorController;
use App\Modules\HR\Payroll\Controllers\PayrollChangeMonitorController;
use App\Modules\HR\Payroll\Controllers\PayrollPayslipAIController;
use App\Modules\HR\Payroll\Controllers\PayrollAIChatController;
use App\Modules\HR\Payroll\Controllers\PayrollSimulationController;
use App\Modules\HR\Payroll\Controllers\PayrollAnalyticsController;
use App\Modules\HR\Payroll\Controllers\PayrollSettingsController;
use App\Modules\HR\Payroll\Controllers\PayrollReportController;
use App\Modules\HR\Payroll\Controllers\PayrollScheduledReportController;
use App\Modules\HR\Payroll\Controllers\PayrollForecastController;
use App\Modules\HR\Payroll\Controllers\PayrollComplianceController;
use App\Modules\HR\Payroll\Controllers\Emp201Controller;
use App\Modules\HR\Payroll\Controllers\EtiController;
use App\Modules\HR\Payroll\Controllers\DeductionReferenceController;
use App\Modules\HR\Payroll\Controllers\Emp501Controller;
use App\Modules\HR\Payroll\Controllers\Ui19Controller;
use App\Modules\HR\Payroll\Controllers\PayrollCorrectionController;
use App\Modules\HR\Payroll\Controllers\PayrollNarrativeReportController;
use App\Modules\HR\Payroll\Http\Controllers\PayslipController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->get('/payroll', fn () => redirect()->route('payroll.dashboard'));

Route::middleware(['web', 'auth', 'module.active:payroll', 'module.tier:payroll'])->prefix('payroll')->name('payroll.')->group(function () {

    // ── Navigation hubs — role-filtered feature-card landing pages ────────────
    foreach (['run-hub' => 'run', 'assurance-hub' => 'assurance', 'insights-hub' => 'insights', 'configure-hub' => 'configure'] as $hubPath => $group) {
        Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
            ->defaults('module', 'payroll')->defaults('group', $group)->name('hub.' . $group);
    }
    // Dashboard
    Route::get('/dashboard', [PayrollDashboardController::class, 'index'])->name('dashboard');

    // Runs
    Route::get('/runs', [PayrollRunController::class, 'index'])->name('runs.index');
    Route::get('/runs/create', [PayrollRunController::class, 'create'])->name('runs.create');
    Route::post('/runs', [PayrollRunController::class, 'store'])->name('runs.store');
    Route::get('/runs/{run:uuid}', [PayrollRunController::class, 'show'])->name('runs.show');
    Route::post('/runs/{run:uuid}/add-employees',  [PayrollRunController::class, 'addEmployees'])->name('runs.add-employees');
    Route::delete('/runs/{run:uuid}/employees/{payslip}', [PayrollRunController::class, 'removeEmployee'])->name('runs.remove-employee')->whereNumber('payslip');
    Route::post('/runs/{run:uuid}/refresh-salaries', [PayrollRunController::class, 'refreshSalaries'])->name('runs.refresh-salaries');
    Route::post('/runs/{run:uuid}/bulk-line',        [PayrollRunController::class, 'bulkAddLine'])->name('runs.bulk-line');
    Route::post('/runs/{run:uuid}/sync-leave',       [PayrollRunController::class, 'syncLeave'])->name('runs.sync-leave');
    Route::post('/runs/{run:uuid}/calculate',      [PayrollRunController::class, 'calculateAll'])->name('runs.calculate');
    // Overtime from attendance at the BCEA multipliers. Separate from calculating because it
    // changes what people are paid; safe to press twice, because it replaces its own lines.
    Route::post('/runs/{run:uuid}/apply-overtime', [PayrollRunController::class, 'applyOvertime'])->name('runs.apply-overtime');
    Route::post('/runs/{run:uuid}/lock',           [PayrollRunController::class, 'lock'])->name('runs.lock');
    Route::post('/runs/{run:uuid}/submit',         [PayrollRunController::class, 'submit'])->name('runs.submit');
    Route::post('/runs/{run:uuid}/release',        [PayrollRunController::class, 'release'])->name('runs.release');
    Route::post('/runs/{run:uuid}/cancel',         [PayrollRunController::class, 'cancel'])->name('runs.cancel');
    Route::post('/runs/{run:uuid}/anomaly-check',  [PayrollRunController::class, 'runAnomalyCheck'])->name('runs.anomaly-check');

    // Anomalies
    Route::get('/anomalies', [PayrollAnomalyController::class, 'index'])->name('anomalies.index');
    Route::post('/anomalies/{anomaly:uuid}/resolve', [PayrollAnomalyController::class, 'resolve'])->name('anomalies.resolve');
    Route::post('/anomalies/{anomaly:uuid}/dismiss', [PayrollAnomalyController::class, 'dismiss'])->name('anomalies.dismiss');

    // Employment milestones — probation, anniversaries and incentive expiry, across both engines.
    // The worklist and the rules that fill it live on one screen: a notice nobody wants to see is a
    // rule they want switched off, and separating the two is how a worklist fills with noise.
    Route::get('/milestones', [EmploymentMilestoneController::class, 'index'])->name('milestones.index');
    Route::post('/milestones/scan', [EmploymentMilestoneController::class, 'scan'])->name('milestones.scan');
    Route::post('/milestones/rules', [EmploymentMilestoneController::class, 'storeRule'])->name('milestones.rules.store');
    Route::post('/milestones/rules/defaults', [EmploymentMilestoneController::class, 'installDefaults'])->name('milestones.rules.defaults');
    Route::put('/milestones/rules/{rule:uuid}', [EmploymentMilestoneController::class, 'updateRule'])->name('milestones.rules.update');
    Route::delete('/milestones/rules/{rule:uuid}', [EmploymentMilestoneController::class, 'destroyRule'])->name('milestones.rules.destroy');
    Route::post('/milestones/{milestone:uuid}/acknowledge', [EmploymentMilestoneController::class, 'acknowledge'])->name('milestones.acknowledge');

    // The labour-law figures — minimum wage, overtime ceiling, the BCEA multipliers. National data
    // like the tax tables, and they change every March, so they need somewhere to be entered.
    Route::get('/labour-rates', [LabourRateController::class, 'index'])->name('labour-rates.index');
    Route::post('/labour-rates', [LabourRateController::class, 'store'])->name('labour-rates.store');
    Route::put('/labour-rates/{rate}', [LabourRateController::class, 'update'])->name('labour-rates.update')->whereNumber('rate');

    // Employment Equity. EEA4 is an income report, and payroll is the only place that holds the
    // remuneration half of it — so the return is assembled here rather than in HR.
    Route::get('/employment-equity', [EmploymentEquityController::class, 'index'])->name('employment-equity.index');
    Route::get('/employment-equity/download', [EmploymentEquityController::class, 'download'])->name('employment-equity.download');

    // Bargaining councils: the levy is calculated on every run, and until now the councils and
    // rates behind it could only be set up with a SQL client.
    Route::get('/bargaining-councils', [BargainingCouncilController::class, 'index'])->name('bargaining-councils.index');
    Route::post('/bargaining-councils', [BargainingCouncilController::class, 'store'])->name('bargaining-councils.store');
    Route::put('/bargaining-councils/{uuid}', [BargainingCouncilController::class, 'update'])->name('bargaining-councils.update');
    Route::post('/bargaining-councils/{uuid}/rates', [BargainingCouncilController::class, 'storeRate'])->name('bargaining-councils.rates.store');
    Route::post('/bargaining-councils/{uuid}/members', [BargainingCouncilController::class, 'addMembers'])->name('bargaining-councils.members.store');
    Route::put('/bargaining-councils/{uuid}/members/{memberId}', [BargainingCouncilController::class, 'endMembership'])->name('bargaining-councils.members.end')->whereNumber('memberId');

    // Skills Development Levy and the SETA mandatory grant. Payroll paid the levy and the LMS
    // chased the deadline, and neither knew what the other was doing.
    Route::get('/skills-levy', [SkillsLevyController::class, 'index'])->name('skills-levy.index');
    Route::post('/skills-levy', [SkillsLevyController::class, 'record'])->name('skills-levy.record');

    // Third-party payments. Deductions came off the payslip and nothing scheduled paying them
    // over — no beneficiary records, no schedule, no way to answer "did the provident go?".
    Route::get('/remittances', [RemittanceController::class, 'index'])->name('remittances.index');
    Route::post('/remittances/beneficiaries', [RemittanceController::class, 'storeBeneficiary'])->name('remittances.beneficiaries.store');
    Route::put('/remittances/beneficiaries/{uuid}', [RemittanceController::class, 'updateBeneficiary'])->name('remittances.beneficiaries.update');
    Route::post('/remittances/rebuild/{runUuid}', [RemittanceController::class, 'rebuild'])->name('remittances.rebuild');
    Route::post('/remittances/{uuid}/paid', [RemittanceController::class, 'markPaid'])->name('remittances.paid');
    Route::get('/remittances/{uuid}/download', [RemittanceController::class, 'download'])->name('remittances.download');
    // The vendor deduction file — Q Link, PIPEDEL, or any other layout transcribed under file-specs.
    // Separate from the CSV above, which a fund's administrator reads by eye.
    Route::get('/remittances/{uuid}/deduction-file', [RemittanceController::class, 'deductionFile'])->name('remittances.deduction-file');

    // The reference numbers each fund knows each employee by. A deduction file is unusable without
    // them: the receiving system matches on its own reference, never on a name.
    Route::get('/deduction-references', [DeductionReferenceController::class, 'index'])->name('deduction-references.index');
    Route::post('/deduction-references', [DeductionReferenceController::class, 'store'])->name('deduction-references.store');
    Route::put('/deduction-references/{reference:uuid}', [DeductionReferenceController::class, 'update'])->name('deduction-references.update');
    Route::delete('/deduction-references/{reference:uuid}', [DeductionReferenceController::class, 'destroy'])->name('deduction-references.destroy');

    // COIDA — the Return of Earnings. Annual rather than monthly, and it did not exist at all:
    // the only COIDA in the codebase was a contractor's letter of good standing in SHEQ.
    Route::get('/coida', [CoidaController::class, 'index'])->name('coida.index');
    Route::post('/coida/prepare', [CoidaController::class, 'prepare'])->name('coida.prepare');
    Route::post('/coida/registration', [CoidaController::class, 'saveRegistration'])->name('coida.registration');
    Route::post('/coida/{return:uuid}/submitted', [CoidaController::class, 'markSubmitted'])->name('coida.submitted');
    Route::get('/coida/{return:uuid}/download', [CoidaController::class, 'download'])->name('coida.download');

    // Tax certificates — issued records rather than a query re-run, so an employee asking for a copy
    // in July receives the document they filed their return with.
    Route::get('/tax-certificates', [TaxCertificateController::class, 'index'])->name('tax-certificates.index');
    Route::post('/tax-certificates/generate', [TaxCertificateController::class, 'generate'])->name('tax-certificates.generate');
    Route::get('/tax-certificates/download', [TaxCertificateController::class, 'downloadAll'])->name('tax-certificates.download-all');
    Route::get('/tax-certificates/{certificate:uuid}', [TaxCertificateController::class, 'show'])->name('tax-certificates.show');
    Route::get('/tax-certificates/{certificate:uuid}/download', [TaxCertificateController::class, 'download'])->name('tax-certificates.download');

    // Outbound file layouts — bank files, SARS imports, and the Q Link and PIPEDEL deduction files.
    // Held as data and versioned by effective date, so a vendor revision is data entry by somebody
    // with their document open rather than a code change and a deploy on a file that moves salaries.
    Route::get('/file-specs', [FileSpecificationController::class, 'index'])->name('file-specs.index');
    Route::post('/file-specs', [FileSpecificationController::class, 'store'])->name('file-specs.store');
    Route::get('/file-specs/{specification:uuid}', [FileSpecificationController::class, 'show'])->name('file-specs.show');
    Route::put('/file-specs/{specification:uuid}', [FileSpecificationController::class, 'update'])->name('file-specs.update');
    Route::post('/file-specs/{specification:uuid}/version', [FileSpecificationController::class, 'duplicate'])->name('file-specs.version');
    Route::post('/file-specs/{specification:uuid}/fields', [FileSpecificationController::class, 'storeField'])->name('file-specs.fields.store');
    Route::put('/file-specs/{specification:uuid}/fields/{field:uuid}', [FileSpecificationController::class, 'updateField'])->name('file-specs.fields.update');
    Route::delete('/file-specs/{specification:uuid}/fields/{field:uuid}', [FileSpecificationController::class, 'destroyField'])->name('file-specs.fields.destroy');

    // Cross-Checks (Phase 3)
    Route::get('/cross-checks', [PayrollCrossCheckController::class, 'index'])->name('cross-checks.index');
    Route::post('/cross-checks/{run:uuid}/run', [PayrollCrossCheckController::class, 'run'])->name('cross-checks.run');

    // Leakage (Phase 4)
    Route::get('/leakage', [PayrollLeakageController::class, 'index'])->name('leakage.index');
    Route::post('/leakage/{run:uuid}/run', [PayrollLeakageController::class, 'run'])->name('leakage.run');

    // Ghost Employee Detection (Phase 5)
    Route::get('/ghost-employees', [PayrollGhostController::class, 'index'])->name('ghost.index');
    Route::post('/ghost-employees/{run:uuid}/run', [PayrollGhostController::class, 'run'])->name('ghost.run');

    // Behaviour Analytics (Phase 6)
    Route::get('/behavior', [PayrollBehaviorController::class, 'index'])->name('behavior.index');
    Route::post('/behavior/{run:uuid}/run', [PayrollBehaviorController::class, 'run'])->name('behavior.run');

    // AI Payroll Assistant (Phase 9)
    Route::get('/ai-assistant', [PayrollAIChatController::class, 'index'])->name('ai-assistant.index');
    Route::post('/ai-assistant/ask', [PayrollAIChatController::class, 'ask'])->name('ai-assistant.ask');

    // Change Log & Risk Detection (Phase 7)
    Route::get('/change-log', [PayrollChangeMonitorController::class, 'index'])->name('change-log.index');
    Route::post('/change-log/{run:uuid}/analyze', [PayrollChangeMonitorController::class, 'analyze'])->name('change-log.analyze');
    Route::post('/change-log/risks/{risk}/review', [PayrollChangeMonitorController::class, 'review'])->name('change-log.risks.review');
    Route::post('/change-log/risks/{risk}/clear', [PayrollChangeMonitorController::class, 'clear'])->name('change-log.risks.clear');

    // Approvals (Phase 16)
    Route::get('/approvals', [PayrollApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/{run:uuid}/submit', [PayrollApprovalController::class, 'submit'])->name('approvals.submit');
    Route::post('/approvals/{approval}/approve', [PayrollApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{approval}/reject', [PayrollApprovalController::class, 'reject'])->name('approvals.reject');
    Route::post('/approvals/{approval}/request-correction', [PayrollApprovalController::class, 'requestCorrection'])->name('approvals.request-correction');
    Route::post('/approvals/{approval}/escalate', [PayrollApprovalController::class, 'escalate'])->name('approvals.escalate');
    Route::post('/approvals/{approval}/comment', [PayrollApprovalController::class, 'comment'])->name('approvals.comment');

    // Simulations
    Route::get('/simulations', [PayrollSimulationController::class, 'index'])->name('simulations.index');
    Route::get('/simulations/create', [PayrollSimulationController::class, 'create'])->name('simulations.create');
    Route::post('/simulations', [PayrollSimulationController::class, 'store'])->name('simulations.store');
    Route::get('/simulations/{simulation:uuid}', [PayrollSimulationController::class, 'show'])->name('simulations.show');

    // Forecasting (Phase 11)
    Route::get('/forecasting', [PayrollForecastController::class, 'index'])->name('forecasting.index');

    // Compliance Intelligence (Phase 12)
    Route::get('/compliance', [PayrollComplianceController::class, 'index'])->name('compliance.index');
    Route::post('/compliance/{run:uuid}/run', [PayrollComplianceController::class, 'run'])->name('compliance.run');

    // EMP201 — monthly PAYE/SDL/UIF employer declaration (writes to the shared SARS history)
    Route::get('/emp201', [Emp201Controller::class, 'index'])->name('emp201');
    Route::post('/emp201/generate', [Emp201Controller::class, 'generate'])->name('emp201.generate');

    // The Employment Tax Incentive register. The ledger has been written on every run since it was
    // built and nothing has ever shown it — whereNumber so the register itself is not swallowed by
    // the employee route.
    Route::get('/eti', [EtiController::class, 'index'])->name('eti');
    Route::get('/eti/{employee}', [EtiController::class, 'show'])->name('eti.show')->whereNumber('employee');

    // EMP501 — annual reconciliation + IRP5/IT3(a) certificates
    Route::get('/emp501', [Emp501Controller::class, 'index'])->name('emp501');
    Route::post('/emp501/generate', [Emp501Controller::class, 'generate'])->name('emp501.generate');
    Route::get('/emp501/certificate/{employee}', [Emp501Controller::class, 'certificate'])->name('emp501.certificate')->whereNumber('employee');

    // UI-19 — monthly UIF employee declaration
    Route::get('/ui19', [Ui19Controller::class, 'index'])->name('ui19');
    Route::post('/ui19/generate', [Ui19Controller::class, 'generate'])->name('ui19.generate');

    // Auto-Correction (Phase 13)
    Route::get('/corrections', [PayrollCorrectionController::class, 'index'])->name('corrections.index');
    Route::post('/corrections/{run:uuid}/suggest', [PayrollCorrectionController::class, 'suggest'])->name('corrections.suggest');
    Route::post('/corrections/{correction}/approve', [PayrollCorrectionController::class, 'approve'])->name('corrections.approve');
    Route::post('/corrections/{correction}/reject', [PayrollCorrectionController::class, 'reject'])->name('corrections.reject');
    Route::post('/corrections/{correction}/apply', [PayrollCorrectionController::class, 'apply'])->name('corrections.apply');

    // Narrative Reports (Phase 14)
    Route::get('/narrative-reports', [PayrollNarrativeReportController::class, 'index'])->name('narrative-reports.index');
    Route::get('/narrative-reports/{run:uuid}/section/{section}', [PayrollNarrativeReportController::class, 'section'])->name('narrative-reports.section');
    Route::get('/narrative-reports/{run:uuid}/pdf', [PayrollNarrativeReportController::class, 'exportPdf'])->name('narrative-reports.pdf');

    // Analytics
    Route::get('/analytics', [PayrollAnalyticsController::class, 'index'])->name('analytics.index');

    // Reports
    Route::get('/reports', [PayrollReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/generate/{key}', [PayrollReportController::class, 'generate'])->name('reports.generate');
    Route::get('/reports/export/{key}/{format}', [PayrollReportController::class, 'export'])->name('reports.export');
    Route::post('/reports/queue/{key}', [PayrollReportController::class, 'queue'])->name('reports.queue');

    // Settings
    Route::get('/settings', [PayrollSettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [PayrollSettingsController::class, 'update'])->name('settings.update');

    // Configurable payroll approval chain (role/employee steps, like Fleet booking).
    Route::get('/settings/approval-steps',              [\App\Modules\HR\Payroll\Controllers\PayrollApprovalConfigController::class, 'index'])->name('settings.approval-steps.index');
    Route::post('/settings/approval-steps',             [\App\Modules\HR\Payroll\Controllers\PayrollApprovalConfigController::class, 'store'])->name('settings.approval-steps.store');
    Route::patch('/settings/approval-steps/{config}',   [\App\Modules\HR\Payroll\Controllers\PayrollApprovalConfigController::class, 'update'])->name('settings.approval-steps.update')->whereNumber('config');
    Route::delete('/settings/approval-steps/{config}',  [\App\Modules\HR\Payroll\Controllers\PayrollApprovalConfigController::class, 'destroy'])->name('settings.approval-steps.destroy')->whereNumber('config');

    // SARS tax tables viewer (up to 10 years of assessment) — the figures both
    // payroll engines use for PAYE / UIF / SDL / medical credits.
    Route::get('/tax-tables', [\App\Modules\HR\Payroll\Controllers\TaxTableController::class, 'payroll'])->name('tax-tables');

    // Scheduled Reports
    Route::get('/settings/scheduled-reports', [PayrollScheduledReportController::class, 'index'])->name('settings.scheduled-reports.index');
    Route::post('/settings/scheduled-reports', [PayrollScheduledReportController::class, 'store'])->name('settings.scheduled-reports.store');
    Route::patch('/settings/scheduled-reports/{scheduledReport}', [PayrollScheduledReportController::class, 'update'])->name('settings.scheduled-reports.update');
    Route::delete('/settings/scheduled-reports/{scheduledReport}', [PayrollScheduledReportController::class, 'destroy'])->name('settings.scheduled-reports.destroy');

    // Old payslip routes (backward compat)
    Route::get('/my-payslips', [PayslipController::class, 'mine'])->name('my-payslips');

    // Bank salary file (normal single-company payroll)
    Route::get('/bank-settings',  [\App\Modules\HR\Payroll\Controllers\PayrollBankController::class, 'settings'])->name('bank-settings');
    Route::post('/bank-settings', [\App\Modules\HR\Payroll\Controllers\PayrollBankController::class, 'saveSettings'])->name('bank-settings.save');
    Route::get('/runs/{run:uuid}/bank-file', [\App\Modules\HR\Payroll\Controllers\PayrollBankController::class, 'file'])->name('runs.bank-file');
    Route::post('/my-payslips/key', [PayslipController::class, 'generateKey'])->name('my-payslips.key');
    Route::get('/{run}/payslips/{payslip}', [PayslipController::class, 'show'])->name('payslips.show');
    Route::post('/{run}/payslips', [PayslipController::class, 'store'])->name('payslips.store');
    Route::get('/{run}/payslips/{payslip}/download', [PayslipController::class, 'download'])->name('payslips.download');
    Route::post('/{run}/payslips/{payslip}/lines', [PayslipController::class, 'addLine'])->name('payslips.lines.store');
    Route::delete('/{run}/payslips/{payslip}/lines/{line}', [PayslipController::class, 'removeLine'])->name('payslips.lines.destroy');

    // AI Payslip Explanation (Phase 8)
    Route::get('/{run}/payslips/{payslip}/explain', [PayrollPayslipAIController::class, 'explain'])->name('payslips.ai.explain');
    Route::post('/{run}/payslips/{payslip}/ask', [PayrollPayslipAIController::class, 'ask'])->name('payslips.ai.ask');
});

// ── Payroll Bureau (multi-company agent) — only when the bureau module is active ──
use App\Modules\HR\Payroll\Controllers\Bureau\BureauCompanyController;
use App\Modules\HR\Payroll\Controllers\Bureau\BureauEmployeeController;
use App\Modules\HR\Payroll\Controllers\Bureau\BureauManagerController;
use App\Modules\HR\Payroll\Controllers\Bureau\BureauComponentController;
use App\Modules\HR\Payroll\Controllers\Bureau\BureauApprovalStepController;
use App\Modules\HR\Payroll\Controllers\Bureau\BureauAuditController;
use App\Modules\HR\Payroll\Controllers\Bureau\BureauPayRunController;

Route::middleware(['web', 'auth', 'module.active:payroll_bureau'])->prefix('payroll/bureau')->name('payroll.bureau.')->group(function () {
    Route::get('/',                         [BureauCompanyController::class, 'index'])->name('dashboard');

    // SARS tax tables viewer (up to 10 years) — the figures the bureau tax engine uses.
    Route::get('/tax-tables',               [\App\Modules\HR\Payroll\Controllers\TaxTableController::class, 'bureau'])->name('tax-tables');
    Route::post('/companies',               [BureauCompanyController::class, 'store'])->name('companies.store');
    Route::get('/companies/{company:uuid}', [BureauCompanyController::class, 'show'])->name('companies.show');
    Route::put('/companies/{company:uuid}', [BureauCompanyController::class, 'update'])->name('companies.update');

    // Who signs a client's payroll, and in what order. The tenant default covers every client that
    // has not asked for something particular, which is most of them.
    Route::get('/approval-steps',                            [BureauApprovalStepController::class, 'index'])->name('approval-steps.index');
    Route::post('/approval-steps',                           [BureauApprovalStepController::class, 'store'])->name('approval-steps.store');
    Route::post('/approval-steps/defaults',                  [BureauApprovalStepController::class, 'installDefaults'])->name('approval-steps.defaults');
    Route::get('/companies/{company:uuid}/approval-steps',   [BureauApprovalStepController::class, 'index'])->name('companies.approval-steps');
    Route::post('/companies/{company:uuid}/approval-steps',  [BureauApprovalStepController::class, 'store'])->name('companies.approval-steps.store');
    Route::post('/companies/{company:uuid}/approval-steps/defaults', [BureauApprovalStepController::class, 'installDefaults'])->name('companies.approval-steps.defaults');
    Route::put('/approval-steps/{step:uuid}',                [BureauApprovalStepController::class, 'update'])->name('approval-steps.update');
    Route::delete('/approval-steps/{step:uuid}',             [BureauApprovalStepController::class, 'destroy'])->name('approval-steps.destroy');

    // Everything done to one client's payroll, and by whom. Asked from outside — by a client's
    // auditor, or by a client disputing a payment — so it is one screen per client.
    Route::get('/companies/{company:uuid}/audit', [BureauAuditController::class, 'index'])->name('audit.index');

    // Employees
    // Who approves whose leave, changed for a whole team at once — it almost never changes for
    // one person alone, and doing it through the employee form leaves it half done.
    Route::get('/companies/{company:uuid}/managers',          [BureauManagerController::class, 'index'])->name('managers.index');
    Route::post('/companies/{company:uuid}/managers/reassign', [BureauManagerController::class, 'reassign'])->name('managers.reassign');

    Route::get('/companies/{company:uuid}/employees',                       [BureauEmployeeController::class, 'index'])->name('employees.index');
    Route::post('/companies/{company:uuid}/employees',                      [BureauEmployeeController::class, 'store'])->name('employees.store');
    Route::get('/companies/{company:uuid}/employees/template',              [BureauEmployeeController::class, 'template'])->name('employees.template');
    Route::post('/companies/{company:uuid}/employees/import',               [BureauEmployeeController::class, 'import'])->name('employees.import');
    Route::put('/companies/{company:uuid}/employees/{employee:uuid}',       [BureauEmployeeController::class, 'update'])->name('employees.update');
    Route::post('/companies/{company:uuid}/employees/{employee:uuid}/key',  [BureauEmployeeController::class, 'generateKey'])->name('employees.key');
    Route::post('/companies/{company:uuid}/employees/{employee:uuid}/terminate', [BureauEmployeeController::class, 'terminate'])->name('employees.terminate');
    Route::post('/companies/{company:uuid}/employees/{employee:uuid}/portal-link', [BureauEmployeeController::class, 'portalLink'])->name('employees.portal-link');
    // Component catalogue
    Route::post('/companies/{company:uuid}/components',                                 [BureauComponentController::class, 'store'])->name('components.store');
    Route::post('/companies/{company:uuid}/components/apply-standard',                  [BureauComponentController::class, 'applyStandard'])->name('components.apply-standard');
    Route::post('/companies/{company:uuid}/employees/{employee:uuid}/components',       [BureauComponentController::class, 'assign'])->name('components.assign');

    // Pay runs
    Route::get('/companies/{company:uuid}/runs',                              [BureauPayRunController::class, 'index'])->name('runs.index');
    Route::post('/companies/{company:uuid}/runs',                             [BureauPayRunController::class, 'store'])->name('runs.store');
    Route::get('/companies/{company:uuid}/runs/{run:uuid}',                   [BureauPayRunController::class, 'show'])->name('runs.show');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/inputs',           [BureauPayRunController::class, 'addInput'])->name('runs.inputs.add');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/process',          [BureauPayRunController::class, 'process'])->name('runs.process');
    // The lifecycle: calculated → approved → finalised → paid → completed. Approval is its own act
    // because it used to be implicit in finalising, so whoever calculated a run could also release
    // the money with no name recorded against the decision.
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/approve',          [BureauPayRunController::class, 'approve'])->name('runs.approve');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/reject',           [BureauPayRunController::class, 'reject'])->name('runs.reject');
    // Who this run pays. A regular run is seeded with everybody on its cycle; every other kind
    // starts empty, because a bonus run for three people must not sweep in the whole payroll.
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/employees',        [BureauPayRunController::class, 'addEmployees'])->name('runs.employees.add');
    Route::delete('/companies/{company:uuid}/runs/{run:uuid}/employees/{employee:uuid}', [BureauPayRunController::class, 'removeEmployee'])->name('runs.employees.remove');
    // Overtime as hours, for one employee or many. There is no attendance to read on the bureau
    // side, so the officer captures hours and the engine prices them per employee.
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/overtime', [BureauPayRunController::class, 'addOvertime'])->name('runs.overtime');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/finalise',         [BureauPayRunController::class, 'finalise'])->name('runs.finalise');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/paid',             [BureauPayRunController::class, 'markPaid'])->name('runs.paid');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/complete',         [BureauPayRunController::class, 'complete'])->name('runs.complete');
    // The authorised amendment a completed payroll needs before it can be changed.
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/reopen',           [BureauPayRunController::class, 'reopen'])->name('runs.reopen');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/email',            [BureauPayRunController::class, 'email'])->name('runs.email');
    Route::get('/companies/{company:uuid}/runs/{run:uuid}/payslips/{payslip:uuid}/download', [BureauPayRunController::class, 'downloadPayslip'])->name('runs.payslip.download');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/bank-file',          [BureauPayRunController::class, 'generateBankFile'])->name('runs.bankfile.generate');
    Route::get('/companies/{company:uuid}/runs/{run:uuid}/bank-file/download',   [BureauPayRunController::class, 'downloadBankFile'])->name('runs.bankfile.download');
    Route::get('/companies/{company:uuid}/runs/{run:uuid}/emp201',               [BureauPayRunController::class, 'emp201Csv'])->name('runs.emp201');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/emp201/file',          [BureauPayRunController::class, 'fileEmp201'])->name('runs.emp201.file');
    Route::get('/companies/{company:uuid}/runs/{run:uuid}/gl-journal',           [BureauPayRunController::class, 'glJournal'])->name('runs.gl');
    Route::get('/companies/{company:uuid}/irp5',                                 [BureauPayRunController::class, 'irp5Csv'])->name('irp5');
    Route::get('/companies/{company:uuid}/easyfile',                             [BureauPayRunController::class, 'easyFile'])->name('easyfile');

    // Real-time salary payouts (credential-driven provider integration)
    Route::get('/companies/{company:uuid}/payouts',                              [\App\Modules\HR\Payroll\Controllers\Bureau\BureauPayoutController::class, 'index'])->name('payouts.index');
    Route::post('/companies/{company:uuid}/payouts/connect',                     [\App\Modules\HR\Payroll\Controllers\Bureau\BureauPayoutController::class, 'connect'])->name('payouts.connect');
    Route::post('/companies/{company:uuid}/payouts/{integration:uuid}/toggle',   [\App\Modules\HR\Payroll\Controllers\Bureau\BureauPayoutController::class, 'toggle'])->name('payouts.toggle');
    Route::post('/companies/{company:uuid}/payouts/{integration:uuid}/test',     [\App\Modules\HR\Payroll\Controllers\Bureau\BureauPayoutController::class, 'test'])->name('payouts.test');
    Route::post('/companies/{company:uuid}/runs/{run:uuid}/disburse',            [\App\Modules\HR\Payroll\Controllers\Bureau\BureauPayoutController::class, 'submit'])->name('payouts.disburse');
    Route::post('/companies/{company:uuid}/disbursements/{disbursement:uuid}/refresh', [\App\Modules\HR\Payroll\Controllers\Bureau\BureauPayoutController::class, 'refresh'])->name('payouts.refresh');
});

// ── Bureau employee self-service payslip portal ───────────────────────────────
// Moved to routes/modules/payroll-payslip-portal.php, which is required outside the
// authenticated group in web.php. Declared here it inherited `auth` from the enclosing
// group, so every employee who followed their link was sent to a login page they have
// no account for — the portal exists precisely because they have no account.
