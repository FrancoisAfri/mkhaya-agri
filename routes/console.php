<?php

use App\Console\Commands\Attendance\AutoApplyMonthlyLeave;
use App\Console\Commands\Communication\DispatchScheduledCampaigns;
use App\Console\Commands\Attendance\SendAbsentUsersReport;
use App\Modules\HR\Leave\Console\EscalateLeaveRequestsCommand;
use App\Modules\HR\Leave\Console\RemindLeaveApproversCommand;
use App\Modules\HR\Leave\Console\SendBirthdayLeaveCommand;
use App\Modules\HR\Leave\Console\SendLeaveBalancesCommand;
use App\Modules\HR\Leave\Console\SendLeaveScheduledReportsCommand;
use App\Console\Commands\PerformanceAppraisal\SendPaRemindersCommand;
use App\Console\Commands\Fleet\FleetDetectAnomaliesCommand;
use App\Console\Commands\Fleet\SyncFleetIntegrationsCommand;
use App\Console\Commands\Fleet\FleetPredictMaintenanceCommand;
use App\Console\Commands\Fleet\FleetWeeklyAnomalyDigestCommand;
use App\Console\Commands\Assets\SendAssetScheduledReportsCommand;
use App\Console\Commands\Fleet\SendFleetScheduledReportsCommand;
use App\Console\Commands\Attendance\AttendanceAnomalyDigest;
use App\Console\Commands\Attendance\DetectAttendanceAnomalies;
use App\Console\Commands\Attendance\ProcessAbsences;
use App\Console\Commands\Attendance\SyncErsClockings;
use App\Console\Commands\CRM\PollCrmMailboxes;
use App\Console\Commands\CRM\EscalateComplaints;
use App\Console\Commands\CRM\ProcessSurveys;
use App\Console\Commands\CRM\RunSequences;
use App\Console\Commands\Disciplinary\SendDisciplinaryScheduledReports;
use App\Console\Commands\Payroll\SendPayrollScheduledReports;
use App\Console\Commands\Policies\SendPolicyScheduledReports;
use App\Console\Commands\LMS\CheckQctoAccreditationExpiry;
use App\Console\Commands\LMS\CheckWspAtrDeadline;
use App\Console\Commands\LMS\SendLmsScheduledReportsCommand;
use App\Console\Commands\LMS\SendRegistrationExpiryAlertsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Licensing heartbeat (remote revocation) ──────────────────────────────────
// No-op unless LICENSE_REMOTE is enabled; checks in with the authority hourly.
// ── Insights: report schedules set up on each category's Insights page ──────
Schedule::command(\App\Console\Commands\Insights\SendScheduledReports::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command(\App\Console\Commands\License\LicenseHeartbeat::class)
    ->hourly()
    ->withoutOverlapping();

// ── Per-tenant storage metering ──────────────────────────────────────────────
// Nightly recompute of each company's database data footprint (files are counted
// live). Powers the storage meter / quota. Heavy scan → off-peak, one server.
Schedule::command(\App\Console\Commands\Tenancy\RecomputeStorageUsage::class)
    ->dailyAt('01:15')
    ->onOneServer()
    ->withoutOverlapping();

// ── Leave: Birthday Wishes & Birthday Leave Allocation ───────────────────────
// Runs daily at 08:00 — sends birthday wishes + allocates leave 7 days in advance
Schedule::command(SendBirthdayLeaveCommand::class)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/birthday-leave.log'));

// NOTE: the leave accrual engine (leave:allocate monthly/advance), the BCEA
// sick-leave cycle and the leave anomaly scan/digest are scheduled in
// bootstrap/app.php, not here. Do not add them again — a second leave:allocate
// entry would allocate balances twice.

// ── Leave: approval chasing & statements ─────────────────────────────────────
// Weekdays 09:00 — nudge approvers sitting on requests, before escalation bites.
Schedule::command(RemindLeaveApproversCommand::class)
    ->weekdays()
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/leave-approver-reminders.log'));

// Weekdays 21:00 — escalate anything left past the configured SLA.
Schedule::command(EscalateLeaveRequestsCommand::class)
    ->weekdays()
    ->dailyAt('21:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/leave-escalation.log'));

