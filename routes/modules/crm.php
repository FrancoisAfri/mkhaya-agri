<?php

use App\Modules\CRM\Http\Controllers\AccountController;
use App\Modules\CRM\Http\Controllers\ActivityController;
use App\Modules\CRM\Http\Controllers\CampaignController;
use App\Modules\CRM\Http\Controllers\ContactController;
use App\Modules\CRM\Http\Controllers\CrmDashboardController;
use App\Modules\CRM\Http\Controllers\ApiTokenController;
use App\Modules\CRM\Http\Controllers\DealController;
use App\Modules\CRM\Http\Controllers\DuplicateController;
use App\Modules\CRM\Http\Controllers\ImportController;
use App\Modules\CRM\Http\Controllers\ApprovalController;
use App\Modules\CRM\Http\Controllers\AuditController;
use App\Modules\CRM\Http\Controllers\CommentController;
use App\Modules\CRM\Http\Controllers\CrmAnalyticsController;
use App\Modules\CRM\Http\Controllers\CrmScheduledReportController;
use App\Modules\CRM\Http\Controllers\CustomFieldController;
use App\Modules\CRM\Http\Controllers\CustomObjectController;
use App\Modules\CRM\Http\Controllers\KpiController;
use App\Modules\CRM\Http\Controllers\LeadController;
use App\Modules\CRM\Http\Controllers\NotificationController;
use App\Modules\CRM\Http\Controllers\ReportController;
use App\Modules\CRM\Http\Controllers\SavedViewController;
use App\Modules\CRM\Http\Controllers\WebhookController;
use App\Modules\CRM\Http\Controllers\WorkflowController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

// Note: the public open/click tracking routes (crm.track.*) are registered in
// routes/web.php at the top level so they are NOT behind auth — mail clients
// and browsers must reach them without a session.

