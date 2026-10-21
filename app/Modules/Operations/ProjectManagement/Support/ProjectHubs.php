<?php

namespace App\Modules\Operations\ProjectManagement\Support;

use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Support\Hubs\ModuleHubs;

/**
 * Navigation for Project Management: eight direct pages plus three hub landings of role-filtered
 * feature cards (Evidence, Insights, Setup). Registered in HubRegistry, so `hubs:sync` manages it
 * and EnsureModuleTier enforces the tiers below on every route — the role separation is real, not
 * just which cards get rendered.
 *
 * The rule for what earns a place here is whether the page means anything without a project in the
 * URL. Scheduling, the board, costs, figures, the registers, the site records and the sprint board
 * are all per-project, so they are tabs on a project rather than sidebar entries.
 *
 * Everything listed below passes that test: `My work`, approvals and the portal are about a person
 * rather than a project; meetings and the portfolio span several at once.
 *
 * Labels resolve to i18n keys project_management.hubs.* / .cards.*. The sidebar's own ribbon labels
 * are mapped to each page's title key in resources/js/utils/locales/nav.ts, so a menu entry and the
 * heading it leads to cannot drift apart. NavigationTest holds all of this together.
 */
class ProjectHubs extends ModuleHubs
{
    public static function code(): string
    {
        return 'project_management';
    }

    public static function direct(): array
    {
        return [
            ['key' => 'dashboard', 'name' => 'PM Dashboard', 'path' => 'project-management', 'icon' => 'layout-dashboard', 'tier' => self::READ],
            ['key' => 'projects', 'name' => 'Projects', 'path' => 'project-management/projects', 'icon' => 'folders', 'tier' => self::READ],
            // Everything else that used to sit here — My Work, Approvals, Announcements, Meetings,
            // the Portal and Portfolio — is now a card (Workspace, or Insights for Portfolio), so the
            // module keeps to five sidebar ribbons. Their READ tiers and reasons are unchanged.
        ];
    }

