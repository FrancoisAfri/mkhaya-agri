<?php

use App\Modules\PerformanceAppraisal\Controllers\PaDashboardController;
use App\Modules\PerformanceAppraisal\Controllers\PaCycleController;
use App\Modules\PerformanceAppraisal\Controllers\PaTemplateController;
use App\Modules\PerformanceAppraisal\Controllers\PaRatingScaleController;
use App\Modules\PerformanceAppraisal\Controllers\PaSettingsController;
use App\Modules\PerformanceAppraisal\Controllers\PaGoalController;
use App\Modules\PerformanceAppraisal\Controllers\PaKpiController;
use App\Modules\PerformanceAppraisal\Controllers\PaAiInsightController;
use App\Modules\PerformanceAppraisal\Controllers\PaFeedbackController;
use App\Modules\PerformanceAppraisal\Controllers\PaCheckinController;
use App\Modules\PerformanceAppraisal\Controllers\Pa360ReviewController;
use App\Modules\PerformanceAppraisal\Controllers\PaProbationController;
use App\Modules\PerformanceAppraisal\Controllers\PaWellnessController;
use App\Modules\PerformanceAppraisal\Controllers\PaDevelopmentController;
use App\Modules\PerformanceAppraisal\Controllers\PaSuccessionController;
use App\Modules\PerformanceAppraisal\Controllers\PaTalentGrowthController;
use App\Modules\PerformanceAppraisal\Controllers\PaPolicyController;
use App\Modules\PerformanceAppraisal\Controllers\PaPipController;
use App\Modules\PerformanceAppraisal\Controllers\PaCalibrationController;
use App\Modules\PerformanceAppraisal\Controllers\PaAnalyticsController;
use App\Modules\PerformanceAppraisal\Controllers\PaReportController;
use App\Modules\PerformanceAppraisal\Controllers\PaMyDashboardController;
use App\Modules\PerformanceAppraisal\Controllers\PaManagerDashboardController;
use App\Modules\PerformanceAppraisal\Controllers\PaAppraisalReviewController;
use App\Modules\PerformanceAppraisal\Controllers\PaNineBoxController;
use App\Modules\PerformanceAppraisal\Controllers\PaRecognitionController;
use App\Modules\PerformanceAppraisal\Controllers\PaCompensationController;
use App\Modules\PerformanceAppraisal\Controllers\PaCompetencyController;
use App\Modules\PerformanceAppraisal\Controllers\PaNudgeController;
use App\Modules\PerformanceAppraisal\Controllers\PaDeiController;
use App\Modules\PerformanceAppraisal\Controllers\PaDevLmsController;
use App\Modules\PerformanceAppraisal\Controllers\PaOneOnOneController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['module.active:performance_appraisal', 'module.tier:performance_appraisal'])->prefix('performance')->name('performance.')->group(function () {

    // ── Navigation hubs — role-filtered feature-card landing pages ────────────
    foreach (['my-performance-hub' => 'my_performance', 'team-hub' => 'team', 'manage-hub' => 'manage', 'insights-hub' => 'insights'] as $hubPath => $group) {
        Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
            ->defaults('module', 'performance_appraisal')->defaults('group', $group)->name('hub.' . $group);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/', [PaDashboardController::class, 'index'])->name('dashboard');

    // ── My Dashboard (employee personal view) ─────────────────────────────────
    Route::get('/my-dashboard',      [PaMyDashboardController::class,      'index'])->name('my-dashboard');
    Route::get('/manager-dashboard', [PaManagerDashboardController::class, 'index'])->name('manager-dashboard');

    // ── 9-Box Talent Grid ─────────────────────────────────────────────────────
    Route::prefix('nine-box')->name('nine-box.')->group(function () {
        Route::get('/',         [PaNineBoxController::class, 'index'])->name('index');
        Route::post('/rebuild', [PaNineBoxController::class, 'rebuild'])->name('rebuild');
        Route::post('/move',    [PaNineBoxController::class, 'move'])->name('move');
    });

    // ── Recognition / Kudos feed ──────────────────────────────────────────────
    Route::prefix('recognition')->name('recognition.')->group(function () {
        Route::get('/',                      [PaRecognitionController::class, 'index'])->name('index');
        Route::post('/',                     [PaRecognitionController::class, 'store'])->name('store');
        Route::post('/{recognition}/react',  [PaRecognitionController::class, 'react'])->name('react');
        Route::post('/{recognition}/comment',[PaRecognitionController::class, 'comment'])->name('comment');
        Route::delete('/{recognition}',      [PaRecognitionController::class, 'destroy'])->name('destroy');
    });

    // ── Goal alignment tree (OKR cascade) ─────────────────────────────────────
    Route::get('/goals/tree', [\App\Modules\PerformanceAppraisal\Controllers\PaGoalController::class, 'tree'])->name('goals.tree');

    // ── Compensation / Merit review ───────────────────────────────────────────
    Route::prefix('compensation')->name('compensation.')->group(function () {
        Route::get('/',                          [PaCompensationController::class, 'index'])->name('index');
        Route::post('/',                         [PaCompensationController::class, 'store'])->name('store');
        Route::get('/{compReview}',              [PaCompensationController::class, 'show'])->name('show');
        Route::post('/{compReview}/regenerate',  [PaCompensationController::class, 'regenerate'])->name('regenerate');
        Route::post('/{compReview}/approve',     [PaCompensationController::class, 'approve'])->name('approve');
        Route::patch('/recommendations/{recommendation}', [PaCompensationController::class, 'updateRecommendation'])->name('recommendation.update');
    });

    // ── Competency framework ──────────────────────────────────────────────────
    Route::prefix('competencies')->name('competencies.')->group(function () {
        Route::get('/',                       [PaCompetencyController::class, 'index'])->name('index');
        Route::post('/',                      [PaCompetencyController::class, 'store'])->name('store');
        Route::patch('/{competency}',         [PaCompetencyController::class, 'update'])->name('update');
        Route::delete('/{competency}',        [PaCompetencyController::class, 'destroy'])->name('destroy');
        Route::post('/{competency}/anchors',  [PaCompetencyController::class, 'saveAnchor'])->name('anchors.save');
        Route::post('/map-role',              [PaCompetencyController::class, 'mapRole'])->name('map-role');
        Route::delete('/role-map/{roleCompetency}', [PaCompetencyController::class, 'unmapRole'])->name('unmap-role');
    });

    // ── DEI / fairness analytics ──────────────────────────────────────────────
    Route::get('/dei', [PaDeiController::class, 'index'])->name('dei.index');

    // ── Proactive AI nudges + review auto-draft ───────────────────────────────
    Route::prefix('nudges')->name('nudges.')->group(function () {
        Route::get('/',                    [PaNudgeController::class, 'index'])->name('index');
        Route::post('/generate',           [PaNudgeController::class, 'generate'])->name('generate');
        Route::patch('/{nudge}/dismiss',   [PaNudgeController::class, 'dismiss'])->name('dismiss');
        Route::get('/draft-review/{snapshot}', [PaNudgeController::class, 'draftReview'])->name('draft-review');
    });

    // ── Development → LMS integration ─────────────────────────────────────────
    Route::prefix('dev-lms')->name('dev-lms.')->group(function () {
        Route::get('/courses',                         [PaDevLmsController::class, 'courses'])->name('courses');
        Route::post('/activities/{activity}/link',     [PaDevLmsController::class, 'linkActivity'])->name('link-activity');
        Route::post('/recommendations/{recommendation}/enrol', [PaDevLmsController::class, 'enrolRecommendation'])->name('enrol-recommendation');
    });

    // ── 1:1 talking points & action items ─────────────────────────────────────
    Route::prefix('checkins/{checkin}')->name('checkins.')->group(function () {
        Route::post('/talking-points',  [PaOneOnOneController::class, 'addTalkingPoint'])->name('talking-points.add');
        Route::post('/action-items',    [PaOneOnOneController::class, 'addActionItem'])->name('action-items.add');
        Route::post('/carry-over',      [PaOneOnOneController::class, 'carryOver'])->name('carry-over');
    });
    Route::patch('/talking-points/{talkingPoint}/toggle', [PaOneOnOneController::class, 'toggleTalkingPoint'])->name('talking-points.toggle');
    Route::delete('/talking-points/{talkingPoint}',       [PaOneOnOneController::class, 'deleteTalkingPoint'])->name('talking-points.delete');
    Route::patch('/action-items/{actionItem}',            [PaOneOnOneController::class, 'updateActionItem'])->name('action-items.update');
    Route::delete('/action-items/{actionItem}',           [PaOneOnOneController::class, 'deleteActionItem'])->name('action-items.delete');

    // ── Appraisal Cycles ──────────────────────────────────────────────────────
    Route::prefix('cycles')->name('cycles.')->group(function () {
        Route::get('/',          [PaCycleController::class, 'index'])->name('index');
        Route::post('/',         [PaCycleController::class, 'store'])->name('store');
        Route::patch('/{cycle}', [PaCycleController::class, 'update'])->name('update');
        Route::delete('/{cycle}',[PaCycleController::class, 'destroy'])->name('destroy');
        Route::patch('/{cycle}/activate', [PaCycleController::class, 'activate'])->name('activate');
        Route::patch('/{cycle}/close',    [PaCycleController::class, 'close'])->name('close');
    });

    // ── Templates ─────────────────────────────────────────────────────────────
    Route::prefix('templates')->name('templates.')->group(function () {
        Route::get('/',               [PaTemplateController::class, 'index'])->name('index');
        Route::post('/',              [PaTemplateController::class, 'store'])->name('store');
        Route::get('/{template}',     [PaTemplateController::class, 'show'])->name('show');
        Route::patch('/{template}',   [PaTemplateController::class, 'update'])->name('update');
        Route::delete('/{template}',  [PaTemplateController::class, 'destroy'])->name('destroy');

        // Sections
        Route::prefix('/{template}/sections')->name('sections.')->group(function () {
            Route::post('/',                    [PaTemplateController::class, 'sectionStore'])->name('store');
            Route::patch('/{section}',          [PaTemplateController::class, 'sectionUpdate'])->name('update');
            Route::delete('/{section}',         [PaTemplateController::class, 'sectionDestroy'])->name('destroy');

            // Questions
            Route::prefix('/{section}/questions')->name('questions.')->group(function () {
                Route::post('/',             [PaTemplateController::class, 'questionStore'])->name('store');
                Route::patch('/{question}',  [PaTemplateController::class, 'questionUpdate'])->name('update');
                Route::delete('/{question}', [PaTemplateController::class, 'questionDestroy'])->name('destroy');
            });
        });
    });

    // ── Rating Scales ─────────────────────────────────────────────────────────
    Route::prefix('rating-scales')->name('rating-scales.')->group(function () {
        Route::get('/',        [PaRatingScaleController::class, 'index'])->name('index');
        Route::post('/',       [PaRatingScaleController::class, 'store'])->name('store');
        Route::patch('/{scale}',  [PaRatingScaleController::class, 'update'])->name('update');
        Route::delete('/{scale}', [PaRatingScaleController::class, 'destroy'])->name('destroy');

        // Values
        Route::prefix('/{scale}/values')->name('values.')->group(function () {
            Route::post('/',           [PaRatingScaleController::class, 'valueStore'])->name('store');
            Route::patch('/{value}',   [PaRatingScaleController::class, 'valueUpdate'])->name('update');
            Route::delete('/{value}',  [PaRatingScaleController::class, 'valueDestroy'])->name('destroy');
        });
    });

    // ── Organisational Goals ──────────────────────────────────────────────────
    Route::prefix('goals')->name('goals.')->group(function () {
        Route::get('/',        [PaGoalController::class, 'index'])->name('index');
        Route::post('/',       [PaGoalController::class, 'store'])->name('store');
        Route::patch('/{goal}',[PaGoalController::class, 'update'])->name('update');
        Route::delete('/{goal}',[PaGoalController::class, 'destroy'])->name('destroy');
        Route::patch('/{goal}/progress', [PaGoalController::class, 'updateProgress'])->name('progress');
    });

    // ── Employee KPIs ─────────────────────────────────────────────────────────
    Route::prefix('kpis')->name('kpis.')->group(function () {
        Route::get('/',      [PaKpiController::class, 'index'])->name('index');
        Route::post('/',     [PaKpiController::class, 'store'])->name('store');
        Route::patch('/{kpi}',[PaKpiController::class, 'update'])->name('update');
        Route::delete('/{kpi}',[PaKpiController::class, 'destroy'])->name('destroy');

        // Progress
        Route::post('/{kpi}/progress',  [PaKpiController::class, 'logProgress'])->name('progress.log');
        Route::get('/{kpi}/progress',   [PaKpiController::class, 'progressHistory'])->name('progress.history');

        // Evidence
        Route::post('/{kpi}/evidence',         [PaKpiController::class, 'uploadEvidence'])->name('evidence.upload');
        Route::get('/evidence/{evidence}/download', [PaKpiController::class, 'downloadEvidence'])->name('evidence.download');
        Route::delete('/evidence/{evidence}',       [PaKpiController::class, 'destroyEvidence'])->name('evidence.destroy');

        // Scoring
        Route::post('/{kpi}/score',             [PaKpiController::class, 'scoreKpi'])->name('score');
        Route::post('/score-employee/{cycle}',  [PaKpiController::class, 'scoreEmployee'])->name('score.employee');
        Route::get('/report/{cycle}',           [PaKpiController::class, 'completionReport'])->name('report');
    });

    // ── AI Performance Intelligence ───────────────────────────────────────────
    Route::prefix('ai')->name('ai.')->group(function () {
        Route::get('/',                                        [PaAiInsightController::class, 'index'])->name('index');
        Route::patch('/insights/{insight}/acknowledge',        [PaAiInsightController::class, 'acknowledge'])->name('acknowledge');
        Route::post('/analyse/{cycle}',                        [PaAiInsightController::class, 'runAnalysis'])->name('analyse');
        Route::post('/bias-detection/{cycle}',                 [PaAiInsightController::class, 'runBiasDetection'])->name('bias');
        Route::post('/inconsistency-detection/{cycle}',        [PaAiInsightController::class, 'runInconsistencyDetection'])->name('inconsistency');
    });

    // ── Continuous Feedback ───────────────────────────────────────────────────
    Route::prefix('feedback')->name('feedback.')->group(function () {
        Route::get('/',                                  [PaFeedbackController::class, 'index'])->name('index');
        Route::post('/',                                 [PaFeedbackController::class, 'store'])->name('store');
        Route::patch('/{feedback}',                      [PaFeedbackController::class, 'update'])->name('update');
        Route::delete('/{feedback}',                     [PaFeedbackController::class, 'destroy'])->name('destroy');
        Route::patch('/{feedback}/acknowledge',          [PaFeedbackController::class, 'acknowledge'])->name('acknowledge');
        Route::post('/summary',                          [PaFeedbackController::class, 'summary'])->name('summary');
    });

    // ── Check-Ins & Coaching Sessions ────────────────────────────────────────
    Route::prefix('checkins')->name('checkins.')->group(function () {
        Route::get('/',                  [PaCheckinController::class, 'index'])->name('index');
        Route::post('/',                 [PaCheckinController::class, 'store'])->name('store');
        Route::patch('/{checkin}',       [PaCheckinController::class, 'update'])->name('update');
        Route::delete('/{checkin}',      [PaCheckinController::class, 'destroy'])->name('destroy');

        // Coaching sessions
        Route::post('/sessions',                   [PaCheckinController::class, 'sessionStore'])->name('sessions.store');
        Route::patch('/sessions/{session}',        [PaCheckinController::class, 'sessionUpdate'])->name('sessions.update');
        Route::delete('/sessions/{session}',       [PaCheckinController::class, 'sessionDestroy'])->name('sessions.destroy');
    });

    // ── 360 Degree Reviews ────────────────────────────────────────────────────
    Route::prefix('360')->name('360.')->group(function () {
        Route::get('/',          [Pa360ReviewController::class, 'index'])->name('index');
        Route::post('/',         [Pa360ReviewController::class, 'store'])->name('store');
        Route::get('/{review}',  [Pa360ReviewController::class, 'show'])->name('show');
        Route::patch('/{review}',[Pa360ReviewController::class, 'update'])->name('update');
        Route::delete('/{review}',[Pa360ReviewController::class, 'destroy'])->name('destroy');

        // Rater management
        Route::post('/{review}/raters',               [Pa360ReviewController::class, 'addRater'])->name('raters.add');
        Route::delete('/{review}/raters/{rater}',     [Pa360ReviewController::class, 'removeRater'])->name('raters.remove');
        Route::patch('/{review}/open',                [Pa360ReviewController::class, 'openCollection'])->name('open');
        Route::patch('/{review}/close',               [Pa360ReviewController::class, 'closeCollection'])->name('close');
        Route::post('/{review}/report',               [Pa360ReviewController::class, 'generateReport'])->name('report');

        // Rater response (public-ish — rater submits their form)
        Route::get('/respond/{rater}',    [Pa360ReviewController::class, 'respond'])->name('respond');
        Route::post('/respond/{rater}',   [Pa360ReviewController::class, 'submitResponse'])->name('respond.submit');

        // Question bank
        Route::post('/questions',                [Pa360ReviewController::class, 'questionStore'])->name('questions.store');
        Route::patch('/questions/{question}',    [Pa360ReviewController::class, 'questionUpdate'])->name('questions.update');
        Route::delete('/questions/{question}',   [Pa360ReviewController::class, 'questionDestroy'])->name('questions.destroy');
    });

    // ── Probation Performance Intelligence ───────────────────────────────────
    Route::prefix('probation')->name('probation.')->group(function () {
        Route::get('/',          [PaProbationController::class, 'index'])->name('index');
        Route::post('/',         [PaProbationController::class, 'store'])->name('store');
        Route::get('/{review}',  [PaProbationController::class, 'show'])->name('show');
        Route::patch('/{review}',[PaProbationController::class, 'update'])->name('update');
        Route::delete('/{review}',[PaProbationController::class, 'destroy'])->name('destroy');

        // Finalise outcome
        Route::patch('/{review}/finalise', [PaProbationController::class, 'finalise'])->name('finalise');

        // Risk & AI
        Route::post('/{review}/risk',         [PaProbationController::class, 'calculateRisk'])->name('risk');
        Route::post('/{review}/ai-prediction',[PaProbationController::class, 'generateAiPrediction'])->name('ai');

        // Objectives
        Route::prefix('/{review}/objectives')->name('objectives.')->group(function () {
            Route::post('/',                [PaProbationController::class, 'objectiveStore'])->name('store');
            Route::patch('/{objective}',    [PaProbationController::class, 'objectiveUpdate'])->name('update');
            Route::delete('/{objective}',   [PaProbationController::class, 'objectiveDestroy'])->name('destroy');
        });

        // Check-ins
        Route::prefix('/{review}/checkins')->name('checkins.')->group(function () {
            Route::post('/',            [PaProbationController::class, 'checkinStore'])->name('store');
            Route::patch('/{checkin}',  [PaProbationController::class, 'checkinUpdate'])->name('update');
            Route::delete('/{checkin}', [PaProbationController::class, 'checkinDestroy'])->name('destroy');
        });

        // Feedback
        Route::prefix('/{review}/feedback')->name('feedback.')->group(function () {
            Route::post('/',            [PaProbationController::class, 'feedbackStore'])->name('store');
            Route::delete('/{feedback}',[PaProbationController::class, 'feedbackDestroy'])->name('destroy');
        });
    });

    // ── Wellness & Workforce Signals ──────────────────────────────────────────
    Route::prefix('wellness')->name('wellness.')->group(function () {
        Route::get('/',                                        [PaWellnessController::class, 'index'])->name('index');
        Route::post('/generate-score',                         [PaWellnessController::class, 'generateScore'])->name('score');
        Route::post('/generate-all',                           [PaWellnessController::class, 'generateAllScores'])->name('score.all');
        Route::post('/detect-signals',                         [PaWellnessController::class, 'detectSignals'])->name('signals');
        Route::get('/manager-insights',                        [PaWellnessController::class, 'managerInsights'])->name('insights');
        Route::patch('/signals/{signal}/acknowledge',          [PaWellnessController::class, 'acknowledgeSignal'])->name('acknowledge');
    });

    // ── Development & IDP ─────────────────────────────────────────────────────
    Route::prefix('development')->name('development.')->group(function () {
        Route::get('/',         [PaDevelopmentController::class, 'index'])->name('index');
        Route::post('/',        [PaDevelopmentController::class, 'store'])->name('store');
        Route::get('/{plan}',   [PaDevelopmentController::class, 'show'])->name('show');
        Route::patch('/{plan}', [PaDevelopmentController::class, 'update'])->name('update');
        Route::delete('/{plan}',[PaDevelopmentController::class, 'destroy'])->name('destroy');

        // Activities
        Route::prefix('/{plan}/activities')->name('activities.')->group(function () {
            Route::post('/',               [PaDevelopmentController::class, 'activityStore'])->name('store');
            Route::patch('/{activity}',    [PaDevelopmentController::class, 'activityUpdate'])->name('update');
            Route::delete('/{activity}',   [PaDevelopmentController::class, 'activityDestroy'])->name('destroy');
        });

        // Employee skills
        Route::post('/{plan}/skills',           [PaDevelopmentController::class, 'employeeSkillStore'])->name('skills.store');
        Route::delete('/{plan}/skills/{skill}', [PaDevelopmentController::class, 'employeeSkillDestroy'])->name('skills.destroy');

        // AI
        Route::post('/{plan}/ai-recommendations', [PaDevelopmentController::class, 'generateAiRecommendations'])->name('ai');

        // Skill library
        Route::prefix('/skills-library')->name('library.')->group(function () {
            Route::post('/',         [PaDevelopmentController::class, 'skillStore'])->name('store');
            Route::patch('/{skill}', [PaDevelopmentController::class, 'skillUpdate'])->name('update');
            Route::delete('/{skill}',[PaDevelopmentController::class, 'skillDestroy'])->name('destroy');
        });
    });

    // ── Succession Planning ───────────────────────────────────────────────────
    Route::prefix('succession')->name('succession.')->group(function () {
        Route::get('/',         [PaSuccessionController::class, 'index'])->name('index');
        Route::post('/',        [PaSuccessionController::class, 'store'])->name('store');
        Route::patch('/{plan}', [PaSuccessionController::class, 'update'])->name('update');
        Route::delete('/{plan}',[PaSuccessionController::class, 'destroy'])->name('destroy');

        // Candidates
        Route::prefix('/{plan}/candidates')->name('candidates.')->group(function () {
            Route::post('/',                  [PaSuccessionController::class, 'candidateStore'])->name('store');
            Route::patch('/{candidate}',      [PaSuccessionController::class, 'candidateUpdate'])->name('update');
            Route::delete('/{candidate}',     [PaSuccessionController::class, 'candidateDestroy'])->name('destroy');
            Route::post('/{candidate}/score', [PaSuccessionController::class, 'scoreCandidate'])->name('score');
        });

        // AI
        Route::post('/{plan}/analysis',      [PaSuccessionController::class, 'generatePlanAnalysis'])->name('analysis');
        Route::post('/high-potentials',       [PaSuccessionController::class, 'identifyHighPotentials'])->name('high-potentials');
    });

    // ── Talent Growth Intelligence ────────────────────────────────────────────
    Route::prefix('talent-growth')->name('talent.')->group(function () {
        Route::get('/',                                                [PaTalentGrowthController::class, 'index'])->name('index');
        Route::post('/build-all',                                      [PaTalentGrowthController::class, 'buildAllProfiles'])->name('build.all');
        Route::post('/build/{employee}',                               [PaTalentGrowthController::class, 'buildProfile'])->name('build');
        Route::get('/skill-gaps/{employee}',                           [PaTalentGrowthController::class, 'skillGaps'])->name('skill-gaps');
        Route::post('/recommendations',                                [PaTalentGrowthController::class, 'generateRecommendations'])->name('recommendations.generate');
        Route::patch('/recommendations/{recommendation}',              [PaTalentGrowthController::class, 'recommendationUpdate'])->name('recommendations.update');
        Route::delete('/recommendations/{recommendation}',             [PaTalentGrowthController::class, 'recommendationDestroy'])->name('recommendations.destroy');
        Route::get('/succession-recommendation/{employee}',            [PaTalentGrowthController::class, 'successionRecommendation'])->name('succession-rec');
    });

    // ── Policy Enforcement Engine ─────────────────────────────────────────────
    Route::prefix('policy')->name('policy.')->group(function () {
        Route::get('/',                                              [PaPolicyController::class, 'index'])->name('index');

        // Policy CRUD
        Route::post('/policies',                                     [PaPolicyController::class, 'policyStore'])->name('store');
        Route::patch('/policies/{policy}',                           [PaPolicyController::class, 'policyUpdate'])->name('update');
        Route::delete('/policies/{policy}',                          [PaPolicyController::class, 'policyDestroy'])->name('destroy');

        // Violation management
        Route::post('/violations/{violation}/escalate',              [PaPolicyController::class, 'escalateViolation'])->name('violations.escalate');
        Route::post('/violations/{violation}/exempt',                [PaPolicyController::class, 'exemptViolation'])->name('violations.exempt');
        Route::patch('/violations/{violation}/resolve',              [PaPolicyController::class, 'resolveViolation'])->name('violations.resolve');

        // Exceptions
        Route::post('/exceptions',                                   [PaPolicyController::class, 'exceptionStore'])->name('exceptions.store');
        Route::delete('/exceptions/{exception}',                     [PaPolicyController::class, 'exceptionDestroy'])->name('exceptions.destroy');

        // Escalations
        Route::patch('/escalations/{escalation}/acknowledge',        [PaPolicyController::class, 'acknowledgeEscalation'])->name('escalations.acknowledge');
        Route::patch('/escalations/{escalation}/resolve',            [PaPolicyController::class, 'resolveEscalation'])->name('escalations.resolve');

        // Compliance scoring + violation detection
        Route::post('/compliance/compute-all',                       [PaPolicyController::class, 'computeAllCompliance'])->name('compliance.compute');
        Route::post('/detect',                                       [PaPolicyController::class, 'detectViolations'])->name('detect');
    });

    // ── Performance Improvement Plans ────────────────────────────────────────
    Route::prefix('pip')->name('pip.')->group(function () {
        Route::get('/',         [PaPipController::class, 'index'])->name('index');
        Route::post('/',        [PaPipController::class, 'store'])->name('store');
        Route::get('/{pip}',    [PaPipController::class, 'show'])->name('show');
        Route::patch('/{pip}',  [PaPipController::class, 'update'])->name('update');
        Route::delete('/{pip}', [PaPipController::class, 'destroy'])->name('destroy');

        // Objectives
        Route::post('/{pip}/objectives',              [PaPipController::class, 'objectiveStore'])->name('objectives.store');
        Route::patch('/objectives/{objective}',       [PaPipController::class, 'objectiveUpdate'])->name('objectives.update');
        Route::delete('/objectives/{objective}',      [PaPipController::class, 'objectiveDestroy'])->name('objectives.destroy');

        // Progress logging
        Route::post('/{pip}/progress',                [PaPipController::class, 'logProgress'])->name('progress.log');

        // Reviews
        Route::post('/{pip}/reviews',                 [PaPipController::class, 'reviewStore'])->name('reviews.store');
        Route::patch('/reviews/{review}',             [PaPipController::class, 'reviewUpdate'])->name('reviews.update');
        Route::delete('/reviews/{review}',            [PaPipController::class, 'reviewDestroy'])->name('reviews.destroy');

        // AI
        Route::post('/{pip}/coaching',                [PaPipController::class, 'generateCoaching'])->name('coaching');
        Route::post('/{pip}/outcome-recommendation',  [PaPipController::class, 'recommendOutcome'])->name('outcome');
        Route::patch('/{pip}/close-out',              [PaPipController::class, 'closeOut'])->name('close');
    });

    // ── Calibration & Approval Workflows ─────────────────────────────────────
    Route::prefix('calibration')->name('calibration.')->group(function () {
        Route::get('/', [PaCalibrationController::class, 'index'])->name('index');

        // Sessions
        Route::post('/sessions',                          [PaCalibrationController::class, 'sessionStore'])->name('sessions.store');
        Route::patch('/sessions/{session}',               [PaCalibrationController::class, 'sessionUpdate'])->name('sessions.update');
        Route::delete('/sessions/{session}',              [PaCalibrationController::class, 'sessionDestroy'])->name('sessions.destroy');
        Route::patch('/sessions/{session}/finalize',      [PaCalibrationController::class, 'sessionFinalize'])->name('sessions.finalize');
        Route::get('/sessions/{session}/summary',         [PaCalibrationController::class, 'sessionSummary'])->name('sessions.summary');

        // Participants
        Route::post('/sessions/{session}/participants',               [PaCalibrationController::class, 'participantStore'])->name('participants.store');
        Route::patch('/participants/{participant}',                   [PaCalibrationController::class, 'participantUpdate'])->name('participants.update');
        Route::delete('/participants/{participant}',                  [PaCalibrationController::class, 'participantDestroy'])->name('participants.destroy');

        // Approval workflows
        Route::post('/workflows',                         [PaCalibrationController::class, 'workflowStore'])->name('workflows.store');
        Route::patch('/workflows/{workflow}/toggle',      [PaCalibrationController::class, 'workflowToggle'])->name('workflows.toggle');
        Route::delete('/workflows/{workflow}',            [PaCalibrationController::class, 'workflowDestroy'])->name('workflows.destroy');

        // Approval requests
        Route::post('/requests',                          [PaCalibrationController::class, 'requestStore'])->name('requests.store');
        Route::post('/requests/{approvalRequest}/action', [PaCalibrationController::class, 'requestAction'])->name('requests.action');
        Route::delete('/requests/{approvalRequest}',      [PaCalibrationController::class, 'requestDestroy'])->name('requests.destroy');
    });

    // ── Reports & Scheduled Reports ───────────────────────────────────────────
    Route::get('/analytics', [PaAnalyticsController::class, 'index'])->name('analytics');

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',                          [PaReportController::class, 'reports'])->name('index');
        Route::get('/executive',                 [PaReportController::class, 'executiveDashboard'])->name('executive');
        Route::get('/generate/{key}',            [PaReportController::class, 'generateReport'])->name('generate');
        Route::post('/queue/{key}',              [PaReportController::class, 'queueReport'])->name('queue');
        Route::get('/export/{key}/{format}',     [PaReportController::class, 'exportReport'])->name('export');
        Route::get('/scheduled',                 [PaReportController::class, 'scheduledReports'])->name('scheduled');
        Route::post('/scheduled',                [PaReportController::class, 'storeScheduledReport'])->name('scheduled.store');
        Route::patch('/scheduled/{scheduledReport}',  [PaReportController::class, 'updateScheduledReport'])->name('scheduled.update');
        Route::delete('/scheduled/{scheduledReport}', [PaReportController::class, 'destroyScheduledReport'])->name('scheduled.destroy');
    });

    // ── Appraisal Review (self-assessment & manager approval) ─────────────────
    Route::prefix('appraisal-reviews')->name('appraisal-review.')->group(function () {
        Route::get('/',                             [PaAppraisalReviewController::class, 'index'])->name('index');
        Route::post('/{snapshot}/submit',           [PaAppraisalReviewController::class, 'submit'])->name('submit');
        Route::patch('/{snapshot}/manager-score',   [PaAppraisalReviewController::class, 'managerScore'])->name('manager-score');
        Route::post('/{snapshot}/approve',          [PaAppraisalReviewController::class, 'approve'])->name('approve');
        Route::post('/{snapshot}/reject',           [PaAppraisalReviewController::class, 'reject'])->name('reject');
    });

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::get('/settings',  [PaSettingsController::class, 'index'])->name('settings.index');
    Route::patch('/settings',[PaSettingsController::class, 'update'])->name('settings.update');
});
