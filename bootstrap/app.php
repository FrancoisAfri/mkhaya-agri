<?php

use App\Console\Commands\DocumentManagement\SweepDocumentExpiry as SweepDocumentExpiryCommand;
use App\Console\Commands\DocumentManagement\SweepDocumentRetention as SweepDocumentRetentionCommand;
use App\Console\Commands\Compliance\AnonymiseDataSubjectCommand;
use App\Console\Commands\Compliance\ExportDataSubjectCommand;
use App\Console\Commands\CRM\CheckSendingDns;
use App\Console\Commands\Disciplinary\EscalateOverdueDisciplinaryItemsCommand;
use App\Console\Commands\Finance\FinanceParityCheck;
use App\Console\Commands\Finance\GenerateRecurringInvoices;
use App\Console\Commands\Finance\ProcessFinancePostings;
use App\Console\Commands\Finance\SendDebtorReminders;
use App\Console\Commands\Finance\SendPeriodReminders;
use App\Console\Commands\Finance\VerifyLedgerChain;
use App\Console\Commands\Payroll\EnsureNextTaxYear;
use App\Console\Commands\PerformanceAppraisal\GeneratePaNudgesCommand;
use App\Console\Commands\PerformanceAppraisal\SendPaScheduledReportsCommand;
use App\Console\Commands\Procurement\ProcurementEscalationsCommand;
use App\Console\Commands\Procurement\ProcurementExpiryAlertsCommand;
use App\Console\Commands\Procurement\SendProcurementScheduledReportsCommand;
use App\Http\Middleware\AuditRequests;
use App\Http\Middleware\AuthLock;
use App\Http\Middleware\CheckBanned;
use App\Http\Middleware\EnforceCrudPermissions;
use App\Http\Middleware\ViewAcrossBusinesses;
use App\Http\Middleware\EnsureCompanyOwner;
use App\Http\Middleware\EnsureComplianceTraining;
use App\Http\Middleware\EnsureContentTier;
use App\Http\Middleware\EnsureMasterAdmin;
use App\Http\Middleware\EnsureModuleIsActive;
use App\Http\Middleware\EnsureModuleTier;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureReseller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventBackHistory;
use App\Http\Middleware\SecurityCheck;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackRequestId;
use App\Http\Middleware\TwoFactor;
use App\Http\Middleware\WatermarkInstance;
use App\Modules\Budget\Console\AdoptOrphanBudgetsCommand;
use App\Modules\Budget\Console\EscalateBudgetApprovalsCommand;
use App\Modules\Budget\Console\SyncAccountingBudgetsCommand;
use App\Modules\Budget\Console\TrackBudgetsCommand;
use App\Modules\HR\Leave\Console\AllocateLeaveCommand;
use App\Modules\HR\Leave\Console\BackfillLeaveAccrualCommand;
use App\Modules\HR\Leave\Console\DetectLeaveAnomaliesCommand;
use App\Modules\HR\Leave\Console\EscalateLeaveRequestsCommand;
use App\Modules\HR\Leave\Console\LeaveAnomalyDigestCommand;
use App\Modules\HR\Leave\Console\RemindLeaveApproversCommand;
use App\Modules\HR\Leave\Console\SendBirthdayLeaveCommand;
use App\Modules\HR\Leave\Console\SendLeaveBalancesCommand;
use App\Modules\HR\Leave\Console\SendLeaveScheduledReportsCommand;
use App\Modules\HR\Leave\Console\SickLeaveCycleCommand;
use App\Modules\HR\LMS\Console\RunCertificationChecksCommand;
use App\Modules\HR\LMS\Console\RunComplianceChecksCommand;
use App\Modules\HR\Recruitment\Jobs\TransitionVacancyToExternalJob;
use App\Modules\Operations\ProjectManagement\Console\DeriveFiguresCommand;
use App\Modules\Operations\ProjectManagement\Console\DrainOutboxCommand;
use App\Modules\Operations\ProjectManagement\Console\EscalateApprovalsCommand;
use App\Modules\Operations\ProjectManagement\Console\InstallContractTemplatesCommand as InstallPmContractTemplatesCommand;
use App\Modules\Operations\ProjectManagement\Console\EvidencePartitionsCommand;
use App\Modules\Operations\ProjectManagement\Console\GenerateRecurringTasksCommand;
use App\Modules\Operations\ProjectManagement\Console\HealthCommand;
use App\Modules\Operations\ProjectManagement\Console\ProvisionRolesCommand;
use App\Modules\Operations\ProjectManagement\Console\SeedPerformanceCommand;
use App\Modules\Operations\ProjectManagement\Console\SendProjectAlertsCommand;
use App\Modules\Operations\Manufacturing\Console\InstallReferenceDataCommand as InstallManufacturingReferenceData;
use App\Modules\Operations\Manufacturing\Console\EscalateFloorCommand;
use App\Modules\Operations\Manufacturing\Console\GenerateMaintenanceCommand;
use App\Modules\Operations\Manufacturing\Console\ScanLossesCommand;
use App\Modules\Operations\Manufacturing\Console\SendAlertsCommand;
use App\Modules\Operations\Manufacturing\Console\MeasureAssistantCommand;
use App\Modules\Operations\Manufacturing\Console\ReconcileAccountsCommand;
use App\Modules\Operations\Manufacturing\Console\SyncIntegrationsCommand;
use App\Modules\Operations\ProjectManagement\Console\SendTaskRemindersCommand;
use App\Modules\Operations\ProjectManagement\Console\SyncClientsCommand;
use App\Modules\Operations\ProjectManagement\Http\Middleware\EnforceIdempotency;
use App\Modules\Operations\ProjectManagement\Http\Middleware\RequireTenantContext;
use App\Modules\Operations\ProjectManagement\Support\Api\ProblemDetails;
use App\Modules\Operations\Sheq\Console\ComputeKpisCommand;
use App\Modules\Operations\Sheq\Console\EvaluateAiCommand;
use App\Modules\Operations\Sheq\Console\InstallInductionsCommand;
use App\Modules\ContractManagement\Console\InstallContractTemplatesCommand;
use App\Modules\SupplierManagement\Console\InstallProviderProfileCommand;
use App\Modules\SupplierManagement\Console\InstallSupplierDocumentsCommand;
use App\Modules\Operations\Sheq\Console\InstallInspectionTemplatesCommand;
use App\Modules\Operations\Sheq\Console\IndexKnowledgeCommand;
use App\Modules\Operations\Sheq\Console\InstallLibrariesCommand;
use App\Modules\Operations\Sheq\Console\ReviewRegulatoryPackCommand;
use App\Modules\Operations\Sheq\Console\SheqDoctorCommand;
use App\Modules\Operations\Sheq\Console\InstallRegulatoryPacksCommand;
use App\Modules\Operations\Sheq\Console\ReclassifyIncidentsCommand;
use App\Modules\Operations\Sheq\Console\RecomputeLegalRegisterCommand;
use App\Modules\Operations\Sheq\Console\RunAnalyticsCommand;
use App\Modules\Operations\Sheq\Console\SendNotificationsCommand;
use App\Modules\Operations\Sheq\Console\SweepEventsCommand;
use App\Modules\Operations\Sheq\Console\SyncSitesCommand;
use App\Modules\Policies\Console\EscalateOverdueAttestations;
use App\Modules\Policies\Console\SendAttestationReminders;
use App\Modules\Finance\Accounting\Console\AdoptOrphanedDocuments as AdoptOrphanedDocumentsCommand;
use App\Modules\Finance\Accounting\Console\DiagnoseBusinesses as DiagnoseBusinessesCommand;
use App\Modules\Procurement\Governance\Console\ComputeWorkloadCommand;
use App\Modules\Procurement\Governance\Console\GenerateReportsCommand;
use App\Modules\Procurement\Governance\Console\SweepNotificationsCommand;
use App\Modules\Procurement\Governance\Console\SyncChainCommand;
use App\Modules\Procurement\Governance\Console\VerifyLedgerCommand;
use App\Modules\Procurement\Governance\Http\Middleware\EnsureCapability;
use App\Modules\Tenancy\Console\BackfillTenantReferenceCommand;
use App\Modules\Tenancy\Console\CreateCompanyCommand;
use App\Modules\SupplierManagement\Console\InstallVerificationProvidersCommand;
use App\Modules\SupplierManagement\Console\MonitorSupplierReputationCommand;
use App\Modules\SupplierManagement\Console\SweepSupplierChecksCommand;
use App\Modules\SupplierManagement\Console\VerifyEvidenceLedgerCommand;
use App\Modules\Tenancy\Console\ExpireTrials;
use App\Modules\Tenancy\Console\TrialReminders;
use App\Modules\Tenancy\Console\RunSubscriptionBilling;
use App\Modules\Tenancy\Http\Middleware\ResolveCompany;
use App\Modules\Tenancy\Http\Middleware\ResolveCompanyFromToken;
use App\Modules\Tenancy\Http\Middleware\ResolvePublicPortalCompany;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        \App\Modules\Finance\Tax\Console\RunTaxComplianceCommand::class,
        \App\Modules\Finance\Tax\Console\DeliverTaxEventsCommand::class,
        \App\Modules\Workflow\Console\WorkflowTickCommand::class,
        \App\Modules\Agri\Console\ScanFarmAlerts::class,
        \App\Modules\Agri\Console\SeedAgriDemo::class,
        SendAttestationReminders::class,
        EscalateOverdueAttestations::class,
        AllocateLeaveCommand::class,
        BackfillLeaveAccrualCommand::class,
        SendBirthdayLeaveCommand::class,
        SickLeaveCycleCommand::class,
        DetectLeaveAnomaliesCommand::class,
        LeaveAnomalyDigestCommand::class,
        EscalateLeaveRequestsCommand::class,
        RemindLeaveApproversCommand::class,
        SendLeaveBalancesCommand::class,
        SendLeaveScheduledReportsCommand::class,
        CreateCompanyCommand::class,
        RunSubscriptionBilling::class,
        ExpireTrials::class,
        \App\Modules\Tenancy\Console\PurgeArchivedCompanies::class,
        TrialReminders::class,
        InstallVerificationProvidersCommand::class,
        MonitorSupplierReputationCommand::class,
        SweepSupplierChecksCommand::class,
        VerifyEvidenceLedgerCommand::class,
        BackfillTenantReferenceCommand::class,
        CheckSendingDns::class,
        AnonymiseDataSubjectCommand::class,
        ExportDataSubjectCommand::class,
        EscalateOverdueDisciplinaryItemsCommand::class,
        RunComplianceChecksCommand::class,
        RunCertificationChecksCommand::class,
        \App\Modules\HR\LMS\Console\InstallAgriLibraryCommand::class,
        SendPaScheduledReportsCommand::class,
        GeneratePaNudgesCommand::class,
        ProvisionRolesCommand::class,
        EvidencePartitionsCommand::class,
        GenerateRecurringTasksCommand::class,
        DeriveFiguresCommand::class,
        DrainOutboxCommand::class,
        SeedPerformanceCommand::class,
        HealthCommand::class,
        SyncClientsCommand::class,
        EscalateApprovalsCommand::class,
        InstallPmContractTemplatesCommand::class,
        SendProjectAlertsCommand::class,
        InstallManufacturingReferenceData::class,
        EscalateFloorCommand::class,
        GenerateMaintenanceCommand::class,
        ScanLossesCommand::class,
        SendAlertsCommand::class,
        MeasureAssistantCommand::class,
        ReconcileAccountsCommand::class,
        SyncIntegrationsCommand::class,
        SendTaskRemindersCommand::class,
        IndexKnowledgeCommand::class,
        InstallLibrariesCommand::class,
        InstallRegulatoryPacksCommand::class,
        // REG-FIX-2: a deployment can gate on this. REG-FIX-4/5: release and review provenance
        // are recorded by whoever ships a pack, not by each tenant.
        SheqDoctorCommand::class,
        ReviewRegulatoryPackCommand::class,
        SendNotificationsCommand::class,
        RecomputeLegalRegisterCommand::class,
        ComputeKpisCommand::class,
        SyncSitesCommand::class,
        App\Modules\HR\Onboarding\Console\SendOnboardingRemindersCommand::class,
        App\Modules\Operations\Sheq\Console\DrainOutboxCommand::class,
        SweepEventsCommand::class,
        ReclassifyIncidentsCommand::class,
        EvaluateAiCommand::class,
        InstallInductionsCommand::class,
        RunAnalyticsCommand::class,
        InstallInspectionTemplatesCommand::class,
        App\Modules\Operations\Sheq\Console\SendRemindersCommand::class,
        App\Modules\Operations\Sheq\Console\CloseStaleAttendanceCommand::class,
        App\Modules\Operations\Sheq\Console\ExpireOverridesCommand::class,
        App\Modules\Operations\Sheq\Console\RecalculateComplianceCommand::class,
        InstallContractTemplatesCommand::class,
        InstallSupplierDocumentsCommand::class,
        InstallProviderProfileCommand::class,
        SweepNotificationsCommand::class,
        ComputeWorkloadCommand::class,
        DiagnoseBusinessesCommand::class,
        AdoptOrphanedDocumentsCommand::class,
        VerifyLedgerCommand::class,
        GenerateReportsCommand::class,
        SyncChainCommand::class,
        TrackBudgetsCommand::class,
        EscalateBudgetApprovalsCommand::class,
        AdoptOrphanBudgetsCommand::class,
        SyncAccountingBudgetsCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        // Project Management: keep the evidence ledger's monthly partitions ahead of incoming
        // evidence. Global reference maintenance, so a single run rather than per-tenant.
        $schedule->command(EvidencePartitionsCommand::class, ['--months' => 6])
            ->monthlyOn(1, '01:15')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-evidence-partitions.log'));

        /*
         * Document Management: chase documents before they lapse, and mark the ones that have.
         *
         * Early enough that a warning is in somebody's inbox when they start the day, and
         * per-tenant inside the command — a sweep without a company set would see every
         * organisation's documents through one query and email one about another's certificates.
         */
        $schedule->command(SweepDocumentExpiryCommand::class)
            ->dailyAt('06:00')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/document-expiry.log'));

        /*
         * Document Management: recompute retention dates and queue what has come due.
         *
         * Weekly rather than daily, and after the expiry sweep rather than alongside it. A retention
         * date moves by years, so asking every morning produces the same answer six days out of
         * seven; and a queue that grows by a handful once a week is one somebody reads, where a
         * daily notification about documents due in five years is one they filter away.
         */
        $schedule->command(SweepDocumentRetentionCommand::class)
            ->weeklyOn(1, '06:30')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/document-retention.log'));

        // Project Management: materialise recurring tasks to each rule's horizon. Early, so the
        // day's list is complete before anyone opens the app; per-tenant fan-out inside the command.
        $schedule->command(GenerateRecurringTasksCommand::class)
            ->dailyAt('02:30')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-recurring-tasks.log'));

        // Project Management: bring derived figures up to date with the ledger, then check for
        // drift. Two runs rather than one flag, so the reconciliation report is about what the
        // *previous* day left behind rather than about work this run just did.
        $schedule->command(DeriveFiguresCommand::class, ['--reconcile'])
            ->dailyAt('02:45')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-derivation.log'));
        $schedule->command(DeriveFiguresCommand::class)
            ->dailyAt('03:15')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-derivation.log'));

        // SHEQ: keep sites in step with the company structure. Nightly and early, because a
        // department added in Company Settings this afternoon must be reportable tomorrow morning
        // — a hazard with nowhere to file it does not get filed.
        $schedule->command(SyncSitesCommand::class)
            ->dailyAt('03:10')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-sites.log'));

        // SHEQ: build the KPI read projection the dashboards read (SHEQ-ARC-4). Dashboards never
        // compute in-request — that would be a write on a page view and a different number for
        // whoever loaded it last. A trailing window, because incidents get reclassified and
        // exposure hours arrive after month end.
        $schedule->command(ComputeKpisCommand::class, ['--sites'])
            ->dailyAt('03:25')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-kpis.log'));

        // SHEQ: recompute the legal register from its evidence. Early, so the register is true
        // before anyone opens it — a schedule that lapsed overnight makes a clause non-compliant
        // whether or not somebody visited the page (SHEQ-LEG-3).
        $schedule->command(RecomputeLegalRegisterCommand::class)
            ->dailyAt('03:40')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-legal-register.log'));

        // SHEQ: publish the events time causes rather than a person — a deadline passing calls
        // no method. Hourly: often enough for an escalation to be useful, rare enough that the
        // already-published guard is doing little work.
        $schedule->command(SweepEventsCommand::class)
            ->hourly()->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-events.log'));

        // Onboarding: chase overdue tasks, imminent start dates and probation reviews falling due.
        // Weekday mornings — a reminder that lands on Saturday is read on Monday at best, and the
        // module previously had no scheduled work at all, so plans were created and never chased.
        $schedule->command(App\Modules\HR\Onboarding\Console\SendOnboardingRemindersCommand::class)
            ->weekdays()->at('07:30')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/onboarding-reminders.log'));

        // SHEQ: get domain events out to subscribed webhooks (§8.2). Every minute, because an
        // integration that learns about a fatality an hour late is not an integration.
        $schedule->command(App\Modules\Operations\Sheq\Console\DrainOutboxCommand::class)
            ->everyMinute()->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-outbox.log'));

        // SHEQ: drain the incident notification queue. Every minute, because SHEQ-INC-5 requires an
        // immediate multi-channel alert for a fatality or a section 24 event, and a five-minute
        // sweep would make "immediate" a lie. Per-tenant fan-out happens inside the command so RLS
        // stays active.
        $schedule->command(SendNotificationsCommand::class)
            ->everyMinute()->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-notifications.log'));

        // SHEQ: nightly analytics — trends, hotspots, recurrence, compliance forecast, contractor
        // scores. Overnight because these scan a year of incidents per tenant, and computing them
        // on a dashboard open would be slow for everybody to spare one job. Fans out per company
        // inside the command, so every query is RLS-scoped.
        /*
         * SHEQ: install the shipped libraries — regulatory packs, inspection templates,
         * inductions. Nightly and early, before analytics and the KPI build read them.
         *
         * These were only ever run by hand, and nothing ran them: a deployment nobody remembered
         * them on held no regulatory packs, so no tenant could activate one and no incident could
         * be reported at all. Each underlying command skips content whose checksum is unchanged,
         * so the steady-state cost is two YAML reads.
         */
        $schedule->command(InstallLibrariesCommand::class)
            ->dailyAt('01:40')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-libraries.log'));

        /*
         * SHEQ: index the material the AI agents answer from.
         *
         * Nothing called KnowledgeRetriever::index(), so the knowledge base was empty on every
         * installation; retrieval returned nothing, and because a claim citing nothing is refused,
         * every agent correctly declined every question. Nightly, after the libraries install and
         * before analytics, and cheap to repeat — a chunk is keyed on its content hash, so
         * re-indexing unchanged material is a no-op.
         */
        $schedule->command(IndexKnowledgeCommand::class)
            ->dailyAt('02:05')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-knowledge.log'));

        $schedule->command(RunAnalyticsCommand::class)
            ->dailyAt('02:20')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-analytics.log'));

        /*
         * SHEQ: the reminders that reach people rather than webhooks.
         *
         * `sheq:events:sweep` already announces a lapsing competency, a stale procedure and an
         * overdue action into the outbox, where an integration can read them. Nothing addressed a
         * person: the notification matrix is keyed on incident events and has no way to say "tell
         * the holder of this certificate". So the 90/60/30/14/7/0-day expiry ladder, the
         * review-due notice to a document's owner and the chase to a supplier sitting on an
         * invitation were all written and never sent.
         *
         * Weekday mornings at 07:00, before the working day: a reminder that lands on Saturday is
         * read on Monday at best. Safe to run repeatedly — each reminder is claimed against the
         * dispatch dedupe key, so a second pass in the same day queues nothing, and a gate missed
         * to an outage is picked up on the next morning rather than lost for good.
         */
        /*
         * SHEQ: recompute compliance where a date, not a person, changed it (COM-4).
         *
         * Scheduled BEFORE the reminder sweep on purpose. Compliance recalculated whenever
         * somebody did something — attached a document, decided one, moved a safety file — and
         * never when nobody did anything, which is what an expiry is: a date going past writes
         * no row. So a lapsed policy left `access_status` reading COMPLIANT until something
         * unrelated happened to touch that contractor.
         *
         * The gate was right the whole time, which is what made it invisible: the decision engine
         * evaluates live. Everything else — the register, the dashboard, the contractor's portal,
         * the notices, the score — reads the stored status. The host saw COMPLIANT, nobody was
         * told, and the first anybody knew was a crew turned away with the register insisting
         * they should have been let in.
         *
         * At 06:15 so the 07:00 reminders chase this morning's state rather than yesterday's.
         */
        $schedule->command(App\Modules\Operations\Sheq\Console\RecalculateComplianceCommand::class)
            ->dailyAt('06:15')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-compliance.log'));

        $schedule->command(App\Modules\Operations\Sheq\Console\SendRemindersCommand::class)
            ->weekdays()->at('07:00')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-reminders.log'));

        /*
         * SHEQ: close the temporary access overrides whose window has shut (CON-17).
         *
         * Hourly, not nightly. An override granted to 18:00 that goes on working until the
         * small hours is a gate nobody meant to leave open, and the point of a window with an
         * end on it is that the end means something. The run is cheap — it touches only the
         * rows whose `ends_at` has passed since the last pass.
         */
        $schedule->command(App\Modules\Operations\Sheq\Console\ExpireOverridesCommand::class)
            ->hourly()->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-overrides.log'));

        /*
         * SHEQ: close the site attendance nobody signed out of (CON-16).
         *
         * 02:30, every day including weekends — a site that works Saturday has the same problem
         * on Sunday morning, and a weekday-only sweep leaves the roll-call wrong for the two
         * days when the fewest people are around to notice.
         *
         * Without it every forgotten exit stays open for ever: within a month the roll-call
         * reads as hundreds of people on a site that holds forty, and an evacuation list
         * nobody believes is worse than none.
         */
        $schedule->command(App\Modules\Operations\Sheq\Console\CloseStaleAttendanceCommand::class)
            ->dailyAt('02:30')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-attendance.log'));

        // SHEQ: score the AI agents against the §7.4 release gate. Weekly rather than nightly —
        // the metrics move with reviewed drafts, not with the clock, and a daily run would just
        // restate the same figure six times before anything changed.
        $schedule->command(EvaluateAiCommand::class)
            ->weeklyOn(1, '05:30')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/sheq-ai-eval.log'));

        // Project Management: get domain events out to subscribed webhooks. Frequent, because an
        // integration that learns about a completed task an hour late is not an integration.
        $schedule->command(DrainOutboxCommand::class)
            ->everyFiveMinutes()->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-outbox.log'));

        // Project Management: keep client names in step with CRM on tenants that have it. Refresh
        // only — adoption changes which module owns a customer record, so it stays a button on the
        // setup page rather than something a nightly job decides for somebody.
        $schedule->command(SyncClientsCommand::class)
            ->dailyAt('04:10')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-clients.log'));

        // Project Management: chase approvals that have sat past the flow's own escalation window.
        // Hourly, because the point of an escalation window measured in hours is that somebody
        // hears about it within the hour it expires.
        $schedule->command(EscalateApprovalsCommand::class)
            ->hourly()->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-approvals.log'));

        // Project Management: budget alerts and deadline reminders (§27). Once each weekday
        // morning — a reminder that arrives at the weekend is one nobody reads on Monday, and one
        // that arrives hourly is one people filter.
        $schedule->command(SendProjectAlertsCommand::class)
            ->weekdays()->dailyAt('07:00')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-alerts.log'));

        /*
         * Project Management: what each person owes today, and what they already owed.
         *
         * SendProjectAlertsCommand tells whoever runs a project that deadlines are approaching;
         * this is the message to whoever actually holds the task. Weekdays, early, and one message
         * per person covering everything they hold — a dozen separate emails is how a mail rule
         * gets written, and the rule catches the thirteenth too.
         */
        $schedule->command(SendTaskRemindersCommand::class)
            ->weekdays()->dailyAt('07:10')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-task-reminders.log'));

        // Project Management: the invariants that fail silently — a stalled outbox, evidence
        // rejected and never looked at, partitions about to run out. Non-zero exit is the signal.
        $schedule->command(HealthCommand::class)
            ->dailyAt('06:20')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pm-health.log'));

        // `onOneServer` on all three below: each one sends email, and a second web node running
        // the same sweep would send every budget owner their alert twice.
        // Budget Management: evaluate every budget against its company's alert rules. Early, so a
        // budget that crossed 90% overnight is on the tracking page and in an inbox before anybody
        // raises the purchase order that would have been refused. Per-tenant fan-out inside the
        // command, because the rules and the people to tell are both tenant-scoped.
        // Mkhaya Agri: raise the day's farm alerts (low stock, services, expiring permits, overdue
        // tasks, withdrawal periods) before the farm day starts, and clear the ones resolved.
        $schedule->command(\App\Modules\Agri\Console\ScanFarmAlerts::class)
            ->dailyAt('04:30')->withoutOverlapping()->onOneServer()
            ->appendOutputTo(storage_path('logs/agri-alerts.log'));

        $schedule->command(TrackBudgetsCommand::class)
            ->dailyAt('05:05')->withoutOverlapping()->onOneServer()
            ->appendOutputTo(storage_path('logs/budget-tracking.log'));

        // Budget Management: repair any accounting-budget mirror the immediate sync missed, and
        // bring across the history of a company that installed this module after it had already
        // been budgeting in Accounting. Before the tracking sweep, so tonight's alerts are measured
        // against tonight's allocations.
        $schedule->command(SyncAccountingBudgetsCommand::class)
            ->dailyAt('04:50')->withoutOverlapping()->onOneServer()
            ->appendOutputTo(storage_path('logs/budget-accounting-sync.log'));

        // Budget Management: chase budget approvals past their flow's escalation window. Hourly,
        // because the point of a window measured in hours is that somebody hears within the hour.
        $schedule->command(EscalateBudgetApprovalsCommand::class)
            ->hourly()->withoutOverlapping()->onOneServer()
            ->appendOutputTo(storage_path('logs/budget-approvals.log'));

        // Procurement Governance: the §9 reminder sweep and escalation pass. Daily and early, so
        // an approver's queue and an evaluator's deadline reach them before the working day starts;
        // per-tenant fan-out happens inside the command so row-level security stays active.
        $schedule->command(SweepNotificationsCommand::class)
            ->dailyAt('05:15')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/procgov-notifications.log'));

        // Procurement Governance: the officer workload snapshot and capacity detection (§4.3). The
        // dashboard is computed live, so a missed run costs a day of trend history rather than a
        // broken screen.
        $schedule->command(ComputeWorkloadCommand::class)
            ->dailyAt('04:40')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/procgov-workload.log'));

        // Procurement Governance: reconcile the §11 chain — link each procurement to the
        // requisitions, orders, contracts and invoices raised for it in the neighbouring modules.
        // Nightly rather than only at award time, because those documents are created afterwards
        // by other people: a contract signed on Tuesday must appear on the record on Wednesday.
        $schedule->command(SyncChainCommand::class)
            ->dailyAt('04:15')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/procgov-chain.log'));

        // Procurement Governance: verify the §7 audit chain. Non-zero exit is the signal — a
        // tamper check nobody looks at is not a tamper check.
        $schedule->command(VerifyLedgerCommand::class)
            ->dailyAt('06:25')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/procgov-ledger.log'));

        // Procurement Governance: quarterly and annual reports (§3). Runs on the 1st; the command
        // generates the period that has just *closed*, never the one in progress.
        $schedule->command(GenerateReportsCommand::class)
            ->monthlyOn(1, '05:45')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/procgov-reports.log'));

        // Finance: drain the posting outbox (safety net) + daily GL/subledger parity.
        $schedule->command(ProcessFinancePostings::class)
            ->everyFiveMinutes()->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/finance-postings.log'));
        $schedule->command(FinanceParityCheck::class)
            ->dailyAt('06:00')
            ->appendOutputTo(storage_path('logs/finance-parity.log'));
        // Tamper-evidence: verify the cryptographic journal chain daily.
        $schedule->command(VerifyLedgerChain::class)
            ->dailyAt('06:10')
            ->appendOutputTo(storage_path('logs/finance-chain.log'));
        // Debtor auto-reminders (configurable cadence; no-op unless enabled).
        $schedule->command(SendDebtorReminders::class)
            ->dailyAt('08:00')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/finance-reminders.log'));
        // Fiscal-period closing reminders (per company).
        $schedule->command(SendPeriodReminders::class)
            ->dailyAt('07:30')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/finance-reminders.log'));
        // Recurring invoices — generate schedules that are due.
        $schedule->command(RunSubscriptionBilling::class)->monthlyOn(1, '02:00')->withoutOverlapping();
        // Suspend companies whose free trial has ended (blocks access until reactivated/paid).
        $schedule->command(ExpireTrials::class)->dailyAt('00:30')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/tenancy-trials.log'));
        // Companies converted to resellers: delete their archived data once the retention period ends.
        $schedule->command(\App\Modules\Tenancy\Console\PurgeArchivedCompanies::class)->dailyAt('03:40')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/tenancy-purge.log'));
        // Supplier checks: re-run whatever has gone stale, per check and per risk band. Separate
        // from the reputation sweep because they cost different money and fail differently.
        $schedule->command(SweepSupplierChecksCommand::class)->dailyAt('02:45')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/supplier-monitoring.log'));
        // Supplier reputation: high/critical suppliers weekly, the rest monthly — the command works
        // out which are due, so this fires nightly and does the right amount of work.
        $schedule->command(MonitorSupplierReputationCommand::class)->dailyAt('03:15')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/supplier-monitoring.log'));
        // Warn platform admins BEFORE a trial ends (14/7/3/1/0 days out, plus once on lapse),
        // so it is extended or converted instead of the client discovering the lock-out.
        // Runs after ExpireTrials so a trial that lapsed overnight is reported as lapsed.
        $schedule->command(TrialReminders::class)->dailyAt('06:00')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/tenancy-trials.log'));
        $schedule->command(GenerateRecurringInvoices::class)
            ->dailyAt('05:30')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/finance-recurring.log'));

        // Payroll — after the annual budget speech (late Feb), make sure the new SARS
        // tax year exists as a provisional carried-forward row for admins to confirm
        // before payroll runs from 1 March. National reference data, single global run.
        $schedule->command(EnsureNextTaxYear::class)
            ->yearlyOn(2, 26, '05:00')->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/payroll-tax-year.log'));

        $schedule->command(SendAttestationReminders::class, ['--days=3'])
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/attestation-reminders.log'));

        $schedule->command(EscalateOverdueAttestations::class)
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/attestation-escalations.log'));

        // Monthly leave accrual on the 1st — PER COMPANY: no --sync means the
        // command fans out one queued job per active/trial tenant (RLS-scoped),
        // so each company's own cycle + accrual rules are applied. Scales to 1000+
        // tenants; needs a worker on the 'tenants' queue.
        $schedule->command(AllocateLeaveCommand::class, ['monthly'])
            ->monthlyOn(1, '01:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/leave-allocation-monthly.log'));

        // Daily: advance any company whose cycle has ended (close + carry-over +
        // forfeiture + grant next year's entitlement). Fans out per tenant too.
        $schedule->command(AllocateLeaveCommand::class, ['advance'])
            ->dailyAt('00:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/leave-cycle-advance.log'));

        // Monthly: open/reset each employee's BCEA 36-month sick-leave cycle and
        // grant the sick entitlement (idempotent — only acts when a cycle rolls).
        $schedule->command(SickLeaveCycleCommand::class)
            ->monthlyOn(1, '01:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/leave-sick-cycle.log'));

        // Weekly: scan every company for leave account-setup / ledger anomalies, then
        // alert HR about anything still open (repeats weekly until resolved/dismissed).
        $schedule->command(DetectLeaveAnomaliesCommand::class)
            ->weeklyOn(1, '02:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/leave-anomaly-detect.log'));

        $schedule->command(LeaveAnomalyDigestCommand::class)
            ->weeklyOn(1, '02:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/leave-anomaly-digest.log'));

        // Transition InternalFirstThenExternal vacancies whose internal deadline has passed
        $schedule->job(new TransitionVacancyToExternalJob)
            ->daily()
            ->withoutOverlapping();

        // Disciplinary: escalate overdue investigations, hearings, notices and policy violations
        $schedule->command(EscalateOverdueDisciplinaryItemsCommand::class)
            ->dailyAt('07:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/disciplinary-escalations.log'));

        // Procurement: watchdog for overdue approvals, delivery delays, budget overruns, policy violations
        $schedule->command(ProcurementEscalationsCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/procurement-escalations.log'));

        // LMS Compliance: track overdue + send reminders daily at 08:30
        $schedule->command(RunComplianceChecksCommand::class, ['--reminders'])
            ->dailyAt('08:30')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/lms-compliance-reminders.log'));

        // LMS Compliance: escalate after-hours sweep at 09:30
        $schedule->command(RunComplianceChecksCommand::class, ['--escalate'])
            ->dailyAt('09:30')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/lms-compliance-escalations.log'));

        // LMS Compliance: assign all active rules weekly on Monday
        $schedule->command(RunComplianceChecksCommand::class, ['--assign'])
            ->weeklyOn(1, '06:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/lms-compliance-assign.log'));

        // LMS Certification: mark expired certs + send reminders daily at 07:30
        $schedule->command(RunCertificationChecksCommand::class, ['--expire', '--reminders'])
            ->dailyAt('07:30')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/lms-certification-checks.log'));

        // LMS Certification: assign refresher training weekly on Tuesday at 06:00
        $schedule->command(RunCertificationChecksCommand::class, ['--refresher'])
            ->weeklyOn(2, '06:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/lms-certification-refresher.log'));

        // Performance: deliver due scheduled reports + generate proactive nudges.
        $schedule->command(SendPaScheduledReportsCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pa-scheduled-reports.log'));

        $schedule->command(GeneratePaNudgesCommand::class)
            ->dailyAt('06:30')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pa-nudges.log'));

        // Procurement: auto-expire contracts + contract renewal & supplier-document
        // expiry alerts.
        $schedule->command(ProcurementExpiryAlertsCommand::class)
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/procurement-expiry-alerts.log'));

        // Procurement: deliver due contract/supplier scheduled reports.
        $schedule->command(SendProcurementScheduledReportsCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/procurement-scheduled-reports.log'));
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->group('api', [
            'throttle:api',
            // One correlation id per request, echoed on the response and stamped on PM audit rows.
            TrackRequestId::class,
            // Multi-tenancy for API traffic. Without this the group carried no tenant at all:
            // CompanyScope did not filter, and an unset app.company_id makes the RLS policy permit
            // every row — so API endpoints were tenant-isolated only as far as each controller
            // remembered to filter. The tenant comes from the token's user, never a parameter.
            //
            // MUST run before SubstituteBindings: route model binding queries the database, so a
            // binding resolved before the tenant is pinned would happily load another tenant's
            // record by id.
            ResolveCompanyFromToken::class,
            SubstituteBindings::class,
        ]);

        $middleware->web(remove: [
            // Re-inserted below, after the tenant is pinned. Laravel puts SubstituteBindings at the
            // end of the default web group, which is *before* anything appended here — so with
            // ResolveCompany appended in the usual way, every `{project}`, `{invoice}`, `{employee}`
            // binding on the site resolved with no active company: CompanyScope inactive, so a
            // foreign uuid in the URL loaded that tenant's record and the page rendered it. The
            // only thing standing between that and a leak was whatever each controller happened to
            // re-check. Same ordering bug as the api group above, three positions apart.
            SubstituteBindings::class,
        ], append: [
            TrackRequestId::class,
            // Multi-tenancy: pin the request to the authenticated user's company so
            // the CompanyScope filters data to that tenant. No-op for guests.
            ResolveCompany::class,
            SubstituteBindings::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetLocale::class,
            AuditRequests::class,
            WatermarkInstance::class,
            // Enforces the password-expiry policy (change every N months) and
            // account-lock. Self-guards: skips guests + the password-change /
            // logout / 2FA routes, so it's safe on public pages.
            SecurityCheck::class,
        ]);

        // Payment gateways post server-to-server notifications without a CSRF token.
        $middleware->validateCsrfTokens(except: [
            'pay/notify/*',
            // RFC 8058 one-click unsubscribe: the mail client posts this on the person's behalf
            // and carries no CSRF token. The per-recipient token is the authorisation.
            'crm/u/*',
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'crud.permissions' => EnforceCrudPermissions::class,
            'module.active' => EnsureModuleIsActive::class,
            'content.tier' => EnsureContentTier::class,
            'module.tier' => EnsureModuleTier::class,
            // Reading a tenant's businesses added together, for one GET. See the middleware.
            'finance.group-view' => ViewAcrossBusinesses::class,
            // §12: gates one capability inside Procurement Governance, on top of the module gate.
            'procgov.capability' => EnsureCapability::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'reseller' => EnsureReseller::class,
            'company.owner' => EnsureCompanyOwner::class,
            'company.resolve' => ResolveCompany::class,
            'portal.company' => ResolvePublicPortalCompany::class,
            'master.admin' => EnsureMasterAdmin::class,
            'auth.lock' => AuthLock::class,
            '2fa' => TwoFactor::class,
            'prevent-back-history' => PreventBackHistory::class,
            'check.banned' => CheckBanned::class,
            'compliance.check' => EnsureComplianceTraining::class,
            // Project Management API: refuse a request that resolved no tenant, and make a
            // mutation safe to retry when the client sends an Idempotency-Key.
            'pm.tenant' => RequireTenantContext::class,
            'sheq.tenant' => App\Modules\Operations\Sheq\Http\Middleware\RequireTenantContext::class,
            'pm.idempotent' => EnforceIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // PM API errors are RFC 7807 problem+json (§4 of the PM requirements), so a client has one
        // error shape to handle instead of a different one per endpoint. Registered first so it
        // wins for /api/v1/pm/*; every other route falls through to the handlers below.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/v1/pm/*')) {
                return null;
            }

            return ProblemDetails::fromThrowable($e, $request);
        });

        // SHEQ API errors are RFC 9457 problem details (SHEQ-API-4), for the same reason as PM
        // above: one error shape for an integrator to handle. Registered here rather than as route
        // middleware because Laravel's priority sorting hoists `auth:sanctum` ahead of unlisted
        // middleware — so a 401, the failure a client hits first, would be the one response that
        // never reached it.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/v1/sheq/*')) {
                return null;
            }

            return App\Modules\Operations\Sheq\Http\Api\ProblemDetails::fromException($e,
                (string) Str::uuid());
        });

        /*
         * SHEQ refusals reach the person, not a stack trace.
         *
         * The module signals every refused business rule by throwing with a message already
         * written for whoever is reading it. The API has turned those into problem documents since
         * it was written; on a screen the same refusal rendered "Internal Server Error" with a
         * class name and a file path. Registered after the API handlers above so they keep their
         * own shape, and it returns null for anything it does not own.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            return App\Modules\Operations\Sheq\Exceptions\RefusedRule::render($e, $request);
        });

        // A platform administrator has no company of their own; creating a workflow, form or
        // calendar without switching into one gets a message, not a 500.
        $exceptions->render(function (App\Modules\Tenancy\Exceptions\TenantContextMissingException $e, Request $request) {
            if (! $request->is('workflow/*') || $request->expectsJson() || $request->isMethod('GET')) {
                return null;
            }

            return back()->with('error', 'Switch into a company first: workflows, forms and calendars belong to a company.');
        });

        // 419 "Page Expired" — never show it. A CSRF token only goes stale
        // because the session rotated underneath the open tab (idle lock,
        // session lifetime elapsed, login elsewhere, `php artisan ...` restart
        // in dev). The only useful recovery is to reload the page the user was
        // on, which mints a fresh token and lands them wherever the session
        // state now says they belong (e.g. the lock screen or login).
        //
        // Hooked on HttpException rather than TokenMismatchException because
        // Laravel's handler converts the latter to a 419 HttpException before
        // render callbacks run; returning null falls through to normal handling
        // for every other HTTP status.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            // Reload the page the request came from. Only trust a same-host
            // referer — an attacker-supplied one would turn this into an open
            // redirect — and fall back to the session's previous URL, then home.
            // (url()->previous() is not used as the fallback — it trusts the
            // referer too, which would defeat the host check.)
            $referer = (string) $request->headers->get('referer');
            $fallback = ($request->hasSession() ? $request->session()->previousUrl() : null) ?: url('/');
            $target = $referer !== '' && parse_url($referer, PHP_URL_HOST) === $request->getHost()
                ? $referer
                : $fallback;

            // Inertia XHR: 409 + X-Inertia-Location tells the client to do a
            // full page visit — a real browser reload — instead of popping the
            // error-response modal that renders Laravel's 419 page.
            if ($request->header('X-Inertia')) {
                return Inertia::location($target);
            }

            // Plain XHR / fetch (axios interceptor in app.ts reloads on this).
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('Your session expired. Reloading…'),
                    'reload' => true,
                ], 419);
            }

            return redirect()->to($target);
        });
    })
    ->create();