    public static function hubs(): array
    {
        return static::consolidate([
            // 👉 Workspace — the day-to-day pages that used to be direct ribbons. All READ: whether
            //    you can approve, post or sign is settled per request, and hiding the pages would
            //    hide people's own submissions and notices from them.
            'workspace' => [
                'name' => 'Workspace', 'path' => 'project-management/workspace', 'icon' => 'briefcase', 'tier' => self::READ,
                'title' => 'project_management.hubs.workspace.title', 'subtitle' => 'project_management.hubs.workspace.subtitle',
                'cards' => [
                    self::card('field', 'project-management/field', 'device-mobile', self::READ, 'primary', ['ability' => 'viewAny', 'subject' => \App\Modules\Operations\ProjectManagement\Models\Task::class]),
                    self::card('approvals_inbox', 'project-management/approvals', 'checkup-list', self::READ, 'amber', self::seeProjects(), 'project_management.approvals.title', 'project_management.approvals.subtitle'),
                    self::card('meetings', 'project-management/meetings', 'calendar-event', self::READ, 'indigo', self::seeProjects(), 'project_management.meetings.title', 'project_management.meetings.subtitle'),
                    self::card('announcements', 'project-management/announcements', 'speakerphone', self::READ, 'orange', self::seeProjects(), 'project_management.announcements.title', 'project_management.announcements.subtitle'),
                    self::card('portal', 'project-management/portal', 'door-enter', self::READ, 'cyan'),
                ],
            ],
            'evidence' => [
                'name' => 'Evidence', 'path' => 'project-management/evidence', 'icon' => 'clipboard-check', 'tier' => self::READ,
                'title' => 'project_management.hubs.evidence.title', 'subtitle' => 'project_management.hubs.evidence.subtitle',
                'cards' => [
                    // Reading the timeline is how anyone answers "why does this say 62%?".
                    self::card('evidence_timeline', 'project-management/evidence/timeline', 'timeline', self::READ, 'primary', self::seeEvidence()),
                    // Verifying the hash chain is an audit function, not day-to-day work. Gated as
                    // well as tiered: the page itself asks EvidencePolicy@verify, and a module
                    // admin who cannot verify would otherwise be shown a card that 403s.
                    self::card('evidence_audit', 'project-management/evidence/audit', 'shield-check', self::ADMIN, 'teal', [
                        'ability' => 'verify',
                        'subject' => EvidenceEvent::class,
                    ]),
                ],
            ],
            // 👉 Insights — the portfolio in charts, and the same numbers as rows anyone can take
            //    away. READ tier: reading the state of the work is not a privileged act; the cost
            //    figures inside are gated separately by `pm.costs.view`, in the payload rather than
            //    by hiding a card.
            'insights' => [
                'name' => 'PM Insights', 'path' => 'project-management/insights', 'icon' => 'chart-line', 'tier' => self::READ,
                'title' => 'project_management.hubs.insights.title', 'subtitle' => 'project_management.hubs.insights.subtitle',
                'cards' => [
                    self::card('portfolio', 'project-management/portfolio', 'stack-2', self::READ, 'indigo', self::seeProjects(), 'project_management.portfolio.title', 'project_management.portfolio.subtitle'),
                    self::card('analytics', 'project-management/insights/analytics', 'chart-histogram', self::READ, 'primary', self::seeProjects()),
                    self::card('reports', 'project-management/insights/reports', 'file-analytics', self::READ, 'blue', self::seeProjects()),
                    // Deterministic warnings first, drafting second — so it is useful with no AI
                    // provider configured at all.
                    self::card('assistant', 'project-management/insights/assistant', 'bulb', self::READ, 'deep-purple', self::seeProjects()),
                ],
            ],
            // 👉 Project Invoicing — an add-on, and deliberately its own ribbon rather than cards
            //    scattered through the existing hubs. Core Project Management works identically
            //    whether or not a tenant uses it. WRITE tier: raising an account against a client
            //    is not a read.
            //
            //    Two cards, not five. Form 1, Form 2, the Summary and the annexures are *parts of
            //    an account* in the client's workbook, not registers of their own — so they are
            //    tabs inside an account, and pointing hub cards at them only produced four
            //    entries that opened the same page.
            'invoicing' => [
                'name' => 'Project Invoicing', 'path' => 'project-management/invoicing', 'icon' => 'file-invoice', 'tier' => self::WRITE,
                'title' => 'project_management.hubs.invoicing.title', 'subtitle' => 'project_management.hubs.invoicing.subtitle',
                'cards' => [
                    // Every account raised on every project, in sequence.
                    self::card('invoices', 'project-management/invoicing/invoices', 'file-invoice', self::WRITE, 'primary', self::seeProjects()),
                    // Month, amount, cumulative — the client's own cashflow sheet. WRITE, not READ,
                    // to match the hub that holds it: a card below its hub's tier is visible to
                    // people who cannot open the hub it lives in, so the tier read as a promise
                    // nothing kept. No practical change — the hub already required WRITE.
                    self::card('invoice_cashflow', 'project-management/invoicing/cashflow', 'chart-area-line', self::WRITE, 'amber', self::seeProjects()),
                ],
            ],
            // 👉 Monthly Project & Financial Reporting — the second add-on, and its own ribbon
            //    for the same reason as the first: core Project Management is unchanged whether or
            //    not a practice runs a monthly management report.
            'reporting' => [
                'name' => 'Monthly Reporting', 'path' => 'project-management/reporting', 'icon' => 'report-analytics', 'tier' => self::WRITE,
                'title' => 'project_management.hubs.reporting.title', 'subtitle' => 'project_management.hubs.reporting.subtitle',
                'cards' => [
                    // The client's own three entries: the months, a project manager's own rows,
                    // and the dashboard the meeting reads.
                    self::card('monthly_reports', 'project-management/reporting/reports', 'calendar-stats', self::WRITE, 'primary', self::seeProjects()),
                    self::card('project_reports', 'project-management/reporting/projects', 'user-check', self::WRITE, 'blue', self::seeProjects()),
                    self::card('reporting_dashboard', 'project-management/reporting/dashboard', 'chart-donut', self::WRITE, 'teal', self::seeProjects()),
                ],
            ],
            // Subcontractor contracts and billing. Its own ribbon rather than folded into
            // Invoicing: what we bill a client and what a subcontractor bills us are different
            // documents read by different people, and mixing them is how a markup ends up on a
            // screen a client can see.
            'contractors' => [
                'name' => 'Subcontractors', 'path' => 'project-management/contractors', 'icon' => 'contract', 'tier' => self::WRITE,
                'title' => 'project_management.hubs.contractors.title', 'subtitle' => 'project_management.hubs.contractors.subtitle',
                'cards' => [
                    // The register is the subject: who we subcontract to, and the way to add one.
                    self::card('contractor_register', 'project-management/contractors/register', 'users-group', self::WRITE, 'primary', self::seeProjects()),
                    // The queue is one thing you do with them, not the thing itself.
                    self::card('contractor_invoices', 'project-management/contractors/invoices', 'file-invoice', self::WRITE, 'teal', self::seeProjects()),
                    // Contracts are PM's own — drafted, amended, reviewed and signed here, with no
                    // dependency on the Contract Management module being bought.
                    //
                    // READ tier for the same reason as approvals and announcements: the other party
                    // to a subcontract is a portal user with no write tier, and they have to be able
                    // to open the contract and sign it. What anybody may *do* is settled per request
                    // — drafting and amending ask ProjectPolicy@update, and signing asks only
                    // whether a signature is pending for you.
                    self::card('project_contracts', 'project-management/contracts', 'file-text', self::READ, 'deep-purple', self::seeProjects()),
                ],
            ],
            'setup' => [
                'name' => 'PM Setup', 'path' => 'project-management/setup', 'icon' => 'settings', 'tier' => self::ADMIN,
                'title' => 'project_management.hubs.setup.title', 'subtitle' => 'project_management.hubs.setup.subtitle',
                'cards' => [
                    self::card('calendars', 'project-management/setup/calendars', 'calendar-time', self::ADMIN, 'indigo', self::adminSettings()),
                    // Who signs off what, designed per tenant rather than hard-coded.
                    self::card('approvals', 'project-management/setup/approvals', 'checkup-list', self::ADMIN, 'amber', self::adminSettings()),
                    // The contract wording this company subcontracts on. Administration, so the
                    // same ADMIN gate as calendars and flows — a project manager drafts from these
                    // but does not get to rewrite the standard terms.
                    self::card('contract_templates', 'project-management/contracts/templates', 'file-description', self::ADMIN, 'deep-purple', self::adminSettings()),
                    // The client list, and its adoption into CRM once that module arrives.
                    self::card('clients', 'project-management/setup/clients', 'building-community', self::ADMIN, 'cyan', self::adminSettings()),
                    // Rooms, licences and small plant — the things a project books that no other
                    // module keeps a register of.
                    self::card('resources', 'project-management/setup/resources', 'door', self::ADMIN, 'teal', self::adminSettings()),
                    // The template library: what a new project can start from.
                    self::card('templates', 'project-management/setup/templates', 'template', self::ADMIN, 'purple', self::seeProjects()),
                    // Who invoices are from and where clients pay — entered once, copied onto
                    // each invoice when it is opened.
                    self::card('invoicing_setup', 'project-management/setup/invoicing', 'building-bank', self::ADMIN, 'blue', self::adminSettings()),
                    self::card('integrations', 'project-management/setup/integrations', 'plug-connected', self::ADMIN, 'grey', self::adminSettings()),
                ],
            ],
        ], [
            // Five sidebar ribbons at most: Dashboard, Projects, Workspace, Commercial, Insights & Setup.
            'workspace' => ['absorb' => ['evidence'], 'name' => 'Workspace'],
            'invoicing' => ['absorb' => ['reporting', 'contractors'], 'name' => 'Commercial'],
            'insights' => ['absorb' => ['setup'], 'name' => 'Insights & Setup'],
        ]);
    }