// 1st of the month 07:00 — email every employee their leave balances.
Schedule::command(SendLeaveBalancesCommand::class)
    ->monthlyOn(1, '07:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/leave-balances.log'));

// Hourly — the command decides what is actually due from next_run_at.
Schedule::command(SendLeaveScheduledReportsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/leave-scheduled-reports.log'));

// ── Attendance ERS & Absence Automation ──────────────────────────────────────
// Pull biometric clock data from ERS (weekdays at 23:30)
Schedule::command(SyncErsClockings::class)
    ->weekdays()
    ->dailyAt('23:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ers-sync.log'));

// Email the day's absentee list to the configured recipients (weekdays at 10:00)
Schedule::command(SendAbsentUsersReport::class)
    ->weekdays()
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/absent-users-report.log'));

// Detect absences, email employees, trigger auto-apply (weekdays at 23:00)
Schedule::command(ProcessAbsences::class)
    ->weekdays()
    ->dailyAt('23:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/absence-process.log'));

// Weekly: scan attendance anomalies (incl. unauthorised absence) then alert HR about
// anything still open (repeats weekly until resolved). Per tenant + self_hosted.
Schedule::command(DetectAttendanceAnomalies::class)
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/attendance-anomaly-detect.log'));

Schedule::command(AttendanceAnomalyDigest::class)
    ->weeklyOn(1, '03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/attendance-anomaly-digest.log'));

// End-of-month catch-all auto-apply leave (last day of month at 23:55)
Schedule::command(AutoApplyMonthlyLeave::class)
    ->monthlyOn(28, '23:55')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/auto-apply-leave.log'));

