<?php

use App\Http\Controllers\ModuleHubController;
use App\Modules\Operations\Sheq\Http\Controllers\FileController;
use App\Modules\Operations\Sheq\Http\Controllers\PpeCatalogueController;
use App\Modules\Operations\Sheq\Http\Controllers\AiAssistantController;
use App\Modules\Operations\Sheq\Http\Controllers\InsightsController;
use App\Modules\Operations\Sheq\Http\Controllers\InspectionController;
use App\Modules\Operations\Sheq\Http\Controllers\InvestigationController;
use App\Modules\Operations\Sheq\Http\Controllers\LegalRegisterController;
use App\Modules\Operations\Sheq\Http\Controllers\QualityController;
use App\Modules\Operations\Sheq\Http\Controllers\RegisterEntryController;
use App\Modules\Operations\Sheq\Http\Controllers\MyComplianceController;
use App\Modules\Operations\Sheq\Http\Controllers\PassScanController;
use App\Modules\Operations\Sheq\Http\Controllers\PermitController;
use App\Modules\Operations\Sheq\Http\Controllers\AccessOverrideController;
use App\Modules\Operations\Sheq\Http\Controllers\ActivityController;
use App\Modules\Operations\Sheq\Http\Controllers\AttendanceController;
use App\Modules\Operations\Sheq\Http\Controllers\ChangeController;
use App\Modules\Operations\Sheq\Http\Controllers\ComplianceController;
use App\Modules\Operations\Sheq\Http\Controllers\ContractorController;
use App\Modules\Operations\Sheq\Http\Controllers\ContractorHistoryController;
use App\Modules\Operations\Sheq\Http\Controllers\ContractorPerformanceController;
use App\Modules\Operations\Sheq\Http\Controllers\ContractorNoticeController;
use App\Modules\Operations\Sheq\Http\Controllers\DocumentLibraryController;
use App\Modules\Operations\Sheq\Http\Controllers\DocumentVersionController;
use App\Modules\Operations\Sheq\Http\Controllers\EnvironmentalRegisterController;
use App\Modules\Operations\Sheq\Http\Controllers\ExposureController;
use App\Modules\Operations\Sheq\Http\Controllers\ExposureRegisterController;
use App\Modules\Operations\Sheq\Http\Controllers\RegistersController;
use App\Modules\Operations\Sheq\Http\Controllers\RiskAssessmentController;
use App\Modules\Operations\Sheq\Http\Controllers\CommandCentreController;
use App\Modules\Operations\Sheq\Http\Controllers\EmergencyCommandController;
use App\Modules\Operations\Sheq\Http\Controllers\RiskIntelligenceController;
use App\Modules\Operations\Sheq\Http\Controllers\SheqSettingsController;
use App\Modules\Operations\Sheq\Http\Controllers\StatutoryFormController;
use App\Modules\Operations\Sheq\Http\Controllers\SubcontractorController;
use App\Modules\Operations\Sheq\Http\Controllers\SyncConflictController;
use App\Modules\Operations\Sheq\Http\Controllers\AccessPassController;
use App\Modules\Operations\Sheq\Http\Controllers\ActionController;
use App\Modules\Operations\Sheq\Http\Controllers\HazardController;
use App\Modules\Operations\Sheq\Http\Controllers\ApprovalController;
use App\Modules\Operations\Sheq\Http\Controllers\AuditController;
use App\Modules\Operations\Sheq\Http\Controllers\IncidentController;
use App\Modules\Operations\Sheq\Http\Controllers\InjuryCaseController;
use App\Modules\Operations\Sheq\Http\Controllers\SafetyAlertController;
use App\Modules\Operations\Sheq\Http\Controllers\TriageController;
use App\Modules\Operations\Sheq\Http\Controllers\InductionController;
use App\Modules\Operations\Sheq\Http\Controllers\IntegrationsController;
use App\Modules\Operations\Sheq\Http\Controllers\ObservationController;
use App\Modules\Operations\Sheq\Http\Controllers\OperationalRegistersController;
use App\Modules\Operations\Sheq\Http\Controllers\RegulatoryPackController;
use App\Modules\Operations\Sheq\Http\Controllers\SheqDashboardController;
use App\Modules\Operations\Sheq\Http\Controllers\StatutoryObligationController;
use App\Modules\Operations\Sheq\Http\Controllers\ToolboxTalkController;
use Illuminate\Support\Facades\Route;

/*
 * SHEQ — Safety, Health, Environment and Quality (Operations & Support).
 *
 * Gated by module entitlement (module.active) and hub tier (module.tier); tenant data is isolated
 * by row-level security, so an id belonging to another company does not resolve here at all.
 *
 * Routes bind on the public UUID, never the internal id (SHEQ-ADR-001).
 */