    /**
     * Cards are filtered on tier, but the module tier and Project Management's own role model are
     * independent systems: PermissionEvaluator decides from platform-admin flags, explicit
     * overrides, global grants and per-project memberships, and never consults
     * security_module_access. So a tenant granted ADMIN on the module but holding no
     * MANAGE_SETTINGS grant used to be shown all eight Setup cards, every one of which the
     * controller then refused. A 403 reached by clicking a card you were just offered reads as a
     * broken system rather than a boundary.
     *
     * These name the exact ability each card's own route authorizes, so the hub offers only what
     * the page behind it will actually open. ModuleHubController evaluates them with
     * Gate::allows($ability, $subject).
     */
    private static function seeProjects(): array
    {
        return ['ability' => 'viewAny', 'subject' => Project::class];
    }

    private static function adminSettings(): array
    {
        return ['ability' => 'manageSettings', 'subject' => Project::class];
    }

    private static function seeEvidence(): array
    {
        return ['ability' => 'viewAny', 'subject' => EvidenceEvent::class];
    }

    /**
     * Defence in depth: any /setup/* URL requires ADMIN even if a future sub-route is added without
     * a card, so an un-carded settings page can never fall back to READ.
     */
    public static function guards(): array
    {
        return [
            'project-management/setup' => self::ADMIN,
        ];
    }
}