// Policy scheduled reports — runs every hour, command checks next_run_at internally
Schedule::command(SendPolicyScheduledReports::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/policy-scheduled-reports.log'));

// CRM lead-capture mailbox polling — every 15 minutes, AI-filters inbound email into leads and
// records replies to sequences. --sync for the same reason as the sequence runner below: queued
// poll jobs sat on a host with no worker, so no reply was ever seen and stop-on-reply never fired.
Schedule::command(PollCrmMailboxes::class, ['--sync'])
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/crm-mailbox-poll.log'));

// CRM complaint SLA escalation — hourly, escalates breached complaints
Schedule::command(EscalateComplaints::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/crm-complaint-escalation.log'));

// CRM survey lifecycle — hourly: send scheduled surveys, close + AI-analyse ended ones
Schedule::command(ProcessSurveys::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/crm-surveys.log'));

// CRM SDR sequences — hourly: send due cadence steps. --sync processes each company inline:
// without it the run only dispatched jobs to the `tenants` queue, which nothing works on this
// estate, so step 1 went out (sent by "Run now" from the screen) and no later step ever did.
Schedule::command(RunSequences::class, ['--sync'])
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/crm-sequences.log'));

// CRM held deals — daily at 06:10: returns deals to the pipeline on the date the client asked
// for. --sync because this estate has no queue worker; the dispatched jobs would simply sit.
Schedule::command(\App\Console\Commands\CRM\WakeHeldDeals::class, ['--sync'])
    ->dailyAt('06:10')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/crm-held-deals.log'));

// Fleet scheduled reports — hourly, command checks next_run_at internally
Schedule::command(SendFleetScheduledReportsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/fleet-scheduled-reports.log'));

// Disciplinary scheduled reports — hourly, command checks next_run_at internally
Schedule::command(SendDisciplinaryScheduledReports::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/disciplinary-scheduled-reports.log'));

// Asset scheduled reports — hourly, command checks next_run_at internally
Schedule::command(SendAssetScheduledReportsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/asset-scheduled-reports.log'));

// Payroll scheduled reports — hourly, command checks next_run_at internally
Schedule::command(SendPayrollScheduledReports::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/payroll-scheduled-reports.log'));

// CRM scheduled reports — every five minutes, command checks next_run_at internally
Schedule::command(\App\Console\Commands\CRM\RunCrmScheduledReports::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/crm-scheduled-reports.log'));

// CRM marketing campaigns — every five minutes, dispatches due scheduled sends
Schedule::command(\App\Console\Commands\CRM\RunCrmCampaigns::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/crm-campaigns.log'));

// ── Fleet Integrations (FR-7) ─────────────────────────────────────────────────
// Telematics: every 15 minutes — positions and events change frequently
Schedule::command(SyncFleetIntegrationsCommand::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/fleet-integrations.log'));

// ── Fleet AI ──────────────────────────────────────────────────────────────────
// FR-4.1 — Daily predictive maintenance scoring (02:00)
Schedule::command(FleetPredictMaintenanceCommand::class)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/fleet-predict.log'));

// FR-4.3 — Daily fuel & spend anomaly detection (03:00)
Schedule::command(FleetDetectAnomaliesCommand::class)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/fleet-anomaly.log'));

// FR-4.3.6 — Weekly anomaly digest email (Mondays at 07:00)
Schedule::command(FleetWeeklyAnomalyDigestCommand::class)
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/fleet-anomaly-digest.log'));

// ── Email Campaigns ───────────────────────────────────────────────────────────
// Every 5 minutes — dispatches campaigns whose scheduled_at has passed
Schedule::command(DispatchScheduledCampaigns::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/email-campaigns.log'));

// ── Performance Appraisal Reminders ───────────────────────────────────────────
// Daily at 07:30 — sends day-before and day-of reminders for check-ins and coaching sessions
Schedule::command(SendPaRemindersCommand::class)
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/pa-reminders.log'));

// ── LMS QCTO Accreditation Expiry ─────────────────────────────────────────────
// Daily at 07:00 — alerts LMS admins when SETA accreditations expire within 30/60/90 days
Schedule::command(CheckQctoAccreditationExpiry::class)
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/lms-accreditation-expiry.log'));

// ── LMS WSP/ATR 30 April Deadline ─────────────────────────────────────────────
// Daily at 07:15 — alerts SDFs and admins at 60/30/14/7/1 days before 30 April
Schedule::command(CheckWspAtrDeadline::class)
    ->dailyAt('07:15')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/lms-wsp-atr-deadline.log'));

// ── LMS Registration Expiry Alerts ────────────────────────────────────────────
// Weekly on Monday 06:30 — emails HR/Admin about assessors, moderators, SDFs expiring within 6 months
Schedule::command(SendRegistrationExpiryAlertsCommand::class)
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/lms-registration-expiry-alerts.log'));

// ── LMS Scheduled Reports ──────────────────────────────────────────────────────
// Hourly — generates and emails due LMS scheduled reports (command checks next_run_at internally)
Schedule::command(SendLmsScheduledReportsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/lms-scheduled-reports.log'));

// ── Payroll: employment milestones ───────────────────────────────────────────
//
// Daily at 06:00 — find the employees who have reached a configured milestone and tell whoever has
// to act on it, on both payroll engines. Early enough that an administrator opening the payroll
// dashboard sees today's incentive reviews before they start preparing a run, rather than after.
//
// Each notice is claimed against a unique index before it is sent, so this is safe to run twice and
// safe to re-run by hand after an outage — the alternative, firing only on the exact anniversary
// date, loses a reminder to any weekend or hour of downtime it happens to land in.
Schedule::command(\App\Console\Commands\Payroll\ScanEmploymentMilestonesCommand::class)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/payroll-milestones.log'));

// ── Payroll audit trail integrity ────────────────────────────────────────────
// The triggers refuse UPDATE and DELETE outright; the hash chain covers the case they cannot —
// somebody with rights to drop a trigger — by making the tampering detectable rather than
// preventing it. Detectable only helps if somebody looks, and nobody looks at a chain by hand, so
// it is walked weekly. Off-peak on Sunday because it reads the whole table, and one server because
// two passes over the same rows tell you nothing the first did not.
Schedule::command(\App\Console\Commands\Payroll\VerifyPayrollAudit::class)
    ->weeklyOn(0, '02:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/payroll-audit-verify.log'));

// ── Live bank feeds ──────────────────────────────────────────────────────────
// Banks post overnight, so the statement is pulled before the working day rather than when somebody
// remembers to press refresh. Only feeds set to `daily` are touched — `login` refreshes when its
// owner opens the screen, and `manual` means the user asked to be left alone. One server, because
// two passes over the same feed just spend a metered API call to import nothing.
Schedule::command(\App\Console\Commands\Finance\RefreshBankFeeds::class)
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/bank-feeds.log'));

// Every 5 minutes — a call for help from the shop floor that nobody has answered climbs a level.
Schedule::command(\App\Modules\Operations\Manufacturing\Console\EscalateFloorCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Daily 05:30 — raise preventive maintenance that fell due by calendar, run hours or breakdowns.
Schedule::command(\App\Modules\Operations\Manufacturing\Console\GenerateMaintenanceCommand::class)
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->onOneServer();

// Daily 06:00 — run the loss detectors and escalate loss investigations past their due date.
Schedule::command(\App\Modules\Operations\Manufacturing\Console\ScanLossesCommand::class)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

// Every 15 minutes — manufacturing alerts: shortages, deadlines, stalls, quality, breakdowns, approvals.
Schedule::command(\App\Modules\Operations\Manufacturing\Console\SendAlertsCommand::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Daily 05:00 — reconcile manufacturing WIP and stock movements with the general ledger.
Schedule::command(\App\Modules\Operations\Manufacturing\Console\ReconcileAccountsCommand::class)
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer();

// Every 5 minutes — manufacturing integrations that are due, and retries of failed messages.
Schedule::command(\App\Modules\Operations\Manufacturing\Console\SyncIntegrationsCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Every minute — agricultural production: stale sensors, incident escalation to backups, unconfirmed
// equipment commands, forecast scoring. Hourly the production agents run too.
Schedule::command(\App\Modules\Operations\Manufacturing\Console\ProductionMonitorCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command(\App\Modules\Operations\Manufacturing\Console\ProductionMonitorCommand::class, ['--agents'])
    ->hourlyAt(7)
    ->withoutOverlapping()
    ->onOneServer();

// Daily 06:30 — measure the manufacturing assistant's recommendations against what happened next.
Schedule::command(\App\Modules\Operations\Manufacturing\Console\MeasureAssistantCommand::class)
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();

// Tax Management — daily at 06:20: generate the coming tax periods for confirmed registrations, then
// send compliance-calendar reminders and escalate what has gone overdue. Inline per tenant: the
// estate has no queue worker.
Schedule::command(\App\Modules\Finance\Tax\Console\RunTaxComplianceCommand::class)
    ->dailyAt('06:20')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/tax-compliance.log'));

// Tax Management — every five minutes: deliver the tax event outbox (SRS §19) to listeners, with
// retries. Events are written with the change they describe, so nothing is lost if this is late.
Schedule::command(\App\Modules\Finance\Tax\Console\DeliverTaxEventsCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/tax-events.log'));

// Workflow & Automation — every minute: due timers (waits, SLA warnings and breaches, reminders),
// action retries, scheduled workflows and event delivery. Inline: production has no queue worker,
// and every state change it makes is a row the next run can pick up if this one is late.
Schedule::command(\App\Modules\Workflow\Console\WorkflowTickCommand::class)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/workflow-tick.log'));
