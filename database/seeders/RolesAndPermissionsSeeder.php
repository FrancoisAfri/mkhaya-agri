<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Role\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ----------------------------
        // Core CRUD permissions
        // ----------------------------
        $corePermissions = ['view', 'modify', 'delete'];
        foreach ($corePermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ----------------------------
        // Leave AI permissions
        // leave.ai.basic     — chatbot for any employee: entitlement, balance, BCEA
        // leave.ai.advisor   — approval likelihood + policy guidance (team leaders+)
        // leave.ai.policy    — full policy reasoning (supervisors+)
        // leave.ai.anomaly   — anomaly detection & burnout flags (HR, compliance+)
        // leave.ai.workforce — workforce intelligence predictions (managers+)
        // leave.ai.executive — executive forecasting & liability (senior managers+)
        // ----------------------------
        $aiPermissions = [
            'leave.ai.basic',
            'leave.ai.advisor',
            'leave.ai.policy',
            'leave.ai.anomaly',
            'leave.ai.workforce',
            'leave.ai.executive',
        ];
        foreach ($aiPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ----------------------------
        // Recruitment permissions
        // ----------------------------
        $recruitmentPermissions = [
            'recruitment.jobs.view',
            'recruitment.jobs.create',
            'recruitment.jobs.edit',
            'recruitment.jobs.delete',
            'recruitment.candidates.view',
            'recruitment.candidates.create',
            'recruitment.candidates.edit',
            'recruitment.candidates.delete',
            'recruitment.candidates.upload_cv',
            'recruitment.candidates.anonymise',
            'recruitment.applications.view',
            'recruitment.applications.move',
            'recruitment.applications.withdraw',
            'recruitment.requisitions.view',
            'recruitment.requisitions.create',
            'recruitment.requisitions.approve',
            'recruitment.shortlists.view',
            'recruitment.shortlists.create',
            'recruitment.shortlists.approve',
            'recruitment.shortlists.override',
            'recruitment.interviews.create',
            'recruitment.interviews.score',
            /*
             * Offers and contracts sit at the end of the recruitment funnel and their policies
             * have always checked these names — but no seeder created them, so
             * `hasPermissionTo('offers.create')` threw PermissionDoesNotExist and the screens
             * answered 500. The module could not be used at all.
             */
            'offers.view',
            'offers.create',
            'offers.send',
            'offers.accept',
            'contracts.view',
            'contracts.create',
            'contracts.send',
        ];
        foreach ($recruitmentPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ----------------------------
        // Attendance permissions
        // ----------------------------
        $attendancePermissions = [
            'attendance.dashboard.view',
            'attendance.records.view',
            'attendance.records.create',
            'attendance.records.edit',
            'attendance.records.delete',
            'attendance.records.approve',
            'attendance.statuses.view',
            'attendance.statuses.create',
            'attendance.statuses.edit',
            'attendance.statuses.delete',
            'attendance.shifts.view',
            'attendance.shifts.create',
            'attendance.shifts.edit',
            'attendance.shifts.delete',
            'attendance.calendars.view',
            'attendance.calendars.create',
            'attendance.calendars.edit',
            'attendance.calendars.delete',
            'attendance.settings.view',
            'attendance.settings.edit',
            'attendance.notifications.edit',
            // Phase 2 — Clocking
            'attendance.clocking.clock_in',
            'attendance.clocking.clock_out',
            'attendance.clocking.break',
            'attendance.clocking.gps',
            'attendance.clocking.wfh',
            'attendance.clocking.view',
            'attendance.clocking.approve',
            'attendance.clocking.missed.view',
            'attendance.clocking.missed.detect',
            'attendance.device_logs.view',
            // Phase 3 — Anomaly Detection
            'attendance.intelligence.view',
            'attendance.intelligence.scan',
            'attendance.intelligence.manage',
            // Phase 4 — AI Intelligence
            'attendance.intelligence.ai',
            // Phase 5 — Workforce Pressure & Burnout
            'attendance.workforce.view',
            'attendance.workforce.scan',
            'attendance.workforce.manage',
            'attendance.workforce.ai',
            // Phase 6 — Roster & Shift Intelligence
            'attendance.roster.view',
            'attendance.roster.manage',
            'attendance.roster.ai',
            // Phase 7 — Employee Wellness Signals
            'attendance.wellness.view',
            'attendance.wellness.scan',
            'attendance.wellness.manage',
            'attendance.wellness.ai',
            // Phase 8 — Attendance Discipline Intelligence
            'attendance.discipline.view',
            'attendance.discipline.manage',
            'attendance.discipline.ai',
            // Phase 9 — Attendance Policy Intelligence
            'attendance.policy.view',
            'attendance.policy.scan',
            'attendance.policy.manage',
            'attendance.policy.ai',
            // Module 9 — Workforce Productivity Analytics
            'attendance.productivity.view',
            'attendance.productivity.scan',
            'attendance.productivity.manage',
            'attendance.productivity.ai',
            // Module 10 — Audit Logging
            'attendance.audit.view',
            // Reports
            'attendance.reports.view',
            'attendance.reports.export',
            'attendance.reports.schedule',
            // Executive Dashboard
            'attendance.executive.view',
        ];
        foreach ($attendancePermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ----------------------------
        // Asset Management permissions
        // ----------------------------
        $assetPermissions = [
            // Core register
            'assets.view',
            'assets.create',
            'assets.edit',
            // AssetPolicy checks `assets.update`, not `assets.edit`. Spatie *throws* when a
            // permission does not exist, so the mismatch produced a 500 rather than a 403.
            'assets.update',
            'assets.delete',
            // Assignments
            'assets.assign',
            // Maintenance
            'assets.maintenance',
            // Incidents
            'assets.incidents',
            // Movements
            'assets.movements',
            // Performance tracking
            'assets.performance',
            // Depreciation
            'assets.depreciation',
            // Exit clearances
            'assets.exit-clearances',
            // Misuse detection & flags
            'assets.misuse-flags',
            // Document extractions (AI)
            'assets.document-extractions',
            // Utilization reports
            'assets.utilization-reports',
            // Compliance rules
            'assets.compliance-rules',
            // Procurement / purchase requests
            'assets.procurement',
            // Approvals (assignment / procurement queue)
            'assets.approvals',
            // Physical audit / stock-take
            'assets.audits',
            // Work-order management
            'assets.work-orders',
            // Spare parts / MRO inventory
            'assets.parts',
            // Analytics
            'assets.analytics',
            // Settings
            'assets.settings',
            // Makes & models reference data
            'assets.settings.makes',
        ];
        foreach ($assetPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ----------------------------
        // Fleet Management permissions
        // ----------------------------
        $fleetPermissions = [
            // Dashboard
            'fleet.dashboard.view',
            // Vehicles
            'fleet.vehicles.view',
            'fleet.vehicles.create',
            'fleet.vehicles.edit',
            'fleet.vehicles.delete',
            'fleet.vehicles.dispose',
            'fleet.vehicles.approve',
            'fleet.vehicles.import',
            // Drivers
            'fleet.drivers.view',
            'fleet.drivers.create',
            'fleet.drivers.edit',
            'fleet.drivers.delete',
            'fleet.drivers.suspend',
            // Bookings
            'fleet.bookings.view',
            'fleet.bookings.create',
            'fleet.bookings.approve',
            'fleet.bookings.collect',
            'fleet.bookings.check-in',
            'fleet.bookings.cancel',
            'fleet.bookings.manage',
            // Fleet Cards
            'fleet.cards.view',
            'fleet.cards.create',
            'fleet.cards.edit',
            'fleet.cards.delete',
            'fleet.cards.transactions.view',
            'fleet.cards.transactions.create',
            'fleet.cards.transactions.flag',
            'fleet.cards.transactions.reconcile',
            // Documents
            'fleet.documents.view',
            'fleet.documents.create',
            'fleet.documents.edit',
            'fleet.documents.delete',
            // Maintenance
            'fleet.maintenance.view',
            'fleet.maintenance.create',
            'fleet.maintenance.edit',
            'fleet.maintenance.delete',
            // Job cards / workshop
            'fleet.job_cards.view',
            'fleet.job_cards.create',
            'fleet.job_cards.edit',
            'fleet.job_cards.delete',
            'fleet.job_cards.approve',
            'fleet.job_cards.configure',
            // Fuel
            'fleet.fuel.view',
            'fleet.fuel.create',
            'fleet.fuel.delete',
            // Reports
            'fleet.reports.view',
            'fleet.reports.export',
            'fleet.reports.schedule',
            // Checked by the fleet controllers and created by nothing, so every one of these
            // actions denied for everybody: generating a report, changing a vehicle's status,
            // and approving bookings in bulk.
            'fleet.reports.generate',
            'fleet.vehicles.change-status',
            'fleet.bookings.bulk-approve',
            // The scheduled-report routes are a resource of their own, and EnforceCrudPermissions
            // derives these names from them. `fleet.reports.schedule` does not match any of them.
            'fleet.reports.scheduled.view',
            'fleet.reports.scheduled.create',
            'fleet.reports.scheduled.update',
            'fleet.reports.scheduled.delete',
            // Settings
            'fleet.settings.view',
            'fleet.settings.edit',
            // AI
            'fleet.ai.view',
            'fleet.ai.chat',
        ];
        foreach ($fleetPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ----------------------------
        // Finance / Claims permissions
        // ----------------------------
        $financePermissions = [
            // Dashboard & executive
            'finance.dashboard.view',
            'finance.executive.view',
            // Claims — employee self-service
            'finance.claims.view',
            'finance.claims.create',
            'finance.claims.edit',
            'finance.claims.submit',
            'finance.claims.cancel',
            // Claims — approval workflow
            'finance.claims.approve',
            'finance.claims.reject',
            // Claims — payment processing
            'finance.claims.pay',
            'finance.claims.payment.view',
            // Claims — reporting
            'finance.claims.reports.view',
            'finance.claims.reports.export',
            'finance.claims.reports.schedule',
            // Claims — administration
            'finance.claims.setup',
            'finance.claims.ai.view',
            // Budgets
            'finance.budgets.view',
            'finance.budgets.manage',
            // Payroll
            'finance.payroll.view',
            'finance.payroll.manage',
            'payroll.runs.create',
            'payroll.runs.calculate',
            'payroll.runs.lock',
            'payroll.runs.submit',
            'payroll.runs.release',
            'payroll.runs.cancel',
            'payroll.anomalies.manage',
            'payroll.approvals.manage',
            'payroll.settings.manage',
            // General finance reports
            'finance.reports.view',
            'finance.reports.export',
            'finance.reports.schedule',
            // Audit
            'finance.audit.view',
        ];
        foreach ($financePermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ----------------------------
        // System Restoration permissions
        // These are kept in the DB for auditing/reference but are NOT granted
        // to any Spatie role. Access is controlled exclusively by the
        // is_platform_admin flag on the users table (EnsurePlatformAdmin middleware).
        // ----------------------------
        $restorationPermissions = [
            'system.restoration.view',
            'system.restoration.restore',
            'system.restoration.force_delete',
        ];
        foreach ($restorationPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Fleet roles
        $fleetManagerPerms = $fleetPermissions; // all permissions

        $fleetDriverPerms = [
            'fleet.dashboard.view',
            'fleet.vehicles.view',
            'fleet.bookings.view',
            'fleet.bookings.create',
            'fleet.bookings.collect',
            'fleet.bookings.check-in',
            'fleet.fuel.view',
            'fleet.fuel.create',
        ];

        $fleetViewerPerms = [
            'fleet.dashboard.view',
            'fleet.vehicles.view',
            'fleet.drivers.view',
            'fleet.bookings.view',
            'fleet.cards.view',
            'fleet.cards.transactions.view',
            'fleet.documents.view',
            'fleet.maintenance.view',
            'fleet.job_cards.view',
            'fleet.fuel.view',
            'fleet.reports.view',
        ];

        $fleetRoles = [
            'fleet_manager' => $fleetManagerPerms,
            'fleet_driver'  => $fleetDriverPerms,
            'fleet_viewer'  => $fleetViewerPerms,
        ];

        foreach ($fleetRoles as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }

        // ----------------------------
        // Roles — base CRUD permissions
        // ----------------------------
        $allRecruitmentPerms = $recruitmentPermissions;
        $recruitmentReadWrite = array_filter($allRecruitmentPerms, fn($p) => !str_ends_with($p, '.delete') && !str_ends_with($p, '.anonymise'));

        $clockingSelf   = ['attendance.clocking.clock_in', 'attendance.clocking.clock_out', 'attendance.clocking.break', 'attendance.clocking.gps', 'attendance.clocking.wfh'];
        $clockingManage = array_merge($clockingSelf, ['attendance.clocking.view', 'attendance.clocking.approve', 'attendance.clocking.missed.view', 'attendance.clocking.missed.detect']);
        $clockingAdmin  = array_merge($clockingManage, ['attendance.device_logs.view']);

        $intelligenceView   = ['attendance.intelligence.view'];
        $intelligenceManage = array_merge($intelligenceView, ['attendance.intelligence.scan', 'attendance.intelligence.manage']);
        $intelligenceAi     = array_merge($intelligenceManage, ['attendance.intelligence.ai']);

        $workforceView   = ['attendance.workforce.view'];
        $workforceManage = array_merge($workforceView, ['attendance.workforce.scan', 'attendance.workforce.manage']);
        $workforceAi     = array_merge($workforceManage, ['attendance.workforce.ai']);

        $rosterView   = ['attendance.roster.view'];
        $rosterManage = array_merge($rosterView, ['attendance.roster.manage']);
        $rosterAi     = array_merge($rosterManage, ['attendance.roster.ai']);

        $wellnessView   = ['attendance.wellness.view'];
        $wellnessManage = array_merge($wellnessView, ['attendance.wellness.scan', 'attendance.wellness.manage']);
        $wellnessAi     = array_merge($wellnessManage, ['attendance.wellness.ai']);

        $disciplineView   = ['attendance.discipline.view'];
        $disciplineManage = array_merge($disciplineView, ['attendance.discipline.manage']);
        $disciplineAi     = array_merge($disciplineManage, ['attendance.discipline.ai']);

        $policyView   = ['attendance.policy.view'];
        $policyManage = array_merge($policyView, ['attendance.policy.scan', 'attendance.policy.manage']);
        $policyAi     = array_merge($policyManage, ['attendance.policy.ai']);

        $productivityView   = ['attendance.productivity.view'];
        $productivityManage = array_merge($productivityView, ['attendance.productivity.scan', 'attendance.productivity.manage']);
        $productivityAi     = array_merge($productivityManage, ['attendance.productivity.ai']);

        $auditView = ['attendance.audit.view'];

        $reportsView     = ['attendance.reports.view'];
        $reportsExport   = array_merge($reportsView, ['attendance.reports.export']);
        $reportsSchedule = array_merge($reportsExport, ['attendance.reports.schedule']);
        $executiveView   = ['attendance.executive.view'];

        $attendanceView    = array_merge(['attendance.dashboard.view', 'attendance.records.view', 'attendance.shifts.view', 'attendance.statuses.view', 'attendance.calendars.view'], $clockingSelf);
        $attendanceBasic   = array_merge($attendanceView, ['attendance.records.create', 'attendance.records.edit'], $clockingManage);
        $attendanceManage  = array_merge($attendanceBasic, ['attendance.records.approve', 'attendance.records.delete', 'attendance.statuses.create', 'attendance.statuses.edit', 'attendance.shifts.create', 'attendance.shifts.edit', 'attendance.calendars.create', 'attendance.calendars.edit'], $intelligenceManage, $workforceManage, $rosterManage, $wellnessManage, $disciplineManage, $policyManage, $productivityManage, $auditView, $reportsExport, $executiveView);
        $attendanceAdmin   = array_merge($attendanceManage, ['attendance.statuses.delete', 'attendance.shifts.delete', 'attendance.calendars.delete', 'attendance.settings.view', 'attendance.settings.edit', 'attendance.notifications.edit'], $clockingAdmin, $intelligenceAi, $workforceAi, $rosterAi, $wellnessAi, $disciplineAi, $policyAi, $productivityAi, $reportsSchedule);

        // Finance permission groupings
        $financeEmployeeSelf = [
            'finance.dashboard.view',
            'finance.claims.view', 'finance.claims.create',
            'finance.claims.edit', 'finance.claims.submit', 'finance.claims.cancel',
        ];
        $financeOfficerPerms = array_merge($financeEmployeeSelf, [
            'finance.claims.payment.view',
            'finance.claims.pay',
            'finance.claims.reports.view', 'finance.claims.reports.export',
            'finance.payroll.view',
            'finance.reports.view', 'finance.reports.export',
            'finance.audit.view',
        ]);
        $financeManagerPerms = array_merge($financeOfficerPerms, [
            'finance.claims.approve', 'finance.claims.reject',
            'finance.claims.reports.schedule',
            'finance.claims.setup',
            'finance.claims.ai.view',
            'finance.executive.view',
            'finance.budgets.view', 'finance.budgets.manage',
            'finance.payroll.manage',
            'finance.reports.schedule',
        ]);
        $payrollOfficerPerms = [
            'finance.dashboard.view',
            'finance.claims.view', 'finance.claims.payment.view',
            'finance.claims.pay',
            'finance.payroll.view', 'finance.payroll.manage',
            'finance.reports.view', 'finance.reports.export',
            'finance.audit.view',
        ];
        $budgetManagerPerms = array_merge($financeEmployeeSelf, [
            'finance.claims.approve', 'finance.claims.reject',
            'finance.executive.view',
            'finance.budgets.view', 'finance.budgets.manage',
            'finance.claims.reports.view', 'finance.claims.reports.export',
            'finance.reports.view', 'finance.reports.export',
            'finance.audit.view',
        ]);

        $assetView   = ['assets.view', 'assets.analytics'];
        $assetBasic  = array_merge($assetView, ['assets.assign', 'assets.maintenance', 'assets.incidents', 'assets.movements', 'assets.performance', 'assets.audits', 'assets.work-orders', 'assets.parts']);
        $assetManage = array_merge($assetBasic, ['assets.create', 'assets.edit', 'assets.depreciation', 'assets.exit-clearances', 'assets.misuse-flags', 'assets.document-extractions', 'assets.utilization-reports', 'assets.compliance-rules', 'assets.procurement', 'assets.approvals']);
        $assetAdmin  = array_merge($assetManage, ['assets.delete', 'assets.settings', 'assets.settings.makes']);

        $baseRoles = [
            'user'               => ['view'],
            'team leader'        => array_merge(['view', 'modify'], $attendanceView, $intelligenceView, $workforceView, $rosterView, $wellnessView, $disciplineView),
            'supervisor'         => array_merge(['view', 'modify', 'delete'], $attendanceBasic, $intelligenceManage, $workforceManage, $rosterManage, $wellnessManage, $disciplineManage),
            'manager'            => array_merge(['view', 'modify', 'delete'], array_values($recruitmentReadWrite), $attendanceManage, $intelligenceAi, $workforceAi, $rosterAi, $wellnessAi, $disciplineAi, $assetBasic),
            'super admin'        => array_merge(['view', 'modify', 'delete'], $allRecruitmentPerms, $attendanceAdmin, $assetAdmin),
            'HR'                 => array_merge(['view', 'modify'], array_values($recruitmentReadWrite), $attendanceAdmin),
            'recruiter'          => $allRecruitmentPerms,
            'compliance officer' => array_merge(['view', 'modify', 'delete'], $attendanceView, $intelligenceView, $workforceView, $rosterView, $wellnessView, $disciplineManage),
            'senior'             => array_merge(['view', 'modify', 'delete'], $attendanceBasic, $intelligenceView, $workforceView, $rosterView, $wellnessView, $disciplineView),
            'senior manager'     => array_merge(['view', 'modify', 'delete'], array_values($recruitmentReadWrite), $attendanceManage, $intelligenceAi, $workforceAi, $rosterAi, $wellnessAi, $disciplineAi),
            'executive'          => array_merge(['view', 'modify', 'delete'], array_values($recruitmentReadWrite), $attendanceView, $intelligenceAi, $workforceAi, $rosterView, $wellnessView, $disciplineView),
            'administrator'      => Permission::all()->pluck('name')->toArray(),
            // ── Finance roles ──────────────────────────────────────────────────
            'finance manager'    => array_merge(['view', 'modify', 'delete'], $attendanceView, $financeManagerPerms),
            'finance officer'    => array_merge(['view', 'modify'], $attendanceView, $financeOfficerPerms),
            'finance clerk'      => array_merge(['view'], $attendanceView, $financeEmployeeSelf),
            'payroll officer'    => array_merge(['view', 'modify'], $attendanceView, $payrollOfficerPerms),
            'budget manager'     => array_merge(['view', 'modify', 'delete'], $attendanceView, $budgetManagerPerms),
        ];

        foreach ($baseRoles as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->givePermissionTo($perms);
        }

        // Strip platform-only permissions from all client roles.
        // These routes are protected by is_platform_admin flag, not Spatie permissions.
        $platformOnlyPermissions = $restorationPermissions;
        foreach (Role::all() as $role) {
            $role->revokePermissionTo(
                collect($platformOnlyPermissions)
                    ->filter(fn($p) => $role->hasPermissionTo($p))
                    ->all()
            );
        }

        // ----------------------------
        // AI permission assignments per role
        // ----------------------------
        $aiRoleMap = [
            // All roles get basic chatbot access
            'leave.ai.basic' => [
                'user', 'team leader', 'supervisor', 'manager',
                'super admin', 'HR', 'compliance officer', 'senior',
                'senior manager', 'executive', 'administrator',
                'finance manager', 'finance officer', 'finance clerk',
                'payroll officer', 'budget manager',
            ],
            // Approval likelihood + policy guidance (team leaders and above)
            'leave.ai.advisor' => [
                'team leader', 'supervisor', 'manager', 'super admin',
                'HR', 'compliance officer', 'senior', 'senior manager',
                'executive', 'administrator',
                'finance manager', 'finance officer', 'payroll officer', 'budget manager',
            ],
            // Full policy reasoning (supervisors and above)
            'leave.ai.policy' => [
                'supervisor', 'manager', 'super admin', 'HR',
                'compliance officer', 'senior', 'senior manager',
                'executive', 'administrator',
                'finance manager', 'budget manager',
            ],
            // Anomaly detection & burnout flags (HR, compliance, senior management)
            'leave.ai.anomaly' => [
                'HR', 'compliance officer', 'senior manager',
                'executive', 'administrator',
                'finance manager',
            ],
            // Workforce intelligence (managers, HR, senior staff)
            'leave.ai.workforce' => [
                'manager', 'HR', 'senior', 'senior manager',
                'executive', 'administrator',
                'finance manager', 'budget manager',
            ],
            // Executive forecasting & liability analysis
            'leave.ai.executive' => [
                'senior manager', 'executive', 'administrator',
                'finance manager', 'budget manager',
            ],
        ];

        foreach ($aiRoleMap as $permission => $roles) {
            $perm = Permission::findByName($permission, 'web');
            foreach ($roles as $roleName) {
                $role = Role::findByName($roleName, 'web');
                if (! $role->hasPermissionTo($perm)) {
                    $role->givePermissionTo($perm);
                }
            }
        }

        // ----------------------------
        // HelpDesk permissions
        // helpdesk.tickets.*     — ticket management
        // helpdesk.livechat.*    — live chat conversations
        // helpdesk.kb.*          — knowledge base articles
        // helpdesk.settings.*    — module configuration
        // ----------------------------
        $helpdeskPermissions = [
            // Tickets
            'helpdesk.tickets.view',
            'helpdesk.tickets.create',
            'helpdesk.tickets.edit',
            'helpdesk.tickets.delete',
            'helpdesk.tickets.assign',
            'helpdesk.tickets.close',
            'helpdesk.tickets.comment',
            'helpdesk.tickets.internal_note',
            'helpdesk.tickets.export',
            // Live Chat
            'helpdesk.livechat.view',
            'helpdesk.livechat.respond',
            'helpdesk.livechat.assign',
            'helpdesk.livechat.solve',
            'helpdesk.livechat.delete',
            'helpdesk.livechat.convert_ticket',
            // Knowledge Base
            'helpdesk.kb.view',
            'helpdesk.kb.create',
            'helpdesk.kb.edit',
            'helpdesk.kb.delete',
            'helpdesk.kb.publish',
            // Settings
            'helpdesk.settings.view',
            'helpdesk.settings.manage',
            // Service Catalogue
            'helpdesk.catalogue.view',
            'helpdesk.catalogue.manage',
            // Service Requests
            'helpdesk.requests.view',
            'helpdesk.requests.create',
            'helpdesk.requests.edit',
            'helpdesk.requests.approve',
            'helpdesk.requests.fulfill',
            // Event Management
            'helpdesk.events.view',
            'helpdesk.events.create',
            'helpdesk.events.manage',
            // Release Management
            'helpdesk.releases.view',
            'helpdesk.releases.manage',
            'helpdesk.releases.deploy',
            // CMDB
            'helpdesk.cmdb.view',
            'helpdesk.cmdb.create',
            'helpdesk.cmdb.edit',
            'helpdesk.cmdb.delete',
        ];

        foreach ($helpdeskPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ── Tender Pursuit Management ──────────────────────────────────────────
        $tenderPermissions = [
            'tenders.view',
            'tenders.create',
            'tenders.edit',
            'tenders.delete',
            'tenders.manage',
        ];

        foreach ($tenderPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}