Route::middleware(['web', 'auth', 'module.active:crm', 'module.tier:crm'])
    ->prefix('crm')
    ->name('crm.')
    ->group(function () {

        Route::get('/', [CrmDashboardController::class, 'index'])->name('dashboard');

        // ── Navigation hubs — role-filtered feature-card landing pages ─────────
        foreach (['workspace', 'sell', 'engage', 'insights'] as $hub) {
            Route::get("/{$hub}", [ModuleHubController::class, 'show'])
                ->defaults('module', 'crm')
                ->defaults('group', str_replace('-', '_', $hub))
                ->name('hub.' . str_replace('-', '_', $hub));
        }

        // ── AI translation (multilingual comms) ────────────────────────────────
        Route::post('/translate', [\App\Modules\CRM\Http\Controllers\CrmTranslateController::class, 'translate'])->name('translate');

        // ── AI Copilot (natural-language ask / act over CRM data) ──────────────
        Route::get('/copilot',      [\App\Modules\CRM\Http\Controllers\CrmCopilotController::class, 'index'])->name('copilot');
        Route::post('/copilot/ask', [\App\Modules\CRM\Http\Controllers\CrmCopilotController::class, 'ask'])->name('copilot.ask');
        Route::post('/copilot/act', [\App\Modules\CRM\Http\Controllers\CrmCopilotController::class, 'act'])->name('copilot.act');

        // ── My Day (AI next-best-action worklist) ──────────────────────────────
        Route::get('/my-day',          [\App\Modules\CRM\Http\Controllers\CrmMyDayController::class, 'index'])->name('my-day');
        Route::post('/my-day/generate',[\App\Modules\CRM\Http\Controllers\CrmMyDayController::class, 'generate'])->name('my-day.generate');

        // ── AI forecasting & pipeline inspection ───────────────────────────────
        Route::get('/forecast',         [\App\Modules\CRM\Http\Controllers\CrmForecastController::class, 'index'])->name('forecast');
        Route::post('/forecast/analyze',  [\App\Modules\CRM\Http\Controllers\CrmForecastController::class, 'analyze'])->name('forecast.analyze');
        Route::post('/forecast/anomalies',[\App\Modules\CRM\Http\Controllers\CrmForecastController::class, 'anomalies'])->name('forecast.anomalies');

        // ── Sales performance dashboards & targets ─────────────────────────────
        Route::get('/my-dashboard',        [\App\Modules\CRM\Http\Controllers\CrmPerformanceController::class, 'me'])->name('my-dashboard');
        Route::get('/team-dashboard',      [\App\Modules\CRM\Http\Controllers\CrmPerformanceController::class, 'team'])->name('team-dashboard');
        Route::get('/executive-dashboard', [\App\Modules\CRM\Http\Controllers\CrmPerformanceController::class, 'executive'])->name('executive-dashboard');
        Route::get('/targets',             [\App\Modules\CRM\Http\Controllers\CrmTargetController::class, 'index'])->name('targets');
        Route::post('/targets',            [\App\Modules\CRM\Http\Controllers\CrmTargetController::class, 'store'])->name('targets.store');
        Route::delete('/targets',          [\App\Modules\CRM\Http\Controllers\CrmTargetController::class, 'destroy'])->name('targets.destroy');

        // ── Location lookups (dependent dropdowns) ─────────────────────────────
        Route::get('/locations/cities', [\App\Modules\CRM\Http\Controllers\CrmLocationController::class, 'cities'])->name('locations.cities');

        // ── Click-to-call (provider-agnostic VoIP) ─────────────────────────────
        Route::get('/calls',           [\App\Modules\CRM\Http\Controllers\CallController::class, 'index'])->name('calls');
        Route::post('/calls/initiate', [\App\Modules\CRM\Http\Controllers\CallController::class, 'initiate'])->name('calls.initiate');

        // ── Customer surveys ───────────────────────────────────────────────────
        Route::get('/surveys',                       [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'index'])->name('surveys');
        Route::post('/surveys',                      [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'store'])->name('surveys.store');
        Route::get('/surveys/recommendations',       [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'recommendations'])->name('surveys.recommendations');

        // Survey templates library (best-practice starting points)
        Route::get('/survey-templates',              [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'templates'])->name('survey-templates');

        // ── Products & Services catalog ─────────────────────────────────────────
        Route::get('/products',                 [\App\Modules\CRM\Http\Controllers\CrmProductController::class, 'index'])->name('products');
        Route::post('/products',                [\App\Modules\CRM\Http\Controllers\CrmProductController::class, 'store'])->name('products.store');
        Route::patch('/products/{product}',     [\App\Modules\CRM\Http\Controllers\CrmProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}',    [\App\Modules\CRM\Http\Controllers\CrmProductController::class, 'destroy'])->name('products.destroy');

        // Survey planner (recurring & advance-scheduled surveys)
        Route::get('/survey-plans',                   [\App\Modules\CRM\Http\Controllers\CrmSurveyPlanController::class, 'index'])->name('survey-plans');
        Route::post('/survey-plans',                  [\App\Modules\CRM\Http\Controllers\CrmSurveyPlanController::class, 'store'])->name('survey-plans.store');
        Route::patch('/survey-plans/{plan}',          [\App\Modules\CRM\Http\Controllers\CrmSurveyPlanController::class, 'update'])->name('survey-plans.update');
        Route::post('/survey-plans/{plan}/toggle',    [\App\Modules\CRM\Http\Controllers\CrmSurveyPlanController::class, 'toggle'])->name('survey-plans.toggle');
        Route::post('/survey-plans/{plan}/run',       [\App\Modules\CRM\Http\Controllers\CrmSurveyPlanController::class, 'runNow'])->name('survey-plans.run');
        Route::delete('/survey-plans/{plan}',         [\App\Modules\CRM\Http\Controllers\CrmSurveyPlanController::class, 'destroy'])->name('survey-plans.destroy');
        Route::post('/surveys/audience-preview',     [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'audiencePreview'])->name('surveys.audience-preview');
        Route::get('/surveys/{survey}',              [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'show'])->name('surveys.show');
        Route::patch('/surveys/{survey}',            [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'update'])->name('surveys.update');
        Route::post('/surveys/{survey}/send',        [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'send'])->name('surveys.send');
        Route::post('/surveys/{survey}/close',       [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'close'])->name('surveys.close');
        Route::post('/surveys/{survey}/analyze',     [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'analyze'])->name('surveys.analyze');
        Route::delete('/surveys/{survey}',           [\App\Modules\CRM\Http\Controllers\CrmSurveyController::class, 'destroy'])->name('surveys.destroy');

        // ── Meetings (audio upload → AI transcription & summary) ───────────────
        Route::get('/meetings',                       [\App\Modules\CRM\Http\Controllers\CrmMeetingController::class, 'index'])->name('meetings');
        Route::post('/meetings',                      [\App\Modules\CRM\Http\Controllers\CrmMeetingController::class, 'store'])->name('meetings.store');
        Route::post('/meetings/{meeting}/followup',   [\App\Modules\CRM\Http\Controllers\CrmMeetingController::class, 'scheduleFollowup'])->name('meetings.followup');
        Route::post('/meetings/{meeting}/retry',      [\App\Modules\CRM\Http\Controllers\CrmMeetingController::class, 'retry'])->name('meetings.retry');
        Route::post('/meetings/{meeting}/score',      [\App\Modules\CRM\Http\Controllers\CrmMeetingController::class, 'score'])->name('meetings.score');
        Route::delete('/meetings/{meeting}',          [\App\Modules\CRM\Http\Controllers\CrmMeetingController::class, 'destroy'])->name('meetings.destroy');

        // ── Complaints & compliments ───────────────────────────────────────────
        Route::get('/complaints',                    [\App\Modules\CRM\Http\Controllers\CrmComplaintController::class, 'index'])->name('complaints');
        Route::post('/complaints',                   [\App\Modules\CRM\Http\Controllers\CrmComplaintController::class, 'store'])->name('complaints.store');
        Route::get('/complaints/settings',           [\App\Modules\CRM\Http\Controllers\CrmComplaintController::class, 'settings'])->name('complaints.settings');
        Route::patch('/complaints/settings',         [\App\Modules\CRM\Http\Controllers\CrmComplaintController::class, 'saveSettings'])->name('complaints.settings.save');
        Route::get('/complaints/{complaint}',        [\App\Modules\CRM\Http\Controllers\CrmComplaintController::class, 'show'])->name('complaints.show');
        Route::patch('/complaints/{complaint}',      [\App\Modules\CRM\Http\Controllers\CrmComplaintController::class, 'update'])->name('complaints.update');
        Route::post('/complaints/{complaint}/analyze',[\App\Modules\CRM\Http\Controllers\CrmComplaintController::class, 'analyze'])->name('complaints.analyze');
        Route::delete('/complaints/{complaint}',     [\App\Modules\CRM\Http\Controllers\CrmComplaintController::class, 'destroy'])->name('complaints.destroy');

        // ── Email templates (merge-tag aware) ──────────────────────────────────
        Route::get('/email-templates',               [\App\Modules\CRM\Http\Controllers\CrmEmailTemplateController::class, 'index'])->name('email-templates');
        Route::post('/email-templates',              [\App\Modules\CRM\Http\Controllers\CrmEmailTemplateController::class, 'store'])->name('email-templates.store');
        Route::patch('/email-templates/{template}',  [\App\Modules\CRM\Http\Controllers\CrmEmailTemplateController::class, 'update'])->name('email-templates.update');
        Route::delete('/email-templates/{template}', [\App\Modules\CRM\Http\Controllers\CrmEmailTemplateController::class, 'destroy'])->name('email-templates.destroy');

        // ── CRM Settings (in-app config, e.g. WhatsApp) ────────────────────────
        Route::get('/settings',                 [\App\Modules\CRM\Http\Controllers\CrmSettingsController::class, 'index'])->name('settings');
        Route::patch('/settings',               [\App\Modules\CRM\Http\Controllers\CrmSettingsController::class, 'update'])->name('settings.update');
        Route::patch('/settings/import-rules', [\App\Modules\CRM\Http\Controllers\CrmSettingsController::class, 'saveImportRules'])->name('settings.import-rules');
        Route::post('/settings/whatsapp/clear', [\App\Modules\CRM\Http\Controllers\CrmSettingsController::class, 'clearWhatsApp'])->name('settings.whatsapp.clear');
        Route::post('/settings/whatsapp/test',  [\App\Modules\CRM\Http\Controllers\CrmSettingsController::class, 'testWhatsApp'])->name('settings.whatsapp.test');

        // ── Integrations hub (email / VoIP / WhatsApp / SMS providers) ─────────
        Route::get('/integrations',                          [\App\Modules\CRM\Http\Controllers\CrmIntegrationController::class, 'index'])->name('integrations');
        Route::post('/integrations',                         [\App\Modules\CRM\Http\Controllers\CrmIntegrationController::class, 'store'])->name('integrations.store');
        Route::post('/integrations/{integration}/toggle',    [\App\Modules\CRM\Http\Controllers\CrmIntegrationController::class, 'toggle'])->name('integrations.toggle');
        Route::post('/integrations/{integration}/test',      [\App\Modules\CRM\Http\Controllers\CrmIntegrationController::class, 'test'])->name('integrations.test');
        Route::delete('/integrations/{integration}',         [\App\Modules\CRM\Http\Controllers\CrmIntegrationController::class, 'destroy'])->name('integrations.destroy');

        // ── Incoming mailboxes (AI lead capture) ──────────────────────────────
        Route::get('/mailboxes',                       [\App\Modules\CRM\Http\Controllers\CrmMailboxController::class, 'index'])->name('mailboxes');
        Route::post('/mailboxes',                      [\App\Modules\CRM\Http\Controllers\CrmMailboxController::class, 'store'])->name('mailboxes.store');
        Route::patch('/mailboxes/{mailbox}',           [\App\Modules\CRM\Http\Controllers\CrmMailboxController::class, 'update'])->name('mailboxes.update');
        Route::post('/mailboxes/{mailbox}/toggle',     [\App\Modules\CRM\Http\Controllers\CrmMailboxController::class, 'toggle'])->name('mailboxes.toggle');
        Route::post('/mailboxes/{mailbox}/test',       [\App\Modules\CRM\Http\Controllers\CrmMailboxController::class, 'test'])->name('mailboxes.test');
        Route::post('/mailboxes/{mailbox}/poll',       [\App\Modules\CRM\Http\Controllers\CrmMailboxController::class, 'pollNow'])->name('mailboxes.poll');
        Route::delete('/mailboxes/{mailbox}',          [\App\Modules\CRM\Http\Controllers\CrmMailboxController::class, 'destroy'])->name('mailboxes.destroy');

        // ── Suppression list (unsubscribes / bounces) ──────────────────────────
        Route::get('/suppressions',                  [\App\Modules\CRM\Http\Controllers\SuppressionController::class, 'index'])->name('suppressions');
        Route::post('/suppressions',                 [\App\Modules\CRM\Http\Controllers\SuppressionController::class, 'store'])->name('suppressions.store');
        Route::delete('/suppressions/{suppression}', [\App\Modules\CRM\Http\Controllers\SuppressionController::class, 'destroy'])->name('suppressions.destroy');

        // ── AI SDR sequences (multi-step cadences) ─────────────────────────────
        Route::get('/sequences',                     [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'index'])->name('sequences');
        Route::post('/sequences',                    [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'store'])->name('sequences.store');
        Route::post('/sequences/draft',              [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'draft'])->name('sequences.draft');
        Route::get('/sequences/{sequence}',          [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'show'])->name('sequences.show');
        Route::patch('/sequences/{sequence}',        [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'update'])->name('sequences.update');
        Route::post('/sequences/{sequence}/enroll',  [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'enroll'])->name('sequences.enroll');
        Route::post('/sequences/{sequence}/run',     [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'runNow'])->name('sequences.run');
        Route::post('/sequences/{sequence}/test',    [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'sendTest'])->name('sequences.test');
        // Read a step as the client will receive it, without putting a copy in your own inbox.
        Route::post('/sequences/{sequence}/preview', [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'preview'])->name('sequences.preview');
        Route::delete('/sequences/{sequence}',       [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'destroy'])->name('sequences.destroy');
        Route::post('/sequence-enrollments/{enrollment}/reply', [\App\Modules\CRM\Http\Controllers\CrmSequenceController::class, 'recordReply'])->name('sequences.reply');

        // ── Marketing Campaigns (email + WhatsApp) ─────────────────────────────
        Route::get('/campaigns',                   [CampaignController::class, 'index'])->name('campaigns');
        Route::post('/campaigns',                  [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('/campaigns/{campaign}',        [CampaignController::class, 'detail'])->name('campaigns.detail');
        Route::patch('/campaigns/{campaign}',      [CampaignController::class, 'update'])->name('campaigns.update');
        Route::post('/campaigns/{campaign}/content',    [CampaignController::class, 'uploadContent'])->name('campaigns.content');
        Route::post('/campaigns/{campaign}/recipients', [CampaignController::class, 'addRecipients'])->name('campaigns.recipients');
        Route::post('/campaigns/{campaign}/schedule',   [CampaignController::class, 'schedule'])->name('campaigns.schedule');
        Route::post('/campaigns/{campaign}/send',       [CampaignController::class, 'sendNow'])->name('campaigns.send');
        Route::post('/campaigns/{campaign}/test',       [CampaignController::class, 'sendTest'])->name('campaigns.test');
        Route::delete('/campaigns/{campaign}',     [CampaignController::class, 'destroy'])->name('campaigns.destroy');

        // ── Reports & Analytics ────────────────────────────────────────────────
        Route::get('/reports',               [ReportController::class, 'index'])->name('reports');
        Route::get('/reports/export/{type}', [ReportController::class, 'export'])->name('reports.export');

        // ── BI Analytics ───────────────────────────────────────────────────────
        Route::get('/analytics',         [CrmAnalyticsController::class, 'index'])->name('analytics');
        Route::post('/analytics/narrate',[CrmAnalyticsController::class, 'narrate'])->name('analytics.narrate');

        // ── Scheduled Reports (queued delivery) ────────────────────────────────
        Route::get('/scheduled-reports',                    [CrmScheduledReportController::class, 'index'])->name('scheduled-reports');
        Route::post('/scheduled-reports',                   [CrmScheduledReportController::class, 'store'])->name('scheduled-reports.store');
        Route::patch('/scheduled-reports/{scheduledReport}',[CrmScheduledReportController::class, 'update'])->name('scheduled-reports.update');
        Route::post('/scheduled-reports/{scheduledReport}/toggle', [CrmScheduledReportController::class, 'toggle'])->name('scheduled-reports.toggle');
        Route::post('/scheduled-reports/{scheduledReport}/run',    [CrmScheduledReportController::class, 'runNow'])->name('scheduled-reports.run');
        Route::delete('/scheduled-reports/{scheduledReport}',[CrmScheduledReportController::class, 'destroy'])->name('scheduled-reports.destroy');

        // ── Custom KPI builder ─────────────────────────────────────────────────
        Route::get('/metrics',          [KpiController::class, 'index'])->name('metrics');
        Route::post('/metrics',         [KpiController::class, 'store'])->name('metrics.store');
        Route::patch('/metrics/{kpi}',  [KpiController::class, 'update'])->name('metrics.update');
        Route::delete('/metrics/{kpi}', [KpiController::class, 'destroy'])->name('metrics.destroy');

        // ── Leads ──────────────────────────────────────────────────────────────
        // Hand leads/deals/accounts/contacts to another sales person. One endpoint for all four,
        // because the rules about who may move what are the same everywhere.
        Route::post('/reassign', [\App\Modules\CRM\Http\Controllers\ReassignController::class, 'store'])->name('reassign');

        Route::get('/leads',                 [LeadController::class, 'index'])->name('leads');
        Route::post('/leads',                [LeadController::class, 'store'])->name('leads.store');
        Route::patch('/leads/{lead}',        [LeadController::class, 'update'])->name('leads.update');
        Route::delete('/leads/{lead}',       [LeadController::class, 'destroy'])->name('leads.destroy');
        Route::post('/leads/{lead}/score',   [LeadController::class, 'score'])->name('leads.score');
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');

        // ── Saved Views / Segments ─────────────────────────────────────────────
        Route::post('/saved-views',         [SavedViewController::class, 'store'])->name('saved-views.store');
        Route::patch('/saved-views/{view}', [SavedViewController::class, 'update'])->name('saved-views.update');
        Route::delete('/saved-views/{view}',[SavedViewController::class, 'destroy'])->name('saved-views.destroy');

        // ── Contacts ───────────────────────────────────────────────────────────
        Route::get('/contacts',           [ContactController::class, 'index'])->name('contacts');
        Route::post('/contacts',          [ContactController::class, 'store'])->name('contacts.store');
        Route::patch('/contacts/{contact}',  [ContactController::class, 'update'])->name('contacts.update');
        Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');

        // ── Accounts ───────────────────────────────────────────────────────────
        Route::get('/account-health',          [\App\Modules\CRM\Http\Controllers\CrmAccountHealthController::class, 'index'])->name('account-health');
        Route::post('/account-health/{account}',[\App\Modules\CRM\Http\Controllers\CrmAccountHealthController::class, 'assess'])->name('account-health.assess');

        Route::get('/accounts',           [AccountController::class, 'index'])->name('accounts');
        // Pull Accounting's customers across as accounts (only when Accounting is active).
        Route::post('/accounts/sync-accounting', [AccountController::class, 'syncFromAccounting'])->name('accounts.sync-accounting');
        Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::post('/accounts',          [AccountController::class, 'store'])->name('accounts.store');
        Route::patch('/accounts/{account}',  [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

        // ── Deals / Pipeline board ─────────────────────────────────────────────
        Route::get('/deals',                  [DealController::class, 'index'])->name('deals');
        Route::post('/deals',                 [DealController::class, 'store'])->name('deals.store');
        Route::patch('/deals/{deal}',         [DealController::class, 'update'])->name('deals.update');
        Route::delete('/deals/{deal}',        [DealController::class, 'destroy'])->name('deals.destroy');
        Route::post('/deals/{deal}/move',     [DealController::class, 'moveStage'])->name('deals.move');
        Route::post('/deals/{deal}/lost',     [DealController::class, 'markLost'])->name('deals.lost');
        Route::post('/deals/{deal}/hold',     [DealController::class, 'hold'])->name('deals.hold');
        Route::post('/deals/{deal}/resume',   [DealController::class, 'resume'])->name('deals.resume');
        Route::get('/deals/{deal}/advise',    [DealController::class, 'advise'])->name('deals.advise');
        Route::get('/deals/{deal}/detail',          [DealController::class, 'detail'])->name('deals.detail');
        Route::post('/deals/{deal}/ai/risk',        [DealController::class, 'risk'])->name('deals.ai.risk');
        Route::post('/deals/{deal}/ai/next-action', [DealController::class, 'nextAction'])->name('deals.ai.next-action');
        Route::post('/deals/{deal}/ai/follow-up',   [DealController::class, 'followUp'])->name('deals.ai.follow-up');
        Route::post('/deals/{deal}/ai/close-probability', [DealController::class, 'closeProbability'])->name('deals.ai.close-probability');

        // ── Activities / Timeline ──────────────────────────────────────────────
        Route::get('/activities',                    [ActivityController::class, 'index'])->name('activities');
        Route::post('/activities',                   [ActivityController::class, 'store'])->name('activities.store');
        Route::post('/activities/{activity}/complete',  [ActivityController::class, 'complete'])->name('activities.complete');
        Route::post('/activities/{activity}/summarise', [ActivityController::class, 'summarise'])->name('activities.summarise');
        Route::delete('/activities/{activity}',      [ActivityController::class, 'destroy'])->name('activities.destroy');

        // ── Automations / Workflows ────────────────────────────────────────────
        Route::get('/automations',                    [WorkflowController::class, 'index'])->name('automations');
        Route::post('/automations',                   [WorkflowController::class, 'store'])->name('automations.store');
        Route::patch('/automations/{workflow}',       [WorkflowController::class, 'update'])->name('automations.update');
        Route::post('/automations/{workflow}/toggle', [WorkflowController::class, 'toggle'])->name('automations.toggle');
        Route::delete('/automations/{workflow}',      [WorkflowController::class, 'destroy'])->name('automations.destroy');

        // ── Data Import (CSV / Bitrix24 / HubSpot / Salesforce) ────────────────
        Route::get('/import',         [ImportController::class, 'index'])->name('import');
        Route::get('/import/template/{entity_type}', [ImportController::class, 'template'])->name('import.template');
        Route::post('/import/preview',[ImportController::class, 'preview'])->name('import.preview');
        // What Run Import would do, against the mapping actually on screen, writing nothing.
        Route::post('/import/dry-run', [ImportController::class, 'dryRun'])->name('import.dry-run');
        Route::post('/import/commit', [ImportController::class, 'commit'])->name('import.commit');
        // The rows an import did not take, as a CSV — the case that needs it most is the one with
        // hundreds of rows, which is exactly what a panel cannot show.
        Route::get('/import/{import}/skipped', [ImportController::class, 'issues'])->name('import.skipped');
        Route::post('/import/{import}/reverse', [ImportController::class, 'reverse'])->name('import.reverse');

        // ── Data hygiene (AI enrichment + completeness/staleness) ──────────────
        Route::get('/data-hygiene',            [\App\Modules\CRM\Http\Controllers\CrmDataHygieneController::class, 'index'])->name('data-hygiene');
        Route::post('/data-hygiene/{account}/enrich',[\App\Modules\CRM\Http\Controllers\CrmDataHygieneController::class, 'enrich'])->name('data-hygiene.enrich');

        // ── Duplicate detection & merge ────────────────────────────────────────
        Route::get('/duplicates',       [DuplicateController::class, 'index'])->name('duplicates');
        Route::post('/duplicates/merge',[DuplicateController::class, 'merge'])->name('duplicates.merge');

        // ── API & Webhooks ─────────────────────────────────────────────────────
        Route::get('/webhooks',                       [WebhookController::class, 'index'])->name('webhooks');
        Route::post('/webhooks',                      [WebhookController::class, 'store'])->name('webhooks.store');
        Route::patch('/webhooks/{webhook}',           [WebhookController::class, 'update'])->name('webhooks.update');
        Route::post('/webhooks/{webhook}/toggle',     [WebhookController::class, 'toggle'])->name('webhooks.toggle');
        Route::post('/webhooks/{webhook}/regenerate', [WebhookController::class, 'regenerateSecret'])->name('webhooks.regenerate');
        Route::delete('/webhooks/{webhook}',          [WebhookController::class, 'destroy'])->name('webhooks.destroy');
        // API tokens (Sanctum)
        Route::post('/api-tokens',          [ApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('/api-tokens/{token}',[ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

        // ── Discussions (threaded comments + @mentions) ────────────────────────
        Route::get('/discussions',              [CommentController::class, 'index'])->name('discussions');
        Route::post('/discussions',             [CommentController::class, 'store'])->name('discussions.store');
        Route::delete('/discussions/{comment}', [CommentController::class, 'destroy'])->name('discussions.destroy');

        // ── Approvals & Collaboration ──────────────────────────────────────────
        Route::get('/approvals',                  [ApprovalController::class, 'index'])->name('approvals');
        Route::post('/approvals',                 [ApprovalController::class, 'store'])->name('approvals.store');
        Route::post('/approvals/{approval}/decide',[ApprovalController::class, 'decide'])->name('approvals.decide');
        Route::post('/approvals/{approval}/cancel',[ApprovalController::class, 'cancel'])->name('approvals.cancel');
        // Notifications
        Route::get('/notifications/feed',                 [NotificationController::class, 'feed'])->name('notifications.feed');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('/notifications/read-all',            [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

        // ── Audit Trail (compliance) ───────────────────────────────────────────
        Route::get('/audit',        [AuditController::class, 'index'])->name('audit');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

        // ── Custom Objects (build-your-own CRM entities) ───────────────────────
        Route::get('/objects',                         [CustomObjectController::class, 'index'])->name('objects');
        Route::post('/objects',                        [CustomObjectController::class, 'storeType'])->name('objects.types.store');
        Route::delete('/objects/{type}',               [CustomObjectController::class, 'destroyType'])->name('objects.types.destroy');
        Route::post('/objects/{type}/records',         [CustomObjectController::class, 'storeRecord'])->name('objects.records.store');
        Route::patch('/objects/{type}/records/{record}',  [CustomObjectController::class, 'updateRecord'])->name('objects.records.update');
        Route::delete('/objects/{type}/records/{record}', [CustomObjectController::class, 'destroyRecord'])->name('objects.records.destroy');

        // ── Custom Fields (no-code data-model builder) ─────────────────────────
        Route::get('/custom-fields',           [CustomFieldController::class, 'index'])->name('custom-fields');
        Route::post('/custom-fields',          [CustomFieldController::class, 'store'])->name('custom-fields.store');
        Route::patch('/custom-fields/{field}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
        Route::delete('/custom-fields/{field}',[CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');
    });
