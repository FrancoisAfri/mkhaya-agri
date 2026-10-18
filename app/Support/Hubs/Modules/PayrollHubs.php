<?php

namespace App\Support\Hubs\Modules;

use App\Support\Hubs\ModuleHubs;

/**
 * Payroll hubs: My Payslips (the ONLY employee self-service item, READ) + Run Payroll / Assurance /
 * Insights (all HR/Payroll-admin, ADMIN) + Configure (SUPERUSER).
 *
 * This is the most sensitive module. The `guards()` floor makes EVERY payroll/* URL require ADMIN
 * by default, so a normal employee or manager can never reach a payroll run, anomaly report, or
 * setting — even by typing the URL. The only exception is the more-specific payroll/my-payslips
 * card (READ), which stays reachable via the longest-prefix rule.
 */
class PayrollHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'payroll';
    }

    public static function guards(): array
    {
        return ['payroll' => self::ADMIN];
    }

    public static function direct(): array
    {
        return [
            ['key' => 'my_payslips', 'name' => 'My Payslips', 'path' => 'payroll/my-payslips', 'icon' => 'cash-multiple', 'tier' => self::READ],
        ];
    }

    public static function hubs(): array
    {
        return [
            'run' => [
                'name' => 'Run Payroll', 'path' => 'payroll/run-hub', 'icon' => 'cash-register', 'tier' => self::ADMIN,
                'title' => 'payroll.hubs.run.title', 'subtitle' => 'payroll.hubs.run.subtitle',
                'cards' => [
                    self::card('dashboard', 'payroll/dashboard', 'view-dashboard-outline', self::ADMIN, 'blue'),
                    self::card('runs', 'payroll/runs', 'play-circle-outline', self::ADMIN, 'indigo'),
                    self::card('new_run', 'payroll/runs/create', 'plus-box-outline', self::ADMIN, 'green'),
                    self::card('approvals', 'payroll/approvals', 'clipboard-check-outline', self::ADMIN, 'teal'),
                    // On the run hub rather than assurance: these are the reviews that have to
                    // happen *before* the next run is calculated, not findings about one already
                    // processed. An incentive expiry noticed afterwards is a correction.
                    self::card('milestones', 'payroll/milestones', 'calendar-alert', self::ADMIN, 'amber'),
                    self::card('simulations', 'payroll/simulations', 'flask-outline', self::ADMIN, 'deep-purple'),
                    self::card('corrections', 'payroll/corrections', 'auto-fix', self::ADMIN, 'orange'),
                ],
            ],
            'assurance' => [
                'name' => 'Assurance', 'path' => 'payroll/assurance-hub', 'icon' => 'shield-check', 'tier' => self::ADMIN,
                'title' => 'payroll.hubs.assurance.title', 'subtitle' => 'payroll.hubs.assurance.subtitle',
                'cards' => [
                    self::card('cross_checks', 'payroll/cross-checks', 'checkbox-multiple-marked-outline', self::ADMIN, 'blue'),
                    self::card('anomalies', 'payroll/anomalies', 'alert-outline', self::ADMIN, 'red'),
                    self::card('leakage', 'payroll/leakage', 'water-alert-outline', self::ADMIN, 'orange'),
                    self::card('ghost_employees', 'payroll/ghost-employees', 'account-question-outline', self::ADMIN, 'deep-purple'),
                    self::card('behavior', 'payroll/behavior', 'chart-bell-curve', self::ADMIN, 'teal'),
                    self::card('compliance', 'payroll/compliance', 'shield-check-outline', self::ADMIN, 'green'),
                ],
            ],
            'insights' => [
                'name' => 'Insights', 'path' => 'payroll/insights-hub', 'icon' => 'chart-line', 'tier' => self::ADMIN,
                'title' => 'payroll.hubs.insights.title', 'subtitle' => 'payroll.hubs.insights.subtitle',
                'cards' => [
                    self::card('analytics', 'payroll/analytics', 'chart-line', self::ADMIN, 'blue'),
                    self::card('reports', 'payroll/reports', 'file-chart-outline', self::ADMIN, 'teal'),
                    self::card('emp201', 'payroll/emp201', 'file-percent-outline', self::ADMIN, 'green'),
                    self::card('emp501', 'payroll/emp501', 'file-document-multiple-outline', self::ADMIN, 'deep-purple'),
                    // How much of each person's twenty-four ETI months is left. The ledger was
                    // written on every run and shown nowhere, so the month the incentive ran out
                    // announced itself as an unexplained jump in the PAYE bill.
                    self::card('eti', 'payroll/eti', 'cash-refund', self::ADMIN, 'lime'),
                    // On insights rather than configure: a certificate is something an employee asks
                    // for, and whoever fields that call is not the person who edits settings.
                    self::card('tax_certificates', 'payroll/tax-certificates', 'certificate-outline', self::ADMIN, 'indigo'),
                    // The Return of Earnings. Annual rather than monthly, and it did not exist at
                    // all before — the levy was being worked out on a spreadsheet.
                    self::card('coida', 'payroll/coida', 'shield-plus-outline', self::ADMIN, 'red'),
                    // Who the deductions are owed to, and whether they have been paid. Everything
                    // here was previously invisible — the only way to check was to ask the bank.
                    self::card('remittances', 'payroll/remittances', 'bank-transfer-out', self::ADMIN, 'teal'),
                    // The reference each fund knows each employee by. Its own screen because
                    // chasing these numbers is a job of work when a fund is onboarded, and doing it
                    // one profile at a time is why it does not get done — and without them no
                    // deduction file can be produced at all.
                    self::card('deduction_references', 'payroll/deduction-references', 'identifier', self::ADMIN, 'blue-grey'),
                    // The levy payroll pays, and the fifth of it the LMS can claim back. Neither
                    // module could see the other's half of it.
                    self::card('skills_levy', 'payroll/skills-levy', 'school-outline', self::ADMIN, 'indigo'),
                    // Councils, their gazetted rates and who they cover. The levy is calculated on
                    // every run; until this screen it could only be set up with a SQL client.
                    self::card('bargaining_councils', 'payroll/bargaining-councils', 'gavel', self::SUPERUSER, 'brown'),
                    // EEA2 and EEA4. It sits with COIDA because it is the same kind of thing: an annual
                    // statutory return assembled from what payroll already holds.
                    self::card('employment_equity', 'payroll/employment-equity', 'scale-balance', self::ADMIN, 'purple'),
                    self::card('ui19', 'payroll/ui19', 'account-group-outline', self::ADMIN, 'blue'),
                    self::card('forecasting', 'payroll/forecasting', 'chart-timeline-variant', self::ADMIN, 'indigo'),
                    self::card('narrative_reports', 'payroll/narrative-reports', 'text-box-outline', self::ADMIN, 'green'),
                    self::card('ai_assistant', 'payroll/ai-assistant', 'robot-happy-outline', self::ADMIN, 'deep-purple'),
                    self::card('scheduled_reports', 'payroll/settings/scheduled-reports', 'calendar-clock-outline', self::ADMIN, 'orange'),
                    self::card('tax_tables', 'payroll/tax-tables', 'calculator-variant-outline', self::ADMIN, 'blue-grey'),
                ],
            ],
            'configure' => [
                'name' => 'Configure', 'path' => 'payroll/configure-hub', 'icon' => 'settings', 'tier' => self::SUPERUSER,
                'title' => 'payroll.hubs.configure.title', 'subtitle' => 'payroll.hubs.configure.subtitle',
                'cards' => [
                    self::card('settings', 'payroll/settings', 'cog-outline', self::SUPERUSER, 'blue-grey'),
                    self::card('approval_steps', 'payroll/settings/approval-steps', 'check-decagram-outline', self::SUPERUSER, 'teal'),
                    self::card('change_log', 'payroll/change-log', 'history', self::SUPERUSER, 'red'),
                    // Superuser: a layout decides what a bank is told to pay, so it sits with the
                    // settings rather than with the people who run payroll day to day.
                    self::card('file_specs', 'payroll/file-specs', 'file-code-outline', self::SUPERUSER, 'blue-grey'),
                    // The National Minimum Wage and the BCEA multipliers. Superuser: these are the
                    // figures every overtime line is measured against, and they are gazetted, not chosen.
                    self::card('labour_rates', 'payroll/labour-rates', 'scale-balance', self::SUPERUSER, 'brown'),
                    // Who signs a client's payroll, and in what order. Superuser: it decides who can
                    // release money, which is not a day-to-day payroll setting.
                    self::card('bureau_approval_steps', 'payroll/bureau/approval-steps', 'writing-sign', self::SUPERUSER, 'teal'),
                ],
            ],
        ];
    }
}
