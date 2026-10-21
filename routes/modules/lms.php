<?php

use App\Modules\HR\LMS\Http\Controllers\LmsAdminController;
use App\Modules\HR\LMS\Http\Controllers\LmsAiCourseBuilderController;
use App\Modules\HR\LMS\Http\Controllers\LmsMaterialController;
use App\Modules\HR\LMS\Http\Controllers\LmsCatalogController;
use App\Modules\HR\LMS\Http\Controllers\LmsCourseBuilderController;
use App\Modules\HR\LMS\Http\Controllers\LmsMyLearningController;
use App\Modules\HR\LMS\Http\Controllers\LmsMarkingController;
use App\Modules\HR\LMS\Http\Controllers\LmsQuestionnaireAdminController;
use App\Modules\HR\LMS\Http\Controllers\LmsQuestionnaireController;
use App\Modules\HR\LMS\Http\Controllers\LmsUnderstandingController;
use App\Modules\HR\LMS\Http\Controllers\LmsAiRecommendationController;
use App\Modules\HR\LMS\Http\Controllers\LmsSkillsController;
use App\Modules\HR\LMS\Http\Controllers\LmsComplianceController;
use App\Modules\HR\LMS\Http\Controllers\LmsPolicyGuardController;
use App\Modules\HR\LMS\Http\Controllers\LmsCertificationController;
use App\Modules\HR\LMS\Http\Controllers\LmsEffectivenessController;
use App\Modules\HR\LMS\Http\Controllers\LmsAiChatController;
use App\Modules\HR\LMS\Http\Controllers\LmsOnboardingTrainingController;
use App\Modules\HR\LMS\Http\Controllers\LmsEmployeeDashboardController;
use App\Modules\HR\LMS\Http\Controllers\LmsExecutiveDashboardController;
use App\Modules\HR\LMS\Http\Controllers\LmsManagerDashboardController;
use App\Modules\HR\LMS\Http\Controllers\LmsPaTrainingController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsQctoAdminController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsAssessorController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsSdfController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsLearnershipController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsWspAtrController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsBbeeController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsPoeController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsModerationController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsNlrdController;
use App\Modules\HR\LMS\Http\Controllers\Qcto\LmsAqpController;
use App\Modules\HR\LMS\Http\Controllers\LmsScheduledReportController;
use App\Modules\HR\LMS\Http\Controllers\LmsReportController;
use App\Modules\HR\LMS\Http\Controllers\LmsAnalyticsController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'module.active:learning_management', 'module.tier:learning_management'])->prefix('lms')->name('lms.')->group(function () {

    // ── Navigation hubs — role-filtered feature-card landing pages ────────────
    foreach (['my-learning-hub' => 'my_learning', 'courses-hub' => 'courses', 'skills-hub' => 'skills', 'compliance-hub' => 'compliance'] as $hubPath => $group) {
        Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
            ->defaults('module', 'learning_management')->defaults('group', $group)->name('hub.' . $group);
    }

    // ── Employee catalog & learning ─────────────────────────────────────────
    Route::get('/my-dashboard',     [LmsEmployeeDashboardController::class, 'index'])->name('my-dashboard');
    Route::get('/manager-dashboard',[LmsManagerDashboardController::class, 'index'])
        ->middleware('role:manager|senior manager|HR|super admin|administrator|executive')
        ->name('manager-dashboard');
    Route::get('/executive-dashboard', [LmsExecutiveDashboardController::class, 'index'])
        ->middleware('role:executive|super admin|administrator|HR')
        ->name('executive-dashboard');
    Route::get('/catalog', [LmsCatalogController::class, 'index'])->name('catalog');
    Route::get('/my-learning', [LmsMyLearningController::class, 'index'])->name('my-learning');
    Route::get('/courses/{course}', [LmsCatalogController::class, 'show'])->name('courses.show');
    Route::get('/courses/{course}/learn/{lesson}', [LmsCatalogController::class, 'learn'])->name('courses.learn');
    Route::post('/courses/{course}/enroll', [LmsCatalogController::class, 'enroll'])->name('courses.enroll');
    Route::patch('/enrollments/{enrollment}/progress', [LmsCatalogController::class, 'updateProgress'])->name('enrollments.progress');
    Route::post('/enrollments/{enrollment}/quiz', [LmsCatalogController::class, 'submitQuiz'])->name('enrollments.quiz');
    Route::post('/enrollments/{enrollment}/lessons/{lesson}/complete', [LmsCatalogController::class, 'completeLesson'])->name('enrollments.lessons.complete');

    // ── Questionnaires (learner) ────────────────────────────────────────────
    // ── Understanding score: time tracking ─────────────────────────────────
    Route::post('/enrollments/{enrollment}/track-time', [LmsUnderstandingController::class, 'trackTime'])->name('enrollments.track-time');

    // ── Learning plan (learner) ─────────────────────────────────────────────
    Route::get('/my-learning-plan', [LmsAiRecommendationController::class, 'myLearningPlan'])->name('my-learning-plan');
    Route::patch('/learning-plan-items/{item}/status', [LmsAiRecommendationController::class, 'updateItemStatus'])->name('learning-plan-items.status');
    Route::post('/enrollments/{enrollment}/revision-plan', [LmsAiRecommendationController::class, 'generateRevisionPlan'])->name('enrollments.revision-plan');
    Route::patch('/ai-recommendations/{recommendation}/status', [LmsAiRecommendationController::class, 'updateStatus'])->name('ai-recommendations.status');

    Route::get('/questionnaires/{questionnaire}', [LmsQuestionnaireController::class, 'show'])->name('questionnaires.show');
    Route::post('/questionnaires/{questionnaire}/start', [LmsQuestionnaireController::class, 'start'])->name('questionnaires.start');
    Route::post('/questionnaires/{questionnaire}/attempts/{attempt}/response', [LmsQuestionnaireController::class, 'saveResponse'])->name('questionnaires.attempts.response');
    Route::post('/questionnaires/{questionnaire}/attempts/{attempt}/submit', [LmsQuestionnaireController::class, 'submit'])->name('questionnaires.attempts.submit');
    Route::get('/questionnaires/{questionnaire}/attempts/{attempt}/result', [LmsQuestionnaireController::class, 'result'])->name('questionnaires.result');
    Route::get('/questionnaires/{questionnaire}/attempts/{attempt}/responses/{response}/download', [LmsQuestionnaireController::class, 'downloadFile'])->name('questionnaires.attempts.response.download');

    // ── Admin ───────────────────────────────────────────────────────────────
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [LmsAdminController::class, 'dashboard'])->name('dashboard');

        // Courses
        Route::get('/courses', [LmsAdminController::class, 'courses'])->name('courses');
        Route::post('/courses', [LmsAdminController::class, 'storeCourse'])->name('courses.store');
        Route::patch('/courses/{course}', [LmsAdminController::class, 'updateCourse'])->name('courses.update');
        Route::delete('/courses/{course}', [LmsAdminController::class, 'destroyCourse'])->name('courses.destroy');
        Route::patch('/courses/{course}/publish', [LmsAdminController::class, 'publishCourse'])->name('courses.publish');

        // Course contents (legacy — kept for backward compat)
        Route::post('/courses/{course}/contents', [LmsAdminController::class, 'storeContent'])->name('courses.contents.store');
        Route::delete('/courses/{course}/contents/{content}', [LmsAdminController::class, 'destroyContent'])->name('courses.contents.destroy');

        // Quiz questions
        Route::post('/courses/{course}/questions', [LmsAdminController::class, 'storeQuestion'])->name('courses.questions.store');
        Route::delete('/courses/{course}/questions/{question}', [LmsAdminController::class, 'destroyQuestion'])->name('courses.questions.destroy');

        // ── Course Builder ────────────────────────────────────────────────────
        Route::get('/courses/{course}/builder', [LmsCourseBuilderController::class, 'show'])->name('courses.builder');

        // Modules
        Route::post('/courses/{course}/modules', [LmsCourseBuilderController::class, 'storeModule'])->name('courses.modules.store');
        Route::patch('/courses/{course}/modules/{module}', [LmsCourseBuilderController::class, 'updateModule'])->name('courses.modules.update');
        Route::delete('/courses/{course}/modules/{module}', [LmsCourseBuilderController::class, 'destroyModule'])->name('courses.modules.destroy');
        Route::post('/courses/{course}/modules/reorder', [LmsCourseBuilderController::class, 'reorderModules'])->name('courses.modules.reorder');

        // Lessons
        Route::post('/courses/{course}/modules/{module}/lessons', [LmsCourseBuilderController::class, 'storeLesson'])->name('courses.modules.lessons.store');
        Route::patch('/courses/{course}/lessons/{lesson}', [LmsCourseBuilderController::class, 'updateLesson'])->name('courses.lessons.update');
        Route::delete('/courses/{course}/lessons/{lesson}', [LmsCourseBuilderController::class, 'destroyLesson'])->name('courses.lessons.destroy');
        Route::post('/courses/{course}/modules/{module}/lessons/reorder', [LmsCourseBuilderController::class, 'reorderLessons'])->name('courses.modules.lessons.reorder');

        // Materials (file upload)
        Route::post('/courses/{course}/lessons/{lesson}/materials', [LmsCourseBuilderController::class, 'uploadMaterial'])->name('courses.lessons.materials.upload');
        Route::delete('/courses/{course}/lessons/{lesson}/materials/{material}', [LmsCourseBuilderController::class, 'destroyMaterial'])->name('courses.lessons.materials.destroy');

        // Prerequisites
        Route::post('/courses/{course}/prerequisites', [LmsCourseBuilderController::class, 'storePrerequisite'])->name('courses.prerequisites.store');
        Route::delete('/courses/{course}/prerequisites/{prerequisite}', [LmsCourseBuilderController::class, 'destroyPrerequisite'])->name('courses.prerequisites.destroy');

        // Settings
        Route::patch('/courses/{course}/settings', [LmsCourseBuilderController::class, 'updateSettings'])->name('courses.settings');

        // AI Course Builder — dispatches a background job; user is notified on completion
        Route::post('/courses/{course}/ai-generate', [LmsAiCourseBuilderController::class, 'generate'])->name('courses.ai.generate');

        // Enrollments
        Route::get('/enrollments', [LmsAdminController::class, 'enrollments'])->name('enrollments');

        // Categories
        Route::get('/categories', [LmsAdminController::class, 'categories'])->name('categories');
        Route::post('/categories', [LmsAdminController::class, 'storeCategory'])->name('categories.store');
        Route::patch('/categories/{category}', [LmsAdminController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [LmsAdminController::class, 'destroyCategory'])->name('categories.destroy');

        // Learning Paths
        Route::get('/learning-paths', [LmsAdminController::class, 'learningPaths'])->name('learning-paths');
        Route::post('/learning-paths', [LmsAdminController::class, 'storeLearningPath'])->name('learning-paths.store');
        Route::patch('/learning-paths/{path}', [LmsAdminController::class, 'updateLearningPath'])->name('learning-paths.update');
        Route::delete('/learning-paths/{path}', [LmsAdminController::class, 'destroyLearningPath'])->name('learning-paths.destroy');
        Route::patch('/learning-paths/{path}/publish', [LmsAdminController::class, 'publishLearningPath'])->name('learning-paths.publish');
        Route::post('/learning-paths/{path}/courses', [LmsAdminController::class, 'addCourseToPath'])->name('learning-paths.courses.add');
        Route::delete('/learning-paths/{path}/courses/{course}', [LmsAdminController::class, 'removeCourseFromPath'])->name('learning-paths.courses.remove');

        // ── Questionnaire Admin ────────────────────────────────────────────
        Route::get('/questionnaires', [LmsQuestionnaireAdminController::class, 'index'])->name('questionnaires');
        Route::post('/questionnaires', [LmsQuestionnaireAdminController::class, 'store'])->name('questionnaires.store');
        Route::patch('/questionnaires/{questionnaire}', [LmsQuestionnaireAdminController::class, 'update'])->name('questionnaires.update');
        Route::delete('/questionnaires/{questionnaire}', [LmsQuestionnaireAdminController::class, 'destroy'])->name('questionnaires.destroy');
        Route::get('/questionnaires/{questionnaire}/builder', [LmsQuestionnaireAdminController::class, 'builder'])->name('questionnaires.builder');

        // Questions within questionnaire
        Route::post('/questionnaires/{questionnaire}/questions', [LmsQuestionnaireAdminController::class, 'storeQuestion'])->name('questionnaires.questions.store');
        Route::patch('/questionnaires/{questionnaire}/questions/{question}', [LmsQuestionnaireAdminController::class, 'updateQuestion'])->name('questionnaires.questions.update');
        Route::delete('/questionnaires/{questionnaire}/questions/{question}', [LmsQuestionnaireAdminController::class, 'destroyQuestion'])->name('questionnaires.questions.destroy');
        Route::post('/questionnaires/{questionnaire}/questions/reorder', [LmsQuestionnaireAdminController::class, 'reorderQuestions'])->name('questionnaires.questions.reorder');
        Route::post('/questionnaires/{questionnaire}/import-from-bank', [LmsQuestionnaireAdminController::class, 'importFromBank'])->name('questionnaires.import-from-bank');

        // Assignments
        Route::post('/questionnaires/{questionnaire}/assignments', [LmsQuestionnaireAdminController::class, 'storeAssignment'])->name('questionnaires.assignments.store');
        Route::delete('/questionnaires/{questionnaire}/assignments/{assignment}', [LmsQuestionnaireAdminController::class, 'destroyAssignment'])->name('questionnaires.assignments.destroy');

        // Question Banks
        Route::get('/question-banks', [LmsQuestionnaireAdminController::class, 'banks'])->name('question-banks');
        Route::post('/question-banks', [LmsQuestionnaireAdminController::class, 'storeBank'])->name('question-banks.store');
        Route::patch('/question-banks/{bank}', [LmsQuestionnaireAdminController::class, 'updateBank'])->name('question-banks.update');
        Route::delete('/question-banks/{bank}', [LmsQuestionnaireAdminController::class, 'destroyBank'])->name('question-banks.destroy');
        Route::post('/question-banks/{bank}/questions', [LmsQuestionnaireAdminController::class, 'storeBankQuestion'])->name('question-banks.questions.store');
        Route::delete('/question-banks/{bank}/questions/{question}', [LmsQuestionnaireAdminController::class, 'destroyBankQuestion'])->name('question-banks.questions.destroy');

        // ── Understanding Score Engine ─────────────────────────────────────
        Route::get('/understanding', [LmsUnderstandingController::class, 'index'])->name('understanding');
        Route::get('/understanding/{score}', [LmsUnderstandingController::class, 'show'])->name('understanding.show');
        Route::post('/understanding/enrollments/{enrollment}/calculate', [LmsUnderstandingController::class, 'calculate'])->name('understanding.calculate');
        Route::post('/understanding/{score}/confirm', [LmsUnderstandingController::class, 'confirm'])->name('understanding.confirm');
        Route::post('/understanding/enrollments/{enrollment}/report', [LmsUnderstandingController::class, 'generateReport'])->name('understanding.report');

        // ── Marking queue ──────────────────────────────────────────────────
        Route::get('/marking', [LmsMarkingController::class, 'index'])->name('marking');
        Route::get('/marking/{attempt}', [LmsMarkingController::class, 'show'])->name('marking.show');
        Route::post('/marking/{attempt}/markings/{marking}', [LmsMarkingController::class, 'markResponse'])->name('marking.response');
        Route::post('/marking/{attempt}/assign', [LmsMarkingController::class, 'assign'])->name('marking.assign');
        Route::post('/marking/{attempt}/finalize', [LmsMarkingController::class, 'finalize'])->name('marking.finalize');
        Route::get('/marking/{attempt}/responses/{response}/download', [LmsMarkingController::class, 'downloadResponseFile'])->name('marking.response.download');

        // ── AI Recommendations & Learning Plans (admin) ───────────────────
        Route::get('/ai-recommendations', [LmsAiRecommendationController::class, 'adminIndex'])->name('ai-recommendations');
        Route::post('/ai-recommendations/generate', [LmsAiRecommendationController::class, 'generateForEmployee'])->name('ai-recommendations.generate');
        Route::patch('/ai-recommendations/{recommendation}/status', [LmsAiRecommendationController::class, 'updateStatus'])->name('ai-recommendations.admin.status');
        Route::delete('/ai-recommendations/{recommendation}', [LmsAiRecommendationController::class, 'destroyRecommendation'])->name('ai-recommendations.destroy');

        // ── Skills Library ─────────────────────────────────────────────────
        Route::get('/skills', [LmsSkillsController::class, 'libraryIndex'])->name('skills');
        Route::post('/skills', [LmsSkillsController::class, 'storeSkill'])->name('skills.store');
        Route::patch('/skills/{skill}', [LmsSkillsController::class, 'updateSkill'])->name('skills.update');
        Route::delete('/skills/{skill}', [LmsSkillsController::class, 'destroySkill'])->name('skills.destroy');

        // Skill → job title mappings
        Route::post('/skills/{skill}/job-titles', [LmsSkillsController::class, 'mapJobTitle'])->name('skills.job-titles.map');
        Route::delete('/skills/{skill}/job-titles/{positionId}', [LmsSkillsController::class, 'unmapJobTitle'])->name('skills.job-titles.unmap');

        // Skill → course mappings
        Route::post('/skills/{skill}/courses', [LmsSkillsController::class, 'mapCourse'])->name('skills.courses.map');
        Route::delete('/skills/{skill}/courses/{courseId}', [LmsSkillsController::class, 'unmapCourse'])->name('skills.courses.unmap');

        // Skill → question mappings
        Route::post('/skills/{skill}/questions', [LmsSkillsController::class, 'mapQuestion'])->name('skills.questions.map');
        Route::delete('/skills/{skill}/questions/{questionId}', [LmsSkillsController::class, 'unmapQuestion'])->name('skills.questions.unmap');

        // Employee skill management
        Route::patch('/employee-skills/{userId}/{skillId}', [LmsSkillsController::class, 'updateEmployeeSkill'])->name('employee-skills.update');
        Route::post('/employee-skills/{userId}/calculate-gaps', [LmsSkillsController::class, 'calculateGaps'])->name('employee-skills.gaps');
        Route::post('/employee-skills/sync-enrollment', [LmsSkillsController::class, 'syncSkillsAfterEnrollment'])->name('employee-skills.sync');

        // Skills Matrix
        Route::get('/skills-matrix', [LmsSkillsController::class, 'matrixIndex'])->name('skills-matrix');

        // Succession Readiness
        Route::get('/succession', [LmsSkillsController::class, 'successionIndex'])->name('succession');

        // ── Compliance Rules ───────────────────────────────────────────────
        Route::get('/compliance', [LmsComplianceController::class, 'dashboard'])->name('compliance');
        Route::post('/compliance/run-checks', [LmsComplianceController::class, 'runChecks'])->name('compliance.run-checks');
        Route::post('/compliance/reports', [LmsComplianceController::class, 'generateReport'])->name('compliance.reports.generate');

        Route::get('/compliance/rules', [LmsComplianceController::class, 'rulesIndex'])->name('compliance.rules');
        Route::post('/compliance/rules', [LmsComplianceController::class, 'storeRule'])->name('compliance.rules.store');
        Route::patch('/compliance/rules/{rule}', [LmsComplianceController::class, 'updateRule'])->name('compliance.rules.update');
        Route::delete('/compliance/rules/{rule}', [LmsComplianceController::class, 'destroyRule'])->name('compliance.rules.destroy');
        Route::post('/compliance/rules/{rule}/assign', [LmsComplianceController::class, 'assignRule'])->name('compliance.rules.assign');

        Route::patch('/compliance/assignments/{assignment}/waive', [LmsComplianceController::class, 'waveAssignment'])->name('compliance.assignments.waive');
        Route::patch('/compliance/escalations/{escalation}/acknowledge', [LmsComplianceController::class, 'acknowledgeEscalation'])->name('compliance.escalations.acknowledge');

        // ── Policy Guard ───────────────────────────────────────────────────
        Route::get('/policy-violations', [LmsPolicyGuardController::class, 'violations'])->name('policy-violations');
        Route::patch('/policy-violations/{violation}/resolve', [LmsPolicyGuardController::class, 'resolveViolation'])->name('policy-violations.resolve');
        Route::post('/policy-exceptions', [LmsPolicyGuardController::class, 'requestException'])->name('policy-exceptions.request');
        Route::patch('/policy-exceptions/{exception}/decide', [LmsPolicyGuardController::class, 'decideException'])->name('policy-exceptions.decide');

        // ── Certification Intelligence ─────────────────────────────────────
        Route::get('/certifications', [LmsCertificationController::class, 'dashboard'])->name('certifications');
        Route::post('/certifications/run-checks', [LmsCertificationController::class, 'runChecks'])->name('certifications.run-checks');
        Route::post('/certifications/issue', [LmsCertificationController::class, 'issueCertificate'])->name('certifications.issue');

        // Certification programs
        Route::get('/certifications/programs', [LmsCertificationController::class, 'certificationsIndex'])->name('certifications.programs');
        Route::post('/certifications/programs', [LmsCertificationController::class, 'storeCertification'])->name('certifications.store');
        Route::patch('/certifications/programs/{certification}', [LmsCertificationController::class, 'updateCertification'])->name('certifications.update');
        Route::delete('/certifications/programs/{certification}', [LmsCertificationController::class, 'destroyCertification'])->name('certifications.destroy');

        // Employee certifications
        Route::patch('/employee-certifications/{employeeCertification}/revoke', [LmsCertificationController::class, 'revokeCertificate'])->name('certifications.revoke');
        Route::post('/employee-certifications/{employeeCertification}/assign-refresher', [LmsCertificationController::class, 'assignRefresher'])->name('certifications.assign-refresher');
        Route::get('/employee-certifications/{employeeCertification}/log', [LmsCertificationController::class, 'expiryLog'])->name('certifications.expiry-log');

        // Certificate templates
        Route::get('/cert-templates', [LmsCertificationController::class, 'templatesIndex'])->name('cert-templates');
        Route::post('/cert-templates', [LmsCertificationController::class, 'storeTemplate'])->name('cert-templates.store');
        Route::patch('/cert-templates/{template}', [LmsCertificationController::class, 'updateTemplate'])->name('cert-templates.update');
        Route::delete('/cert-templates/{template}', [LmsCertificationController::class, 'destroyTemplate'])->name('cert-templates.destroy');

        // ── Training Effectiveness & ROI ───────────────────────────────────
        Route::get('/effectiveness', [LmsEffectivenessController::class, 'dashboard'])->name('effectiveness');
        Route::get('/effectiveness/{courseId}', [LmsEffectivenessController::class, 'analyse'])->name('effectiveness.analyse');
        Route::post('/effectiveness/report', [LmsEffectivenessController::class, 'generateReport'])->name('effectiveness.report');

        Route::get('/training-costs', [LmsEffectivenessController::class, 'costsIndex'])->name('costs');
        Route::post('/training-costs', [LmsEffectivenessController::class, 'storeCost'])->name('costs.store');
        Route::patch('/training-costs/{cost}', [LmsEffectivenessController::class, 'updateCost'])->name('costs.update');
        Route::delete('/training-costs/{cost}', [LmsEffectivenessController::class, 'destroyCost'])->name('costs.destroy');

        // Scheduled Reports
        Route::get('/scheduled-reports',                      [LmsScheduledReportController::class, 'index'])->name('scheduled-reports');
        Route::post('/scheduled-reports',                     [LmsScheduledReportController::class, 'store'])->name('scheduled-reports.store');
        Route::patch('/scheduled-reports/{scheduledReport}',  [LmsScheduledReportController::class, 'update'])->name('scheduled-reports.update');
        Route::delete('/scheduled-reports/{scheduledReport}', [LmsScheduledReportController::class, 'destroy'])->name('scheduled-reports.destroy');
        Route::post('/scheduled-reports/{scheduledReport}/toggle', [LmsScheduledReportController::class, 'toggle'])->name('scheduled-reports.toggle');

        // BI Analytics
        Route::get('/analytics', [LmsAnalyticsController::class, 'index'])->name('analytics');

        // Reports
        Route::get('/reports',                       [LmsReportController::class, 'index'])->name('reports');
        Route::get('/reports/generate/{key}',        [LmsReportController::class, 'generate'])->name('reports.generate');
        Route::get('/reports/export/{key}/{format}', [LmsReportController::class, 'export'])->name('reports.export');
        Route::post('/reports/queue/{key}',          [LmsReportController::class, 'queue'])->name('reports.queue');

        // ── AI Training Chat (admin / manager / HR views) ──────────────────
        Route::get('/ai-chat', [LmsAiChatController::class, 'index'])->name('ai.index');
        Route::get('/ai-chat/manager', [LmsAiChatController::class, 'managerChat'])
            ->middleware('role:manager|senior manager|team leader|executive|administrator|super admin')
            ->name('ai.manager');
        Route::get('/ai-chat/hr', [LmsAiChatController::class, 'hrChat'])
            ->middleware('role:HR|HR manager|administrator|super admin')
            ->name('ai.hr');

        // ── Onboarding Training Integration ───────────────────────────────
        Route::get('/onboarding',                     [LmsOnboardingTrainingController::class, 'dashboard'])->name('onboarding');
        Route::get('/onboarding/plans/{planId}',      [LmsOnboardingTrainingController::class, 'planDetail'])->name('onboarding.plan');
        Route::get('/onboarding/rules',               [LmsOnboardingTrainingController::class, 'rulesIndex'])->name('onboarding.rules');
        Route::post('/onboarding/rules',              [LmsOnboardingTrainingController::class, 'storeRule'])->name('onboarding.rules.store');
        Route::patch('/onboarding/rules/{rule}',      [LmsOnboardingTrainingController::class, 'updateRule'])->name('onboarding.rules.update');
        Route::delete('/onboarding/rules/{rule}',     [LmsOnboardingTrainingController::class, 'destroyRule'])->name('onboarding.rules.destroy');
        Route::post('/onboarding/assign',             [LmsOnboardingTrainingController::class, 'assignTraining'])->name('onboarding.assign');
        Route::post('/onboarding/sync',               [LmsOnboardingTrainingController::class, 'syncCompletion'])->name('onboarding.sync');
        Route::post('/onboarding/reminders',          [LmsOnboardingTrainingController::class, 'sendReminders'])->name('onboarding.reminders');
        Route::post('/onboarding/check-block',        [LmsOnboardingTrainingController::class, 'checkBlock'])->name('onboarding.check-block');

        // ── PA Training Integration ────────────────────────────────────────
        // Requires BOTH learning_management (outer group) AND performance_appraisal active.
        // Role: lms_pa_admin (full write) | HR | manager | senior manager | executive (read + report).
        Route::prefix('pa-training')
            ->name('pa.')
            ->middleware([
                'module.active:performance_appraisal',
                'role:lms_pa_admin|HR|super admin|administrator|manager|senior manager|executive',
            ])
            ->group(function () {
                // ── Read ──────────────────────────────────────────────────
                Route::get('/',                  [LmsPaTrainingController::class, 'dashboard'])->name('dashboard');
                Route::get('/recommendations',   [LmsPaTrainingController::class, 'recommendations'])->name('recommendations');
                Route::get('/plans',             [LmsPaTrainingController::class, 'devPlans'])->name('plans');
                Route::get('/plans/{planId}',    [LmsPaTrainingController::class, 'planDetail'])->name('plans.detail');
                Route::get('/progress-report',   [LmsPaTrainingController::class, 'progressReport'])->name('progress-report');

                // ── Write — restricted to lms_pa_admin | HR | super admin | administrator ──
                Route::middleware('role:lms_pa_admin|HR|super admin|administrator')->group(function () {
                    Route::post('/recommend',                           [LmsPaTrainingController::class, 'recommend'])->name('recommend');
                    Route::patch('/recommendations/{recommendation}',   [LmsPaTrainingController::class, 'updateRecommendation'])->name('recommendations.update');
                    Route::post('/plans',                               [LmsPaTrainingController::class, 'createPlan'])->name('plans.create');
                    Route::post('/plans/{planId}/track',                [LmsPaTrainingController::class, 'trackPlan'])->name('plans.track');
                    Route::post('/compute-readiness',                   [LmsPaTrainingController::class, 'computeReadiness'])->name('compute-readiness');
                });
            });

        // ── QCTO Compliance Hub ─────────────────────────────────────────────
        Route::middleware('role:super admin|administrator|HR')->group(function () {

            // QCTO Dashboard
            Route::get('/qcto',           [LmsQctoAdminController::class, 'dashboard'])->name('qcto.dashboard');

            // SETAs (managed inline on the dashboard)
            Route::post('/setas',          [LmsQctoAdminController::class, 'setaStore'])->name('qcto.setas.store');
            Route::patch('/setas/{seta}',  [LmsQctoAdminController::class, 'setaUpdate'])->name('qcto.setas.update');
            Route::delete('/setas/{seta}', [LmsQctoAdminController::class, 'setaDestroy'])->name('qcto.setas.destroy');

            // Registered Assessors & Moderators
            Route::get('/registered-persons',                     [LmsAssessorController::class, 'index'])->name('qcto.persons');
            Route::post('/registered-persons',                    [LmsAssessorController::class, 'store'])->name('qcto.persons.store');
            Route::patch('/registered-persons/{person}',          [LmsAssessorController::class, 'update'])->name('qcto.persons.update');
            Route::delete('/registered-persons/{person}',         [LmsAssessorController::class, 'destroy'])->name('qcto.persons.destroy');

            // Skills Development Facilitators
            Route::get('/sdfs',                                   [LmsSdfController::class, 'index'])->name('qcto.sdfs');
            Route::post('/sdfs',                                  [LmsSdfController::class, 'store'])->name('qcto.sdfs.store');
            Route::patch('/sdfs/{sdf}',                           [LmsSdfController::class, 'update'])->name('qcto.sdfs.update');
            Route::delete('/sdfs/{sdf}',                          [LmsSdfController::class, 'destroy'])->name('qcto.sdfs.destroy');

            // Learnerships & Apprenticeships
            Route::get('/learnerships',                           [LmsLearnershipController::class, 'index'])->name('qcto.learnerships');
            Route::post('/learnerships',                          [LmsLearnershipController::class, 'store'])->name('qcto.learnerships.store');
            Route::patch('/learnerships/{learnership}',           [LmsLearnershipController::class, 'update'])->name('qcto.learnerships.update');
            Route::delete('/learnerships/{learnership}',          [LmsLearnershipController::class, 'destroy'])->name('qcto.learnerships.destroy');
            Route::get('/learnerships/{learnership}/enrolments',  [LmsLearnershipController::class, 'enrolments'])->name('qcto.learnerships.enrolments');
            Route::post('/learnerships/{learnership}/enrolments', [LmsLearnershipController::class, 'storeEnrolment'])->name('qcto.learnerships.enrolments.store');
            Route::patch('/learnership-enrolments/{enrolment}',   [LmsLearnershipController::class, 'updateEnrolment'])->name('qcto.enrolments.update');
            Route::get('/learnership-enrolments/{enrolment}/logbook',     [LmsLearnershipController::class, 'logbook'])->name('qcto.enrolments.logbook');
            Route::post('/learnership-enrolments/{enrolment}/logbook',    [LmsLearnershipController::class, 'addLogbookEntry'])->name('qcto.enrolments.logbook.store');
            Route::patch('/learnership-logbook/{entry}/sign-off',         [LmsLearnershipController::class, 'signOffLogbook'])->name('qcto.logbook.signoff');
            Route::get('/learnership-logbook/{entry}/evidence',           [LmsLearnershipController::class, 'downloadLogbookEvidence'])->name('qcto.logbook.evidence.download');
            Route::post('/learnership-enrolments/{enrolment}/absorb',    [LmsLearnershipController::class, 'markAbsorbed'])->name('qcto.enrolments.absorb');

            // WSP / ATR
            Route::get('/wsp-atr',                                [LmsWspAtrController::class, 'index'])->name('qcto.wsp-atr');
            Route::get('/wsp-atr/{wspPlan}',                      [LmsWspAtrController::class, 'show'])->name('qcto.wsp-atr.show');
            Route::post('/wsp-plans',                             [LmsWspAtrController::class, 'store'])->name('qcto.wsp.store');
            Route::patch('/wsp-plans/{wspPlan}',                  [LmsWspAtrController::class, 'update'])->name('qcto.wsp.update');
            Route::post('/wsp-plans/{wspPlan}/items',             [LmsWspAtrController::class, 'storeItem'])->name('qcto.wsp.items.store');
            Route::delete('/wsp-items/{item}',                    [LmsWspAtrController::class, 'destroyItem'])->name('qcto.wsp.items.destroy');
            Route::get('/wsp-plans/{wspPlan}/suggest-items',      [LmsWspAtrController::class, 'suggestItems'])->name('qcto.wsp.suggest');
            Route::get('/wsp-plans/{wspPlan}/export',             [LmsWspAtrController::class, 'exportWsp'])->name('qcto.wsp.export');
            Route::post('/wsp-plans/{wspPlan}/atr',               [LmsWspAtrController::class, 'storeAtr'])->name('qcto.atr.store');
            Route::patch('/atr-reports/{atrReport}',              [LmsWspAtrController::class, 'updateAtr'])->name('qcto.atr.update');
            Route::get('/atr-reports/{atrReport}/export',         [LmsWspAtrController::class, 'exportAtr'])->name('qcto.atr.export');

            // B-BBEE Skills Development
            Route::get('/bbee',                                   [LmsBbeeController::class, 'dashboard'])->name('qcto.bbee');
            Route::post('/bbee/payroll',                          [LmsBbeeController::class, 'updatePayroll'])->name('qcto.bbee.payroll');

            // Moderation Reports
            Route::get('/moderation',                             [LmsModerationController::class, 'index'])->name('qcto.moderation');
            Route::post('/moderation',                            [LmsModerationController::class, 'store'])->name('qcto.moderation.store');
            Route::patch('/moderation/{report}',                  [LmsModerationController::class, 'update'])->name('qcto.moderation.update');
            Route::post('/moderation/{report}/acknowledge',       [LmsModerationController::class, 'acknowledge'])->name('qcto.moderation.acknowledge');
            Route::delete('/moderation/{report}',                 [LmsModerationController::class, 'destroy'])->name('qcto.moderation.destroy');

            // NLRD Submissions
            Route::get('/nlrd',                                   [LmsNlrdController::class, 'index'])->name('qcto.nlrd');
            Route::post('/nlrd',                                  [LmsNlrdController::class, 'store'])->name('qcto.nlrd.store');
            Route::patch('/nlrd/{submission}',                    [LmsNlrdController::class, 'update'])->name('qcto.nlrd.update');
            Route::post('/nlrd/{submission}/validate',            [LmsNlrdController::class, 'validate'])->name('qcto.nlrd.validate');
            Route::post('/nlrd/{submission}/resubmit',            [LmsNlrdController::class, 'resubmit'])->name('qcto.nlrd.resubmit');
            Route::get('/nlrd/{submission}/export',               [LmsNlrdController::class, 'export'])->name('qcto.nlrd.export');
            Route::delete('/nlrd/{submission}',                   [LmsNlrdController::class, 'destroy'])->name('qcto.nlrd.destroy');

            // PoE Admin
            Route::get('/poe-admin',                              [LmsPoeController::class, 'adminIndex'])->name('qcto.poe.admin');
            Route::post('/portfolios/{portfolio}/assign-assessor', [LmsPoeController::class, 'assignAssessor'])->name('qcto.poe.assign');
            Route::post('/portfolios/{portfolio}/assess',          [LmsPoeController::class, 'assess'])->name('qcto.poe.assess');
            Route::post('/portfolios/{portfolio}/moderate',        [LmsPoeController::class, 'moderate'])->name('qcto.poe.moderate');

            // AQP & EISA (QCTO Occupational Qualifications)
            Route::get('/aqp',                                    [LmsAqpController::class, 'index'])->name('qcto.aqp');
            Route::post('/aqp',                                   [LmsAqpController::class, 'store'])->name('qcto.aqp.store');
            Route::patch('/aqp/{aqp}',                            [LmsAqpController::class, 'update'])->name('qcto.aqp.update');
            Route::delete('/aqp/{aqp}',                           [LmsAqpController::class, 'destroy'])->name('qcto.aqp.destroy');
            Route::post('/eisa',                                  [LmsAqpController::class, 'storeEisa'])->name('qcto.eisa.store');
            Route::patch('/eisa/{eisa}',                          [LmsAqpController::class, 'updateEisa'])->name('qcto.eisa.update');
            Route::delete('/eisa/{eisa}',                         [LmsAqpController::class, 'destroyEisa'])->name('qcto.eisa.destroy');
        });
    });

    // ── Learner PoE ────────────────────────────────────────────────────────────
    Route::get('/my-portfolios',                                   [LmsPoeController::class, 'myPortfolios'])->name('poe.my');
    Route::post('/portfolios',                                     [LmsPoeController::class, 'createPortfolio'])->name('poe.create');
    Route::get('/portfolios/{portfolio}',                          [LmsPoeController::class, 'showPortfolio'])->name('poe.show');
    Route::post('/portfolios/{portfolio}/declare',                 [LmsPoeController::class, 'declare'])->name('poe.declare');
    Route::post('/portfolios/{portfolio}/submit',                  [LmsPoeController::class, 'submit'])->name('poe.submit');
    Route::post('/portfolios/{portfolio}/sections',                [LmsPoeController::class, 'storeSection'])->name('poe.sections.store');
    Route::patch('/poe-sections/{section}',                        [LmsPoeController::class, 'updateSection'])->name('poe.sections.update');
    Route::delete('/poe-sections/{section}',                       [LmsPoeController::class, 'destroySection'])->name('poe.sections.destroy');
    Route::post('/poe-sections/{section}/evidence',                [LmsPoeController::class, 'uploadEvidence'])->name('poe.evidence.upload');
    Route::get('/poe-evidence/{evidence}/download',               [LmsPoeController::class, 'downloadEvidence'])->name('poe.evidence.download');
    Route::delete('/poe-evidence/{evidence}',                      [LmsPoeController::class, 'destroyEvidence'])->name('poe.evidence.destroy');

    // ── Material file serving (auth + enrollment check handled in controller) ─
    Route::get('/materials/{material}', [LmsMaterialController::class, 'serve'])->name('materials.serve');

    // Compliance action check (available to authenticated users)
    Route::post('/compliance/check-action', [LmsComplianceController::class, 'checkAction'])->name('compliance.check-action');

    // ── AI Training Chat API (all authenticated LMS users) ─────────────────
    Route::get('/ai/chat', [LmsAiChatController::class, 'index'])->name('ai.chat.page');
    Route::post('/ai/chat', [LmsAiChatController::class, 'chat'])->name('ai.chat');
    Route::post('/ai/policy-chat', [LmsAiChatController::class, 'policyChat'])->name('ai.policy-chat');
    Route::post('/ai/compliance-chat', [LmsAiChatController::class, 'complianceChat'])->name('ai.compliance-chat');
    Route::post('/ai/skills-gap-chat', [LmsAiChatController::class, 'skillsGapChat'])->name('ai.skills-gap-chat');
    Route::post('/ai/summarise', [LmsAiChatController::class, 'summarise'])->name('ai.summarise');
    Route::post('/ai/study-guide', [LmsAiChatController::class, 'studyGuide'])->name('ai.study-guide');
    Route::post('/ai/practice-quiz', [LmsAiChatController::class, 'practiceQuiz'])->name('ai.practice-quiz');
    Route::get('/ai/history', [LmsAiChatController::class, 'history'])->name('ai.history');
    Route::get('/ai/sessions', [LmsAiChatController::class, 'sessions'])->name('ai.sessions');
    Route::post('/ai/archive', [LmsAiChatController::class, 'archiveSession'])->name('ai.archive');
    Route::get('/ai/artefacts', [LmsAiChatController::class, 'artefacts'])->name('ai.artefacts');
    Route::delete('/ai/artefacts/{artefact}', [LmsAiChatController::class, 'destroyArtefact'])->name('ai.artefacts.destroy');
});
