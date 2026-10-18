<?php

use App\Modules\Communication\ContentManagement\Controller\CeoMessageController;
use App\Modules\Communication\ContentManagement\Controller\CommunicationDashboardController;
use App\Modules\Communication\ContentManagement\Controller\CommunicationReportController;
use App\Modules\Communication\ContentManagement\Controller\ContentsController;
use App\Modules\Communication\ContentManagement\Controller\EmployeeSpotlightController;
use App\Modules\Communication\ContentManagement\Controller\ReactionController;
use App\Modules\Communication\EmailCampaigns\Controllers\EmailCampaignController;
use App\Modules\Communication\EmailCampaigns\Controllers\EmailCampaignTemplateController;
use App\Modules\Dashboard\Controller\DashbaordController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module.active:content_management'])->group(function () {

    /** CEO MESSAGE */
    Route::get('/cms/ceo-message', [DashbaordController::class, 'ceoMessage'])->name('cms.ceo-message');
    Route::post('/ceo-message/store', [CeoMessageController::class, 'store'])->name('ceo-message.store');
    Route::put('/ceo-message/{id}', [CeoMessageController::class, 'update'])->name('ceo-message.update');

    /** Content */
    Route::get('/cms/news', [DashbaordController::class, 'news'])->name('cms.news');
    Route::get('/cms/events', [DashbaordController::class, 'events'])->name('cms.events');
    Route::get('/cms/announcements', [DashbaordController::class, 'announcement'])->name('cms.announcement');
    Route::get('/cms/internal-notice', [DashbaordController::class, 'internalNotice'])->name('cms.internalNotice');
    Route::post('/content/store', [ContentsController::class, 'store'])->name('content.store');
    Route::put('/content/{id}', [ContentsController::class, 'update'])->name('content.update');
    Route::delete('/content/delete/{id}', [ContentsController::class, 'destroy'])->name('content.delete');

    /** Employee */
    Route::get('cms/employee-team', [DashbaordController::class, 'employeeSpotlights'])->name('cms.employee-team');
    Route::post('/employee-team/store', [EmployeeSpotlightController::class, 'store'])->name('employee-team.store');
    Route::put('/employee-team/update/{id}', [EmployeeSpotlightController::class, 'update'])->name('employee-team.update');

    /** Dashboard & Reports */
    Route::get('cms/dashaboard-cm', [CommunicationDashboardController::class, 'index'])->name('cms.dashboard');
    Route::get('/cms/reports-cm', [CommunicationReportController::class, 'index'])->name('cms.report');
    Route::get('cms-reports/generate/{reportKey}', [CommunicationReportController::class, 'generate'])->name('cms.generate');
    Route::get('cms-reports/export/{reportKey}/{format}', [CommunicationReportController::class, 'export'])->name('cms.export');

    /** Reactions */
    Route::prefix('reactions')->name('reactions.')->group(function () {
        Route::post('/',                          [ReactionController::class, 'store'])->name('store');
        Route::delete('/{reactionId}',            [ReactionController::class, 'destroy'])->name('delete');
        Route::delete('/comment/{reactionId}',    [ReactionController::class, 'destroyComment'])->name('comment.delete');
        Route::get('/comments',                   [ReactionController::class, 'comments'])->name('comments');
        Route::get('/user',                       [ReactionController::class, 'userReactions'])->name('user');
        Route::get('/count/{contentId}',          [ReactionController::class, 'countLikes'])->name('like.count');
    });
    /** Dashboard & Reports */
    Route::get('quick-access', [DashbaordController::class, 'quickAccess'])->name('quick-access');
    Route::post('quick-access/store', [DashbaordController::class, 'storeQuickAccess'])->name('quick-access.store');
    Route::put('quick-access/update/{id}', [DashbaordController::class, 'updateQuickAccess'])->name('quick-access.update');
    Route::delete('quick-access/delete/{id}', [DashbaordController::class, 'destroyQuickAccess'])->name('quick-access.delete');
    Route::post('/quick-access/user/add', [DashbaordController::class, 'addToUser']);
    Route::match(['post', 'delete'], '/quick-access/user/remove', [DashbaordController::class, 'removeFromUser']);

    // ── Email Campaigns ───────────────────────────────────────────────────────
    Route::prefix('cms/campaigns')->name('cms.campaigns.')->group(function () {
        Route::get('/',                        [EmailCampaignController::class, 'index'])->name('index');
        Route::post('/',                       [EmailCampaignController::class, 'stores'])->name('store');
        Route::get('/{campaign}',              [EmailCampaignController::class, 'show'])->name('show');
        Route::patch('/{campaign}',            [EmailCampaignController::class, 'update'])->name('update');
        Route::delete('/{campaign}',           [EmailCampaignController::class, 'destroy'])->name('destroy');
        Route::post('/{campaign}/send',        [EmailCampaignController::class, 'send'])->name('send');
        Route::post('/{campaign}/schedule',    [EmailCampaignController::class, 'schedule'])->name('schedule');
        Route::post('/{campaign}/cancel',      [EmailCampaignController::class, 'cancel'])->name('cancel');
        Route::post('/{campaign}/duplicate',   [EmailCampaignController::class, 'duplicate'])->name('duplicate');
    });

    // ── Campaign Templates ────────────────────────────────────────────────────
    Route::prefix('cms/campaign-templates')->name('cms.campaign-templates.')->group(function () {
        Route::get('/',               [EmailCampaignTemplateController::class, 'index'])->name('index');
        Route::get('/list',           [EmailCampaignTemplateController::class, 'list'])->name('list');
        Route::post('/',              [EmailCampaignTemplateController::class, 'store'])->name('store');
        Route::patch('/{template}',   [EmailCampaignTemplateController::class, 'update'])->name('update');
        Route::delete('/{template}',  [EmailCampaignTemplateController::class, 'destroy'])->name('destroy');
    });
});

// ── Open-tracking pixel (no auth — token IS the credential) ──────────────────
Route::get('/track/email-open/{token}', [EmailCampaignController::class, 'trackOpen'])
    ->name('cms.campaigns.track-open');