Route::middleware(['module.active:sheq', 'module.tier:sheq'])
    ->prefix('sheq')->name('sheq.')
    ->group(function () {
        Route::get('/', [SheqDashboardController::class, 'index'])->name('dashboard');

        // ── Hub landings — role-filtered feature cards ────────────────────────────────────────
        // Every hub declared in SheqHubs needs a landing route; the hub test suite fails the
        // build if one is missing, which is how the four new hubs were caught before shipping.
        foreach ([
            'safety-hub' => 'safety',
            'risk-hub' => 'risk',
            'assurance-hub' => 'assurance',
            'insights-hub' => 'insights',
            'compliance-hub' => 'compliance',
            'configure-hub' => 'configure',
        ] as $path => $group) {
            Route::get("/{$path}", [ModuleHubController::class, 'show'])
                ->defaults('module', 'sheq')
                ->defaults('group', $group)
                ->name('hub.' . $group);
        }

        // ── Incidents ─────────────────────────────────────────────────────────────────────────
        // `create` is declared before `{uuid}` so the literal segment is not swallowed by the
        // wildcard — otherwise "create" resolves as an incident uuid and 404s.
        Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
        Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
        Route::get('/incidents/{uuid}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::post('/incidents/{uuid}/triage', [IncidentController::class, 'triage'])->name('incidents.triage');

        // ── The queue of incidents waiting for somebody to pick them up ──────────────────────
        Route::get('/triage', [TriageController::class, 'index'])->name('triage.index');

        // ── Safety alerts: carrying a finding to the people exposed to the same hazard ───────
        Route::get('/safety-alerts', [SafetyAlertController::class, 'index'])->name('safety-alerts.index');
        Route::post('/safety-alerts/from-incident/{incidentUuid}', [SafetyAlertController::class, 'draft'])
            ->name('safety-alerts.draft');
        Route::patch('/safety-alerts/{uuid}', [SafetyAlertController::class, 'update'])->name('safety-alerts.update');
        Route::post('/safety-alerts/{uuid}/issue', [SafetyAlertController::class, 'issue'])->name('safety-alerts.issue');
        Route::post('/safety-alerts/{uuid}/read', [SafetyAlertController::class, 'read'])->name('safety-alerts.read');

        // ── Injury cases: who is off work, who is on light duty, and who is back ─────────────
        Route::get('/injury-cases', [InjuryCaseController::class, 'index'])->name('injury-cases.index');
        Route::get('/injury-cases/{uuid}', [InjuryCaseController::class, 'show'])->name('injury-cases.show');
        Route::post('/injury-cases/from-incident/{incidentUuid}', [InjuryCaseController::class, 'store'])
            ->name('injury-cases.store');
        Route::post('/injury-cases/{uuid}/record', [InjuryCaseController::class, 'record'])->name('injury-cases.record');
        Route::post('/injury-cases/{uuid}/close', [InjuryCaseController::class, 'close'])->name('injury-cases.close');
        Route::post('/injury-cases/{uuid}/claim', [InjuryCaseController::class, 'claim'])->name('injury-cases.claim');

        // ── Hazards (§5.2) ────────────────────────────────────────────────────────────────────
        Route::get('/hazards', [HazardController::class, 'index'])->name('hazards.index');
        Route::get('/hazards/create', [HazardController::class, 'create'])->name('hazards.create');
        Route::post('/hazards', [HazardController::class, 'store'])->name('hazards.store');
        Route::post('/hazards/{uuid}/triage', [HazardController::class, 'triage'])->name('hazards.triage');

        // ── Actions — the single CAPA list (§5.5) ──────────────────────────────────────────────
        Route::get('/actions', [ActionController::class, 'index'])->name('actions.index');

        /*
         * Where I stand, for me.
         *
         * `sheq:reminders:send` emails somebody that their certificate lapses in thirty days, and
         * that email was the only place the fact existed: gone once deleted, uncheckable between
         * sends, and useless to anybody who was on leave for it. The register that holds the same
         * answer needs `sheq.competency.manage`, which the person themselves does not have.
         *
         * Gated on nothing but being signed in. It is their own record; the entitlements exist to
         * stop them reading other people's.
         */
        Route::get('/my-compliance', [MyComplianceController::class, 'index'])->name('my-compliance');
        Route::post('/actions/{uuid}/complete', [ActionController::class, 'complete'])->name('actions.complete');
        Route::post('/actions/{uuid}/verify', [ActionController::class, 'verify'])->name('actions.verify');
        Route::post('/actions/{uuid}/extend', [ActionController::class, 'extend'])->name('actions.extend');
        Route::post('/actions/{uuid}/effectiveness', [ActionController::class, 'reviewEffectiveness'])
            ->name('actions.effectiveness');

        // ── Investigations (SHEQ-INC-7..10) ───────────────────────────────────────────────────
        Route::get('/investigations', [InvestigationController::class, 'index'])->name('investigations');
        Route::post('/investigations', [InvestigationController::class, 'open'])
            ->name('investigations.open');
        Route::get('/investigations/{uuid}', [InvestigationController::class, 'show'])
            ->whereUuid('uuid')->name('investigations.show');
        Route::post('/investigations/{uuid}', [InvestigationController::class, 'update'])
            ->whereUuid('uuid')->name('investigations.update');
        Route::post('/investigations/{uuid}/causes', [InvestigationController::class, 'addCause'])
            ->whereUuid('uuid')->name('investigations.causes');
        Route::post('/investigations/{uuid}/submit', [InvestigationController::class, 'submit'])
            ->whereUuid('uuid')->name('investigations.submit');
        Route::post('/investigations/{uuid}/approve', [InvestigationController::class, 'approve'])
            ->whereUuid('uuid')->name('investigations.approve');
        Route::post('/investigations/{incidentUuid}/close', [InvestigationController::class, 'closeIncident'])
            ->whereUuid('incidentUuid')->name('investigations.close');
        Route::post('/investigations/{incidentUuid}/reopen', [InvestigationController::class, 'reopen'])
            ->whereUuid('incidentUuid')->name('investigations.reopen');

        // ── Statutory obligations (SHEQ-REG-5) ────────────────────────────────────────────────
        Route::get('/obligations', [StatutoryObligationController::class, 'index'])->name('obligations.index');
        Route::post('/obligations/{uuid}/submit', [StatutoryObligationController::class, 'submit'])
            ->name('obligations.submit');

        // ── Files: evidence, certificates and procedures (SHEQ-OFF-7, SHEQ-SEC-4) ─────────────
        Route::post('/files', [FileController::class, 'store'])->name('files.store');
        Route::get('/files/{uuid}/download', [FileController::class, 'download'])
            ->whereUuid('uuid')->name('files.download');
        Route::get('/files/{uuid}/view', [FileController::class, 'view'])
            ->whereUuid('uuid')->name('files.view');

        // ── Registers: the work that had backends but no screens (§5.3–§5.9) ──────────────────
        Route::get('/risk-assessments', [RegistersController::class, 'riskAssessments'])->name('risk-assessments');

        /*
         * One assessment: its lines, their controls, and the signature it needs.
         *
         * All of this lived in RiskAssessmentService with nothing calling it — an assessment could
         * be created and then nothing could be done with it, which also blocked every permit that
         * referenced it.
         */
        Route::get('/risk-assessments/{uuid}', [RiskAssessmentController::class, 'show'])
            ->name('risk-assessments.show');
        Route::post('/risk-assessments/{uuid}/lines', [RiskAssessmentController::class, 'addLine'])
            ->name('risk-assessments.lines.store');
        Route::post('/risk-assessments/{uuid}/participants', [RiskAssessmentController::class, 'addParticipant'])
            ->name('risk-assessments.participants.store');
        Route::post('/risk-assessments/{uuid}/approve', [RiskAssessmentController::class, 'approve'])
            ->name('risk-assessments.approve');
        Route::post('/risk-assessments/{uuid}/revise', [RiskAssessmentController::class, 'revise'])
            ->name('risk-assessments.revise');
        Route::get('/permits', [RegistersController::class, 'permits'])->name('permits');

        /*
         * One permit, and the decisions that move it.
         *
         * The whole lifecycle lived in PermitService with nothing calling it: a permit could be
         * requested and then nothing could be done with it — no issuer could see what was blocking
         * it, nobody could issue or close one.
         */
        /*
         * The live board (SHEQ-PTW-6). Before the {uuid} route, or "board" is read as a permit id.
         *
         * `activeNow()` existed for this and nothing called it. The register answers an
         * administrator's question — every permit ever raised — and a control room asking what work
         * is live right now had to find four permits among two hundred closed ones.
         */
        Route::get('/permits/board', [PermitController::class, 'board'])->name('permits.board');
        Route::get('/permits/{uuid}', [PermitController::class, 'show'])->name('permits.show');
        Route::post('/permits/{uuid}/issue', [PermitController::class, 'issue'])->name('permits.issue');
        Route::post('/permits/{uuid}/approve', [PermitController::class, 'approve'])->name('permits.approve');
        Route::post('/permits/{uuid}/suspend', [PermitController::class, 'suspend'])->name('permits.suspend');
        Route::post('/permits/{uuid}/reinstate', [PermitController::class, 'reinstate'])->name('permits.reinstate');
        Route::post('/permits/{uuid}/close', [PermitController::class, 'close'])->name('permits.close');
        Route::post('/permits/{uuid}/override-competency', [PermitController::class, 'overrideCompetency'])
            ->name('permits.override-competency');
        Route::get('/inspections', [RegistersController::class, 'inspections'])->name('inspections');
        Route::get('/inspections/templates', [InspectionController::class, 'templates'])
            ->name('inspections.templates');
        Route::post('/inspections/templates', [InspectionController::class, 'storeTemplate'])
            ->name('inspections.templates.store');
        Route::post('/inspections/schedules', [InspectionController::class, 'storeSchedule'])
            ->name('inspections.schedules.store');
        Route::get('/inspections/{uuid}/perform', [InspectionController::class, 'perform'])
            ->whereUuid('uuid')->name('inspections.perform');
        Route::post('/inspections/{uuid}/complete', [InspectionController::class, 'complete'])
            ->whereUuid('uuid')->name('inspections.complete');
        Route::get('/competency', [RegistersController::class, 'competencies'])->name('competency');
        /*
         * The matrix: who is qualified for what, as a grid.
         *
         * `CompetencyService::matrix()` computed it and nothing called it, so the register could
         * list records and could not answer the question the matrix exists for — where are we
         * short. Before the catch-all competency routes, and a distinct path from them.
         */
        Route::get('/competency/matrix', [RegistersController::class, 'competencyMatrix'])
            ->name('competency.matrix');
        Route::get('/documents', [RegistersController::class, 'documents'])->name('documents');
        Route::get('/contractors', [RegistersController::class, 'contractors'])->name('contractors');
        Route::get('/sites', [RegistersController::class, 'sites'])->name('sites');
        /*
         * Sites the tenant types, alongside the ones mirrored from the company structure.
         *
         * A rented depot, a client's premises, a stretch of road under contract — places where work
         * happens that are not lines on an HR org chart. Every write here needs sheq.configure; the
         * controller enforces it per action, matching the rest of the module.
         */
        Route::post('/sites', [RegistersController::class, 'storeSite'])->name('sites.store');
        Route::patch('/sites/{uuid}', [RegistersController::class, 'updateSite'])
            ->whereUuid('uuid')->name('sites.update');
        /*
         * Separate from the site form: a site mirrored from the company structure cannot be
         * renamed here but may still set its own contractor bar.
         */
        Route::post('/sites/{uuid}/requirements', [RegistersController::class, 'updateSiteRequirements'])
            ->name('sites.requirements');
        Route::post('/sites/{uuid}/toggle', [RegistersController::class, 'toggleSite'])
            ->whereUuid('uuid')->name('sites.toggle');

        /*
         * The activity catalogue (CON-15).
         *
         * A register of its own rather than a settings card: access has to answer contractor +
         * worker + site + activity + time, and the activity half is only answerable once
         * somebody has written down what each kind of work takes.
         */
        /*
         * Who is on site (CON-16). Read by anybody with module access, because during an
         * evacuation the person who opens it is whoever is nearest a screen; writing to it
         * needs sheq.contractor.manage, enforced in the controller.
         */
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance');
        Route::post('/attendance/{direction}', [AttendanceController::class, 'record'])
            ->whereIn('direction', ['entry', 'exit'])->name('attendance.record');

        /*
         * The register of temporary access (CON-17). Visible to anybody with module access,
         * because an override's safety comes from being seen by people who did not grant it;
         * granting and revoking need sheq.contractor.manage.
         */
        /*
         * What gets told to contractors, and what went out (CON-19). Readable by anybody with
         * module access — a notification nobody can see the delivery of is one nobody can
         * trust — while changing the channels needs sheq.configure.
         */
        Route::get('/contractor-notices', [ContractorNoticeController::class, 'index'])
            ->name('contractor-notices');
        Route::post('/contractor-notices/channels',
            [ContractorNoticeController::class, 'updateChannels'])
            ->name('contractor-notices.channels');

        /*
         * The central compliance decision, asked directly (COM-1). A page rather than only an
         * API, because the people who need the answer — a safety officer, a site manager, a
         * guard's supervisor — are the ones with no way to call one.
         */
        Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance');

        /*
         * One contractor's whole story (CON-22) and why the gate says what it says (COM-5).
         * GET only: a history a screen can write to is not a history.
         */
        Route::get('/contractor-history', [ContractorHistoryController::class, 'index'])
            ->name('contractor-history');

        /* Every code scanned at a gate, including the ones that matched nobody (CON-23). */
        Route::get('/pass-scans', [PassScanController::class, 'index'])->name('pass-scans');

        /*
         * Every version of every contractor document, and what was on file on a given day
         * (CON-24). GET only: a version chain a screen can rewrite records nothing.
         */
        Route::get('/document-versions', [DocumentVersionController::class, 'index'])
            ->name('document-versions');

        /*
         * What people were seen doing (OBS-1 to OBS-4). Recording is gated on reporting a
         * hazard, not on managing the register: restricting the one register that depends on
         * volume to safety staff is how it ends up with four contributors.
         */
        Route::get('/observations', [ObservationController::class, 'index'])->name('observations');
        Route::post('/observations', [ObservationController::class, 'store'])
            ->name('observations.store');
        Route::post('/observations/behaviours/install',
            [ObservationController::class, 'installBehaviours'])
            ->name('observations.behaviours.install');
        Route::post('/observations/{uuid}/action', [ObservationController::class, 'raiseAction'])
            ->whereUuid('uuid')->name('observations.action');
        Route::post('/observations/{uuid}/close', [ObservationController::class, 'close'])
            ->whereUuid('uuid')->name('observations.close');

        /*
         * Management of change (MOC-1 to MOC-5). Registering one is gated on reporting a hazard
         * — anybody who can see a change coming should be able to raise it — while implementing
         * and reviewing need `sheq.configure`, because those are the two decisions that let the
         * change actually happen.
         */
        Route::get('/changes', [ChangeController::class, 'index'])->name('changes');
        Route::post('/changes', [ChangeController::class, 'store'])->name('changes.store');
        Route::post('/changes/{uuid}/submit', [ChangeController::class, 'submit'])
            ->whereUuid('uuid')->name('changes.submit');
        Route::post('/changes/{uuid}/implement', [ChangeController::class, 'implement'])
            ->whereUuid('uuid')->name('changes.implement');
        Route::post('/changes/{uuid}/review', [ChangeController::class, 'review'])
            ->whereUuid('uuid')->name('changes.review');
        Route::post('/changes/{uuid}/reject', [ChangeController::class, 'reject'])
            ->whereUuid('uuid')->name('changes.reject');
        Route::post('/changes/{uuid}/impacts', [ChangeController::class, 'addImpact'])
            ->whereUuid('uuid')->name('changes.impacts.add');
        Route::post('/changes/impacts/{impactUuid}', [ChangeController::class, 'decideImpact'])
            ->whereUuid('impactUuid')->name('changes.impacts.decide');

        /*
         * Quality (QLT-1 to QLT-8). Raising an NCR is gated on hazard reporting — anybody who
         * can see defective work should be able to raise it — while verification needs
         * `sheq.action.verify`, which is the entitlement that already means "confirm somebody
         * else's work is done".
         */
        Route::get('/quality', [QualityController::class, 'index'])->name('quality');
        Route::post('/quality/requirements', [QualityController::class, 'storeRequirement'])
            ->name('quality.requirements.store');
        Route::post('/quality/ncrs', [QualityController::class, 'storeNcr'])
            ->name('quality.ncrs.store');
        Route::post('/quality/ncrs/{uuid}/action', [QualityController::class, 'raiseAction'])
            ->whereUuid('uuid')->name('quality.ncrs.action');
        Route::post('/quality/ncrs/{uuid}/investigate', [QualityController::class, 'investigate'])
            ->whereUuid('uuid')->name('quality.ncrs.investigate');
        Route::post('/quality/ncrs/{uuid}/correction',
            [QualityController::class, 'recordCorrection'])
            ->whereUuid('uuid')->name('quality.ncrs.correction');
        Route::post('/quality/ncrs/{uuid}/verify', [QualityController::class, 'verify'])
            ->whereUuid('uuid')->name('quality.ncrs.verify');
        Route::post('/quality/ncrs/{uuid}/void', [QualityController::class, 'voidNcr'])
            ->whereUuid('uuid')->name('quality.ncrs.void');
        Route::post('/quality/rework', [QualityController::class, 'storeRework'])
            ->name('quality.rework.store');
        Route::post('/quality/rework/{uuid}/verify', [QualityController::class, 'verifyRework'])
            ->whereUuid('uuid')->name('quality.rework.verify');

        /*
         * Environmental registers (ENV-1 to ENV-7). Recording a spill or waste is gated on
         * hazard reporting — the person who finds a spill is whoever was standing there — while
         * aspects and meter limits need `sheq.configure`.
         */
        /*
         * A separate path from the consumption-monitoring screen that already lived at
         * `/environmental`. Two pages rather than one because they are read by different people
         * for different reasons — a sustainability report is compiled from meter totals, and a
         * reportable spill is cleared by whoever is on site this afternoon — and because
         * collapsing them would have meant rewriting a working screen to make room.
         */
        Route::get('/environmental-registers',
            [EnvironmentalRegisterController::class, 'index'])->name('environmental-registers');
        Route::post('/environmental-registers/aspects',
            [EnvironmentalRegisterController::class, 'storeAspect'])
            ->name('environmental-registers.aspects.store');
        Route::post('/environmental-registers/waste', [EnvironmentalRegisterController::class, 'storeWaste'])
            ->name('environmental-registers.waste.store');
        Route::post('/environmental-registers/waste/{uuid}/manifest',
            [EnvironmentalRegisterController::class, 'returnManifest'])
            ->whereUuid('uuid')->name('environmental-registers.waste.manifest');
        Route::post('/environmental-registers/spills', [EnvironmentalRegisterController::class, 'storeSpill'])
            ->name('environmental-registers.spills.store');
        Route::post('/environmental-registers/spills/{uuid}/notify',
            [EnvironmentalRegisterController::class, 'notifySpill'])
            ->whereUuid('uuid')->name('environmental-registers.spills.notify');
        Route::post('/environmental-registers/spills/{uuid}',
            [EnvironmentalRegisterController::class, 'updateSpill'])
            ->whereUuid('uuid')->name('environmental-registers.spills.update');
        Route::post('/environmental-registers/meters/{uuid}/threshold',
            [EnvironmentalRegisterController::class, 'updateMeterThreshold'])
            ->whereUuid('uuid')->name('environmental-registers.meters.threshold');
        Route::post('/environmental-registers/readings',
            [EnvironmentalRegisterController::class, 'storeReading'])
            ->name('environmental-registers.readings.store');

        /*
         * Occupational health and hygiene (OH-1 to OH-6).
         *
         * A separate path from `/exposure`, which records exposure HOURS — the denominator for
         * an injury rate. This records what people were exposed to while working them, which is
         * a different register answering a different question: an injury is an event and an
         * exposure is a dose.
         */
        Route::get('/occupational-health', [ExposureRegisterController::class, 'index'])
            ->name('occupational-health');
        Route::post('/occupational-health/agents/install',
            [ExposureRegisterController::class, 'installAgents'])
            ->name('occupational-health.agents.install');
        Route::post('/occupational-health/agents/{uuid}',
            [ExposureRegisterController::class, 'updateAgent'])
            ->whereUuid('uuid')->name('occupational-health.agents.update');
        Route::post('/occupational-health/agents/{uuid}/ppe',
            [ExposureRegisterController::class, 'linkPpe'])
            ->whereUuid('uuid')->name('occupational-health.agents.ppe');
        Route::post('/occupational-health/results',
            [ExposureRegisterController::class, 'storeResult'])
            ->name('occupational-health.results.store');
        Route::post('/occupational-health/groups',
            [ExposureRegisterController::class, 'storeGroup'])
            ->name('occupational-health.groups.store');
        Route::post('/occupational-health/groups/{uuid}/members',
            [ExposureRegisterController::class, 'addMember'])
            ->whereUuid('uuid')->name('occupational-health.groups.members');

        /*
         * Risk intelligence (RISK-1 to RISK-5). Reading is open to anybody with module access;
         * verifying a control and linking it to what enforces it need the assessment author
         * entitlement, because both change what an approved assessment asserts.
         */
        Route::get('/risk-intelligence', [RiskIntelligenceController::class, 'index'])
            ->name('risk-intelligence');
        Route::post('/risk-intelligence/controls/{uuid}/verify',
            [RiskIntelligenceController::class, 'verifyControl'])
            ->whereUuid('uuid')->name('risk-intelligence.controls.verify');
        Route::post('/risk-intelligence/controls/{uuid}/links',
            [RiskIntelligenceController::class, 'linkControl'])
            ->whereUuid('uuid')->name('risk-intelligence.controls.links');

        /*
         * The live SHEQ command centre (CMD-1 to CMD-8).
         *
         * Reading is open to anybody with module access — the whole point of a command centre is
         * that a supervisor can see what is happening without asking somebody to run a report.
         * Reweighting the site risk score and forcing a recompute need `sheq.configure`, because
         * the weights decide which sites get management attention.
         */
        Route::get('/command-centre', [CommandCentreController::class, 'index'])
            ->name('command-centre');
        Route::get('/command-centre/drill', [CommandCentreController::class, 'drill'])
            ->name('command-centre.drill');
        Route::post('/command-centre/recompute', [CommandCentreController::class, 'recompute'])
            ->name('command-centre.recompute');
        Route::post('/command-centre/weights', [CommandCentreController::class, 'saveWeights'])
            ->name('command-centre.weights');

        /*
         * The emergency command centre (EMR-1 to EMR-6).
         *
         * Declaring, and accounting for people, take the incident-reporting entitlement rather
         * than a configuration one: the people who hold `sheq.configure` are in an office, and
         * the person who presses this button is a guard or a supervisor at a gate.
         *
         * Refreshing the roll call needs nothing beyond module access. It only ever adds people
         * who are already on site and cannot alter a judgement, and an emergency is the worst
         * possible moment to discover that the person holding the phone lacks a permission.
         */
        Route::get('/emergency-command', [EmergencyCommandController::class, 'index'])
            ->name('emergency-command');
        Route::post('/emergency-command/declare', [EmergencyCommandController::class, 'declare'])
            ->name('emergency-command.declare');
        Route::post('/emergency-command/{uuid}/roll-call', [EmergencyCommandController::class, 'mark'])
            ->whereUuid('uuid')->name('emergency-command.roll-call');
        Route::post('/emergency-command/{uuid}/refresh', [EmergencyCommandController::class, 'refresh'])
            ->whereUuid('uuid')->name('emergency-command.refresh');
        Route::post('/emergency-command/{uuid}/stand-down',
            [EmergencyCommandController::class, 'standDown'])
            ->whereUuid('uuid')->name('emergency-command.stand-down');
        Route::post('/emergency-command/resources',
            [EmergencyCommandController::class, 'saveResource'])
            ->name('emergency-command.resources');
        Route::post('/emergency-command/substances',
            [EmergencyCommandController::class, 'saveSubstance'])
            ->name('emergency-command.substances');
        Route::post('/emergency-command/drills/{uuid}/attendance',
            [EmergencyCommandController::class, 'drillAttendance'])
            ->whereUuid('uuid')->name('emergency-command.drills.attendance');
        Route::post('/emergency-command/competencies/{uuid}/role',
            [EmergencyCommandController::class, 'setEmergencyRole'])
            ->whereUuid('uuid')->name('emergency-command.competencies.role');

        /* Principal contractor, subcontractor, worker (CON-25). */
        Route::get('/subcontractors', [SubcontractorController::class, 'index'])
            ->name('subcontractors');
        Route::post('/subcontractors/appoint', [SubcontractorController::class, 'appoint'])
            ->name('subcontractors.appoint');
        Route::post('/subcontractors/{uuid}/release', [SubcontractorController::class, 'release'])
            ->whereUuid('uuid')->name('subcontractors.release');
        Route::post('/subcontractors/{uuid}/visibility',
            [SubcontractorController::class, 'updateVisibility'])
            ->whereUuid('uuid')->name('subcontractors.visibility');

        /* The score, its arithmetic, and the trend behind it (CON-20, CON-21). */
        Route::get('/contractor-performance',
            [ContractorPerformanceController::class, 'index'])->name('contractor-performance');
        Route::post('/contractor-performance/recompute',
            [ContractorPerformanceController::class, 'recompute'])
            ->name('contractor-performance.recompute');
        Route::post('/contractor-performance/weights',
            [ContractorPerformanceController::class, 'updateWeights'])
            ->name('contractor-performance.weights');
        Route::post('/compliance/check', [ComplianceController::class, 'check'])
            ->name('compliance.check');
        Route::post('/compliance/fail-safe', [ComplianceController::class, 'updateFailSafe'])
            ->name('compliance.fail-safe');

        Route::get('/overrides', [AccessOverrideController::class, 'index'])->name('overrides');
        Route::post('/overrides', [AccessOverrideController::class, 'store'])
            ->name('overrides.store');
        Route::post('/overrides/{uuid}/revoke', [AccessOverrideController::class, 'revoke'])
            ->whereUuid('uuid')->name('overrides.revoke');

        Route::get('/activities', [ActivityController::class, 'index'])->name('activities');
        Route::post('/activities', [ActivityController::class, 'store'])->name('activities.store');
        Route::post('/activities/install', [ActivityController::class, 'install'])
            ->name('activities.install');
        Route::put('/activities/{uuid}', [ActivityController::class, 'update'])
            ->whereUuid('uuid')->name('activities.update');
        Route::delete('/activities/{uuid}', [ActivityController::class, 'destroy'])
            ->whereUuid('uuid')->name('activities.retire');

        // Creating entries. The registers had screens but no way in — this is that way in.
        Route::post('/risk-assessments', [RegisterEntryController::class, 'storeRiskAssessment'])
            ->name('risk-assessments.store');
        Route::post('/permits', [RegisterEntryController::class, 'storePermit'])->name('permits.store');
        Route::post('/documents', [RegisterEntryController::class, 'storeDocument'])->name('documents.store');
        Route::post('/competency', [RegisterEntryController::class, 'storeCompetency'])->name('competency.store');

        /*
         * The lifecycle each register could describe and not perform.
         *
         * A legal appointment under OHSA 16(2) could not be made, accepted or revoked; a document
         * could be published and never acknowledged or withdrawn; PPE could be issued and never
         * returned. All of it existed in the services and had no route.
         */
        /*
         * A ticket earned somewhere else. Without this the only competency a person could hold was
         * one the LMS issued, so anybody trained outside the system read as not competent and the
         * permit gates refused them.
         */
        Route::post('/competency/records', [RegisterEntryController::class, 'recordCompetency'])
            ->name('competency.records.store');

        Route::post('/competency/appoint', [RegisterEntryController::class, 'appoint'])
            ->name('competency.appoint');
        Route::post('/competency/records/{record}/accept', [RegisterEntryController::class, 'acceptAppointment'])
            ->whereNumber('record')->name('competency.accept');
        Route::post('/competency/records/{record}/revoke', [RegisterEntryController::class, 'revokeAppointment'])
            ->whereNumber('record')->name('competency.revoke');

        Route::post('/documents/{uuid}/acknowledge', [RegisterEntryController::class, 'acknowledgeDocument'])
            ->whereUuid('uuid')->name('documents.acknowledge');
        /*
         * Putting a version in force.
         *
         * `publish()` was complete and had no route, so every controlled document created through
         * the register stayed DRAFT for ever: the reader library shows published documents only and
         * was permanently empty, acknowledgement could never be satisfied, and the review reminder
         * had nothing to find. The module could author procedures and could not issue one.
         */
        Route::post('/documents/{uuid}/publish', [RegisterEntryController::class, 'publishDocument'])
            ->whereUuid('uuid')->name('documents.publish');
        Route::post('/documents/{uuid}/withdraw', [RegisterEntryController::class, 'withdrawDocument'])
            ->whereUuid('uuid')->name('documents.withdraw');

        /*
         * Where everybody else reads the procedures.
         *
         * The register is a practitioner's screen — it needs `sheq.document.manage`, shows drafts
         * and offers publishing. There was nowhere for the people a procedure is written for, and
         * a controlled document nobody can find controls nothing.
         *
         * Deliberately outside the document-manage gate: the whole point is that a general reader
         * reaches it.
         */
        /*
         * Hours worked — the denominator under every injury rate.
         *
         * `recordExposure()` existed and nothing called it, so the table stayed empty: LTIFR,
         * TRIR, DIFR and the severity rate had nothing to divide by.
         */
        Route::get('/exposure', [ExposureController::class, 'index'])->name('exposure');
        Route::post('/exposure', [ExposureController::class, 'store'])->name('exposure.store');

        Route::get('/library', [DocumentLibraryController::class, 'index'])->name('library');
        Route::post('/library/{uuid}/acknowledge', [DocumentLibraryController::class, 'acknowledge'])
            ->whereUuid('uuid')->name('library.acknowledge');

        Route::post('/ppe/issues/{issue}/return', [RegisterEntryController::class, 'returnPpe'])
            ->whereUuid('issue')->name('ppe.return');

        /*
         * The last of the behaviour that existed with no way in.
         *
         * An audit finding could be raised and actioned and never verified, so the audit never
         * completed. A supplier assessment could be made and never decided. Equipment stayed
         * IN_SERVICE whatever happened to it. A hazard that turned out to be an incident stayed a
         * separate record of the same event.
         */
        Route::post('/suppliers/assessments/{assessment}/decide', [RegisterEntryController::class, 'decideSupplierAssessment'])
            ->whereNumber('assessment')->name('suppliers.assessments.decide');
        Route::post('/equipment/{uuid}/status', [RegisterEntryController::class, 'changeEquipmentStatus'])
            ->whereUuid('uuid')->name('equipment.status');
        Route::post('/hazards/{uuid}/link-incident', [RegisterEntryController::class, 'linkHazardToIncident'])
            ->whereUuid('uuid')->name('hazards.link-incident');
        Route::post('/contractors', [RegisterEntryController::class, 'storeContractor'])->name('contractors.store');
        Route::post('/contractors/{uuid}/documents', [RegisterEntryController::class, 'storeContractorDocument'])
            ->whereUuid('uuid')->name('contractors.documents.store');

        /*
         * One contractor, and whether their people may come on site.
         *
         * Registering one read as BLOCKED immediately — correctly, since access is refused until
         * the required documents are held — and there was no screen on which to add one, so the
         * block could never be lifted.
         */
        Route::get('/contractors/{uuid}', [ContractorController::class, 'show'])
            ->whereUuid('uuid')->name('contractors.show');
        Route::post('/contractors/{uuid}/workers', [ContractorController::class, 'storeWorker'])
            ->whereUuid('uuid')->name('contractors.workers.store');
        Route::post('/contractors/{uuid}/override', [ContractorController::class, 'overrideAccess'])
            ->whereUuid('uuid')->name('contractors.override');
        /*
         * CON-14. Approval is per site, so the decision is posted with a site and never without
         * one: a company-wide "approve" is exactly the thing this requirement exists to stop.
         */
        Route::post('/contractors/{uuid}/sites', [ContractorController::class, 'decideSite'])
            ->whereUuid('uuid')->name('contractors.sites.decide');
        Route::delete('/contractors/{uuid}/sites', [ContractorController::class, 'withdrawSite'])
            ->whereUuid('uuid')->name('contractors.sites.withdraw');
        /*
         * CON-5, from the contractor's side.
         *
         * The join between a contractor's safety file and their SHEQ assessment is `supplier_id` on
         * both records, and nothing ever wrote either: no validator accepted the field and the
         * bridge's `link()` had no caller.
         */
        /*
         * The digital safety file (CON-6).
         *
         * What it replaces is a lever-arch folder with dividers, carried to the gate and
         * photocopied when it expires. Its sections are configurable because "COID" is a South
         * African word and a load-test certificate is a construction demand: a bookkeeping
         * contractor asked for one learns to ignore the whole file.
         */
        Route::get('/contractors/{uuid}/safety-file', [ContractorController::class, 'safetyFile'])
            ->whereUuid('uuid')->name('contractors.safety-file');
        /*
         * The bundle. A zip, not a merged PDF: a real safety file is PDFs, photographs,
         * spreadsheets and the occasional Word document, and merging that means dropping whatever
         * will not merge. The index inside it is the PDF, and it states the gaps — a bundle of
         * only what exists is a selective bundle.
         */
        Route::get('/contractors/{uuid}/safety-file/export',
            [ContractorController::class, 'exportSafetyFile'])
            ->whereUuid('uuid')->name('contractors.safety-file.export');
        /*
         * Issuing and printing a pass (CON-11, CON-13). Inside the gate: this is a write on a
         * contractor record and needs an account like every other one.
         *
         * `{subject}` is `contractors` or `workers`, so one pair of routes serves both rather than
         * two near-identical pairs drifting apart.
         */
        Route::get('/pass/{subject}/{uuid}/qr.svg', [AccessPassController::class, 'image'])
            ->whereIn('subject', ['contractors', 'workers'])->whereUuid('uuid')
            ->name('pass.image');
        // The printable card, which is what a person actually carries past a boom.
        Route::get('/pass/{subject}/{uuid}/card', [AccessPassController::class, 'card'])
            ->whereIn('subject', ['contractors', 'workers'])
            ->name('pass.card');
        Route::post('/pass/{subject}/{uuid}/rotate', [AccessPassController::class, 'rotate'])
            ->whereIn('subject', ['contractors', 'workers'])->whereUuid('uuid')
            ->name('pass.rotate');

        /*
         * Which conditions refuse site access (CON-10). A host's policy, company-wide: applying
         * it unevenly between contractors is how a challenge at a boom succeeds.
         */
        Route::post('/contractors/{uuid}/blocking-rules',
            [ContractorController::class, 'saveBlockingRules'])
            ->whereUuid('uuid')->name('contractors.blocking-rules');

        /*
         * The safety file's own states (CON-7). One route rather than six: the legal moves are a
         * property of the state machine, and six endpoints would be six places to forget a guard.
         */
        Route::post('/contractors/{uuid}/safety-file/transition',
            [ContractorController::class, 'transitionSafetyFile'])
            ->whereUuid('uuid')->name('contractors.safety-file.transition');
        Route::post('/contractors/{uuid}/safety-file/profile',
            [ContractorController::class, 'applySafetyFileProfile'])
            ->whereUuid('uuid')->name('contractors.safety-file.profile');
        Route::post('/contractors/{uuid}/safety-file/sections/{code}',
            [ContractorController::class, 'configureSafetyFileSection'])
            ->whereUuid('uuid')->name('contractors.safety-file.section');

        /*
         * Correcting a compliance file.
         *
         * The check keeps the furthest expiry per document type, so a date typed as 2030 instead of
         * 2026 wins over the correct certificate attached afterwards and the contractor reads
         * COMPLIANT. Deleting the wrong row would destroy compliance evidence, so it is set aside
         * instead: kept, readable, and no longer counted.
         */
        /*
         * Accepting or rejecting a supplied document (CON-8). A document that has arrived and not
         * been checked satisfies nothing; this is the act that makes it count, or refuses it with
         * a reason the contractor can act on.
         */
        Route::post('/contractors/{uuid}/documents/{document}/decide',
            [ContractorController::class, 'decideDocument'])
            ->whereUuid('uuid')->whereUuid('document')->name('contractors.documents.decide');
        Route::post('/contractors/{uuid}/documents/{document}/supersede',
            [ContractorController::class, 'supersedeDocument'])
            ->whereUuid('uuid')->whereUuid('document')->name('contractors.documents.supersede');
        Route::post('/contractors/{uuid}/link-supplier', [ContractorController::class, 'linkSupplier'])
            ->whereUuid('uuid')->name('contractors.link-supplier');
        Route::post('/contractors/{uuid}/refresh', [ContractorController::class, 'refresh'])
            ->whereUuid('uuid')->name('contractors.refresh');

        // ── Configure: the PPE catalogue and stock the issue register reads from ──────────────
        // Under settings/ because it is set up once and then left alone, unlike the register it
        // feeds, which is used every day. Every write here needs sheq.configure; the controller
        // enforces that per action rather than the route, matching the rest of the module.
        Route::get('/settings/ppe', [PpeCatalogueController::class, 'index'])->name('ppe.catalogue');
        Route::post('/settings/ppe/items', [PpeCatalogueController::class, 'storeItem'])
            ->name('ppe.catalogue.items.store');
        /*
         * Loading a catalogue from a spreadsheet, and the template that says what the columns are.
         * Eighty supplier items typed one at a time is why a catalogue stays half-filled and the
         * issue register stays empty behind it.
         *
         * Declared above the `{uuid}` routes: `whereUuid` already stops "import" matching them, but
         * a reader should not have to know that to see why this is safe.
         */
        Route::post('/settings/ppe/items/import', [PpeCatalogueController::class, 'importItems'])
            ->name('ppe.catalogue.items.import');
        Route::get('/settings/ppe/items/template', [PpeCatalogueController::class, 'importTemplate'])
            ->name('ppe.catalogue.items.template');
        Route::post('/settings/ppe/items/{uuid}', [PpeCatalogueController::class, 'updateItem'])
            ->whereUuid('uuid')->name('ppe.catalogue.items.update');
        Route::post('/settings/ppe/items/{uuid}/toggle', [PpeCatalogueController::class, 'toggleItem'])
            ->whereUuid('uuid')->name('ppe.catalogue.items.toggle');
        Route::delete('/settings/ppe/items/{uuid}', [PpeCatalogueController::class, 'destroyItem'])
            ->whereUuid('uuid')->name('ppe.catalogue.items.destroy');
        Route::post('/settings/ppe/stock', [PpeCatalogueController::class, 'storeStock'])
            ->name('ppe.catalogue.stock.store');
        Route::delete('/settings/ppe/stock/{uuid}', [PpeCatalogueController::class, 'destroyStock'])
            ->whereUuid('uuid')->name('ppe.catalogue.stock.destroy');

        // ── Settings: employer particulars the statutory forms read ───────────────────────────
        Route::get('/settings', [SheqSettingsController::class, 'index'])->name('settings');
        Route::post('/settings', [SheqSettingsController::class, 'update'])->name('settings.update');

        // ── Statutory forms (SHEQ-REG-6) ──────────────────────────────────────────────────────
        Route::get('/forms', [StatutoryFormController::class, 'index'])->name('forms.index');
        Route::post('/forms', [StatutoryFormController::class, 'generate'])->name('forms.generate');
        Route::get('/forms/{uuid}', [StatutoryFormController::class, 'show'])
            ->whereUuid('uuid')->name('forms.show');
        Route::get('/forms/{uuid}/pdf', [StatutoryFormController::class, 'pdf'])
            ->whereUuid('uuid')->name('forms.pdf');
        Route::post('/forms/{uuid}/override', [StatutoryFormController::class, 'override'])
            ->whereUuid('uuid')->name('forms.override');
        Route::post('/forms/{uuid}/finalise', [StatutoryFormController::class, 'finalise'])
            ->whereUuid('uuid')->name('forms.finalise');

        // ── AI Safety Officer (§7) ────────────────────────────────────────────────────────────
        // Deliberately absent: any route by which the assistant writes to an operational record.
        // Everything it produces arrives here as a draft and leaves only through a human decision.
        Route::get('/ai', [AiAssistantController::class, 'index'])->name('ai');
        Route::post('/ai/generate', [AiAssistantController::class, 'generate'])->name('ai.generate');
        /*
         * Asking a question. Returns text and nothing else — no draft is filed, because a question
         * is not a proposal and the review queue is for decisions. Throttled: a model call costs
         * the tenant tokens against a quota, and a held-down key should not spend them.
         */
        Route::post('/ai/chat', [AiAssistantController::class, 'chat'])
            ->middleware('throttle:20,1')->name('ai.chat');
        Route::post('/ai/{uuid}/decide', [AiAssistantController::class, 'decide'])
            ->whereUuid('uuid')->name('ai.decide');

        // ── Approvals (cross-cutting) ─────────────────────────────────────────────────────────
        Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals');
        Route::post('/approvals/{uuid}/decide', [ApprovalController::class, 'decide'])
            ->whereUuid('uuid')->name('approvals.decide');
        Route::get('/approvals/workflows', [ApprovalController::class, 'workflows'])
            ->name('approvals.workflows');
        Route::post('/approvals/workflows', [ApprovalController::class, 'storeWorkflow'])
            ->name('approvals.workflows.store');
        // Retire, not delete: see retireWorkflow().
        Route::delete('/approvals/workflows/{uuid}', [ApprovalController::class, 'retireWorkflow'])
            ->whereUuid('uuid')->name('approvals.workflows.retire');

        // ── Audits and supplier SHEQ onboarding ───────────────────────────────────────────────
        Route::get('/audits', [AuditController::class, 'index'])->name('audits');
        Route::post('/audits', [AuditController::class, 'store'])->name('audits.store');
        Route::get('/audits/{uuid}', [AuditController::class, 'show'])
            ->whereUuid('uuid')->name('audits.show');
        Route::post('/audits/{uuid}/fieldwork', [AuditController::class, 'recordFieldwork'])
            ->whereUuid('uuid')->name('audits.fieldwork');
        Route::post('/audits/{uuid}/findings', [AuditController::class, 'raiseFinding'])
            ->whereUuid('uuid')->name('audits.findings.raise');
        Route::post('/audits/{uuid}/close', [AuditController::class, 'close'])
            ->whereUuid('uuid')->name('audits.close');
        Route::post('/findings/{uuid}/respond', [AuditController::class, 'respondToFinding'])
            ->whereUuid('uuid')->name('findings.respond');
        Route::post('/findings/{uuid}/verify', [AuditController::class, 'requestFindingClosure'])
            ->whereUuid('uuid')->name('findings.verify');

        /*
         * The other half of verification.
         *
         * `requestFindingClosure` tells the auditee's side "somebody other than you has to agree
         * it is fixed" — and nobody could record that agreement, because
         * `applyClosureDecision()` had no route. An audit whose findings cannot be signed off
         * never completes, and the programme behind it never shows as done.
         */
        Route::post('/findings/{finding}/decide', [RegisterEntryController::class, 'decideAuditFinding'])
            ->whereNumber('finding')->name('findings.decide');

        Route::get('/suppliers', [AuditController::class, 'suppliers'])->name('suppliers');
        Route::post('/suppliers', [AuditController::class, 'inviteSupplier'])->name('suppliers.invite');
        // CON-5, from the assessment's side: reuse the portal login the supplier already has.
        Route::post('/suppliers/{uuid}/link', [AuditController::class, 'linkSupplier'])
            ->whereUuid('uuid')->name('suppliers.link');
        Route::post('/suppliers/{uuid}/submit', [AuditController::class, 'submitSupplier'])
            ->whereUuid('uuid')->name('suppliers.submit');

        // ── Operational registers ─────────────────────────────────────────────────────────────
        Route::get('/ppe', [OperationalRegistersController::class, 'ppe'])->name('ppe');
        Route::post('/ppe/issue', [OperationalRegistersController::class, 'issuePpe'])->name('ppe.issue');

        Route::get('/medical', [OperationalRegistersController::class, 'medical'])->name('medical');
        Route::post('/medical', [OperationalRegistersController::class, 'storeMedical'])->name('medical.store');
        /*
         * The clinical detail of one examination, for a holder of `sheq.pii.read`.
         *
         * Asked for one record at a time rather than shipped with the register: health information
         * should travel when somebody entitled to it asks, not sit in the page payload of every
         * examination on a list.
         */
        Route::get('/medical/{uuid}/clinical', [OperationalRegistersController::class, 'clinicalDetail'])
            ->whereUuid('uuid')->name('medical.clinical');

        Route::get('/environmental', [OperationalRegistersController::class, 'environmental'])
            ->name('environmental');
        Route::post('/environmental-registers/readings', [OperationalRegistersController::class, 'storeReading'])
            ->name('environmental-registers.readings');

        Route::get('/equipment', [OperationalRegistersController::class, 'equipment'])->name('equipment');
        Route::post('/equipment', [OperationalRegistersController::class, 'storeEquipment'])
            ->name('equipment.store');
        Route::post('/equipment/{uuid}/inspect', [OperationalRegistersController::class, 'inspectEquipment'])
            ->whereUuid('uuid')->name('equipment.inspect');

        Route::get('/visitors', [OperationalRegistersController::class, 'visitors'])->name('visitors');
        Route::post('/visitors', [OperationalRegistersController::class, 'requestVisit'])
            ->name('visitors.request');
        Route::post('/visitors/{uuid}/{action}', [OperationalRegistersController::class, 'visitorAction'])
            ->whereUuid('uuid')->whereIn('action', ['induct', 'sign-in', 'sign-out'])
            ->name('visitors.action');

        Route::get('/emergency', [OperationalRegistersController::class, 'emergency'])->name('emergency');
        Route::post('/emergency/plans', [OperationalRegistersController::class, 'storeEmergencyPlan'])
            ->name('emergency.plans');
        Route::post('/emergency/drills', [OperationalRegistersController::class, 'storeDrill'])
            ->name('emergency.drills');

        // Read-only: training records live in Learning Management and are not duplicated here.
        Route::get('/training', [OperationalRegistersController::class, 'training'])->name('training');

        // ── Access-control and clock-in integrations ──────────────────────────────────────────
        // The screen the whole gate layer exists for: credentials in, test, watch, then enforce.
        Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations');
        Route::post('/integrations', [IntegrationsController::class, 'store'])->name('integrations.store');
        Route::post('/integrations/{uuid}/test', [IntegrationsController::class, 'test'])
            ->whereUuid('uuid')->name('integrations.test');
        Route::post('/integrations/{uuid}/sync', [IntegrationsController::class, 'sync'])
            ->whereUuid('uuid')->name('integrations.sync');
        Route::post('/integrations/{uuid}/enforcement', [IntegrationsController::class, 'setEnforcement'])
            ->whereUuid('uuid')->name('integrations.enforcement');

        // ── Site induction and bulk onboarding ────────────────────────────────────────────────
        Route::get('/inductions', [InductionController::class, 'index'])->name('inductions');
        Route::post('/inductions', [InductionController::class, 'store'])->name('inductions.store');
        Route::get('/inductions/template/{type}', [InductionController::class, 'template'])
            ->name('inductions.template');
        Route::post('/inductions/upload', [InductionController::class, 'upload'])
            ->name('inductions.upload');

        // ── Toolbox talks and analytics findings ──────────────────────────────────────────────
        Route::get('/talks', [ToolboxTalkController::class, 'index'])->name('talks');
        Route::post('/talks', [ToolboxTalkController::class, 'store'])->name('talks.store');
        Route::post('/insights/{uuid}/dismiss', [ToolboxTalkController::class, 'dismissInsight'])
            ->whereUuid('uuid')->name('insights.dismiss');

        // ── Insights (§5.11) ──────────────────────────────────────────────────────────────────
        Route::get('/insights/analysis', [InsightsController::class, 'analysis'])->name('insights.analysis');
        Route::get('/insights/reports', [InsightsController::class, 'reports'])->name('insights.reports');
        // Same filters, same rows, different container — see exportReport().
        Route::get('/insights/reports/export', [InsightsController::class, 'exportReport'])
            ->name('insights.reports.export');

        // ── Sync conflicts — the "Needs your attention" queue (SHEQ-OFF-5) ────────────────────
        Route::get('/sync/conflicts', [SyncConflictController::class, 'index'])->name('sync.conflicts');
        Route::post('/sync/conflicts/{uuid}/resolve', [SyncConflictController::class, 'resolve'])
            ->whereUuid('uuid')->name('sync.conflicts.resolve');

        // ── Compliance ────────────────────────────────────────────────────────────────────────
        Route::get('/regulatory-packs', [RegulatoryPackController::class, 'index'])->name('regulatory-packs');
        Route::post('/regulatory-packs/activate', [RegulatoryPackController::class, 'activate'])
            ->name('regulatory-packs.activate');
        Route::get('/legal-register', [RegulatoryPackController::class, 'legalRegister'])->name('legal-register');

        // SHEQ-LEG-2..4. Deliberately absent: any route that sets a line's status directly —
        // compliance is computed from the evidence linked here, never declared.
        Route::post('/legal-register/{uuid}/evidence', [LegalRegisterController::class, 'linkEvidence'])
            ->name('legal-register.evidence.link');
        Route::delete('/legal-register/{uuid}/evidence', [LegalRegisterController::class, 'unlinkEvidence'])
            ->name('legal-register.evidence.unlink');
        Route::post('/legal-register/{uuid}/not-applicable', [LegalRegisterController::class, 'markNotApplicable'])
            ->name('legal-register.not-applicable');
        Route::post('/legal-register/{uuid}/acknowledge', [LegalRegisterController::class, 'acknowledgeChange'])
            ->name('legal-register.acknowledge');
    });
