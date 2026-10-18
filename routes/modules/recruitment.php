<?php

use App\Modules\HR\Recruitment\Http\Controllers\ApplicationController;
use App\Modules\HR\Recruitment\Http\Controllers\ApprovalWorkflowController;
use App\Modules\HR\Recruitment\Http\Controllers\CandidateController;
use App\Modules\HR\Recruitment\Http\Controllers\CandidateMessageController;
use App\Modules\HR\Recruitment\Http\Controllers\CandidateScreeningController;
use App\Modules\HR\Recruitment\Http\Controllers\ReferenceCheckController;
use App\Modules\HR\Recruitment\Http\Controllers\InternalApplicationController;
use App\Modules\HR\Recruitment\Http\Controllers\InternalJobBoardController;
use App\Modules\HR\Recruitment\Http\Controllers\InterviewController;
use App\Modules\HR\Recruitment\Http\Controllers\JobController;
use App\Modules\HR\Recruitment\Http\Controllers\PipelineController;
use App\Modules\HR\Recruitment\Http\Controllers\RecruitmentDashboardController;
use App\Modules\HR\Recruitment\Http\Controllers\RecruitmentAnalyticsController;
use App\Modules\HR\Recruitment\Http\Controllers\RecruitmentReportsController;
use App\Modules\HR\Recruitment\Http\Controllers\RequisitionApprovalController;
use App\Modules\HR\Recruitment\Http\Controllers\RequisitionController;
use App\Modules\HR\Recruitment\Http\Controllers\ShortlistController;
use App\Modules\HR\Recruitment\Http\Controllers\SocialAccountController;
use App\Modules\HR\Recruitment\Http\Controllers\SuccessionPlanningController;
use App\Modules\HR\Recruitment\Http\Controllers\VacancyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'module.active:recruitment'])->prefix('recruitment')->name('recruitment.')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/dashboard', [RecruitmentDashboardController::class, 'index'])->name('dashboard');

    // ── Jobs ─────────────────────────────────────────────────────────────────
    Route::prefix('jobs')->name('jobs.')->group(function () {
        Route::get('/',          [JobController::class, 'index'])->name('index');
        Route::get('/create',    [JobController::class, 'create'])->name('create');
        Route::post('/',         [JobController::class, 'store'])->name('store');
        Route::get('/{job}',     [JobController::class, 'show'])->name('show');
        Route::patch('/{job}',   [JobController::class, 'update'])->name('update');
        Route::delete('/{job}',  [JobController::class, 'destroy'])->name('destroy');

        // Pipeline board
        Route::get('/{job}/pipeline', [PipelineController::class, 'index'])->name('pipeline');

        // ── Job-scoped Requisitions (legacy) ──────────────────────────────────
        Route::prefix('/{job}/requisitions')->name('requisitions.')->group(function () {
            Route::post('/',                       [RequisitionController::class, 'store'])->name('store');
            Route::post('/{requisition}/submit',   [RequisitionController::class, 'submit'])->name('submit');
            Route::post('/{requisition}/approve',  [RequisitionApprovalController::class, 'approve'])->name('approve');
            Route::post('/{requisition}/reject',   [RequisitionApprovalController::class, 'reject'])->name('reject');
        });

        // ── Applications ──────────────────────────────────────────────────────
        Route::prefix('/{job}/applications')->name('applications.')->group(function () {
            Route::get('/',                        [ApplicationController::class, 'index'])->name('index');
            Route::post('/',                       [ApplicationController::class, 'store'])->name('store');
            Route::get('/{application}',           [ApplicationController::class, 'show'])->name('show');
            Route::patch('/{application}/move',    [ApplicationController::class, 'move'])->name('move');
            Route::post('/{application}/reject',   [ApplicationController::class, 'reject'])->name('reject');
            Route::post('/{application}/withdraw', [ApplicationController::class, 'withdraw'])->name('withdraw');
            Route::post('/bulk',                   [ApplicationController::class, 'bulkAction'])->name('bulk');

            // ── References ────────────────────────────────────────────────────
            Route::prefix('/{application}/references')->name('references.')->group(function () {
                Route::post('/',              [ReferenceCheckController::class, 'store'])->name('store');
                Route::patch('/{reference}',  [ReferenceCheckController::class, 'update'])->name('update');
                Route::delete('/{reference}', [ReferenceCheckController::class, 'destroy'])->name('destroy');
            });

            // ── Messages ──────────────────────────────────────────────────────
            Route::post('/{application}/messages', [CandidateMessageController::class, 'store'])->name('messages.store');

            // ── Interviews ────────────────────────────────────────────────────
            Route::prefix('/{application}/interviews')->name('interviews.')->group(function () {
                Route::post('/',                     [InterviewController::class, 'store'])->name('store');
                Route::get('/{interview}',                    [InterviewController::class, 'show'])->name('show');
                Route::post('/{interview}/generate-questions', [InterviewController::class, 'generateQuestions'])->name('generate-questions');
                Route::post('/{interview}/score',    [InterviewController::class, 'score'])->name('score');
                Route::post('/{interview}/complete', [InterviewController::class, 'complete'])
                    ->middleware('throttle:recruitment.ai-scoring')
                    ->name('complete');
                Route::post('/{interview}/cancel',   [InterviewController::class, 'cancel'])->name('cancel');
            });
        });

        // ── Shortlists ────────────────────────────────────────────────────────
        Route::prefix('/{job}/shortlists')->name('shortlists.')->group(function () {
            Route::get('/',  [ShortlistController::class, 'index'])->name('index');
            Route::post('/', [ShortlistController::class, 'generate'])
                ->middleware('throttle:recruitment.ai-scoring')
                ->name('generate');
            Route::get('/{shortlist}',                               [ShortlistController::class, 'show'])->name('show');
            Route::post('/{shortlist}/submit',                       [ShortlistController::class, 'submit'])->name('submit');
            Route::post('/{shortlist}/approve',                      [ShortlistController::class, 'approve'])->name('approve');
            Route::post('/{shortlist}/reject',                       [ShortlistController::class, 'reject'])->name('reject');
            Route::post('/{shortlist}/overrides',                    [ShortlistController::class, 'override'])->name('overrides.store');
            Route::post('/{shortlist}/overrides/{override}/approve', [ShortlistController::class, 'approveOverride'])->name('overrides.approve');
        });
    });

    // ── Requisitions (Sprint 1 — standalone CRUD) ─────────────────────────────
    Route::prefix('requisitions')->name('requisitions.')->group(function () {
        Route::get('/',       [RequisitionController::class, 'index'])->name('index');
        Route::get('/create', [RequisitionController::class, 'create'])->name('create');
        Route::post('/',      [RequisitionController::class, 'store'])->name('store');

        Route::prefix('/{requisition}')->group(function () {
            Route::get('/',       [RequisitionController::class, 'show'])->name('show');
            Route::get('/edit',   [RequisitionController::class, 'edit'])->name('edit');
            Route::put('/',       [RequisitionController::class, 'update'])->name('update');
            Route::delete('/',    [RequisitionController::class, 'destroy'])->name('destroy');
            Route::post('/submit',  [RequisitionController::class, 'submit'])->name('submit');
            Route::post('/cancel',  [RequisitionController::class, 'cancel'])->name('cancel');
            Route::post('/convert', [RequisitionController::class, 'convert'])->name('convert');
        });
    });

    // ── Approvals ─────────────────────────────────────────────────────────────
    Route::prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/pending', [RequisitionApprovalController::class, 'pending'])->name('pending');

        Route::prefix('requisitions/{requisition}')->name('requisitions.')->group(function () {
            Route::post('/approve', [RequisitionApprovalController::class, 'approve'])->name('approve');
            Route::post('/reject',  [RequisitionApprovalController::class, 'reject'])->name('reject');
        });
    });

    // ── Candidates ────────────────────────────────────────────────────────────
    Route::prefix('candidates')->name('candidates.')->group(function () {
        Route::get('/',            [CandidateController::class, 'index'])->name('index');
        Route::post('/',           [CandidateController::class, 'store'])->name('store');
        Route::get('/{candidate}', [CandidateController::class, 'show'])->name('show');
        Route::post('/{candidate}/cv', [CandidateController::class, 'uploadCv'])
            ->middleware('throttle:recruitment.cv-upload')
            ->name('cv.upload');
        Route::post('/{candidate}/consent', [CandidateController::class, 'consent'])->name('consent');
        Route::post('/{candidate}/reparse', [CandidateController::class, 'reparse'])->name('reparse');
        // AI Candidate Screening — public-reputation analysis (human review, no auto decisions)
        Route::get('/{candidate}/screening',           [CandidateScreeningController::class, 'show'])->name('screening');
        Route::post('/{candidate}/screening/rescreen', [CandidateScreeningController::class, 'rescreen'])->name('screening.rescreen');
    });

    // ── Vacancies (Sprint 2) ──────────────────────────────────────────────────
    Route::prefix('vacancies')->name('vacancies.')->group(function () {
        Route::get('/',       [VacancyController::class, 'index'])->name('index');
        Route::get('/create', [VacancyController::class, 'create'])->name('create');
        Route::post('/',      [VacancyController::class, 'store'])->name('store');

        Route::prefix('/{vacancy}')->group(function () {
            Route::get('/',            [VacancyController::class, 'show'])->name('show');
            Route::post('/publish',    [VacancyController::class, 'publish'])->name('publish');
            Route::post('/close',      [VacancyController::class, 'close'])->name('close');
            Route::post('/transition', [VacancyController::class, 'transition'])->name('transition');
        });
    });

    // ── Internal Job Board ────────────────────────────────────────────────────
    Route::prefix('internal')->name('internal.')->group(function () {
        Route::get('/job-board',                          [InternalJobBoardController::class, 'index'])->name('job-board');
        Route::get('/job-board/{vacancyUuid}/apply',      [InternalApplicationController::class, 'show'])->name('apply');
        Route::post('/job-board/{vacancyUuid}/apply',     [InternalApplicationController::class, 'store'])->name('apply.store');
    });

    // ── Succession Planning ───────────────────────────────────────────────────
    Route::prefix('succession')->name('succession.')->group(function () {
        Route::get('/',              [SuccessionPlanningController::class, 'index'])->name('index');
        Route::post('/',             [SuccessionPlanningController::class, 'store'])->name('store');
        Route::delete('/{successionCandidate}', [SuccessionPlanningController::class, 'destroy'])->name('destroy');
    });

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::get('/analytics', [RecruitmentAnalyticsController::class, 'index'])->name('analytics');
    Route::get('/reports', [RecruitmentReportsController::class, 'index'])->name('reports');

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::prefix('approval-workflows')->name('approval-workflows.')->group(function () {
            Route::get('/',                       [ApprovalWorkflowController::class, 'index'])->name('index');
            Route::post('/',                      [ApprovalWorkflowController::class, 'store'])->name('store');
            Route::put('/{approvalWorkflow}',     [ApprovalWorkflowController::class, 'update'])->name('update');
            Route::delete('/{approvalWorkflow}',  [ApprovalWorkflowController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('social-accounts')->name('social-accounts.')->group(function () {
            Route::get('/',                          [SocialAccountController::class, 'index'])->name('index');
            Route::post('/',                         [SocialAccountController::class, 'store'])->name('store');
            Route::put('/{socialAccount}',           [SocialAccountController::class, 'update'])->name('update');
            Route::delete('/{socialAccount}',        [SocialAccountController::class, 'destroy'])->name('destroy');
            Route::post('/{socialAccount}/publish',  [SocialAccountController::class, 'publish'])->name('publish');
            Route::get('/{provider}/connect',        [SocialAccountController::class, 'connect'])->name('connect');
        });
    });
});
