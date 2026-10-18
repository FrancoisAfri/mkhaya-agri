<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Core\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleSeeders extends Seeder
{
    /**
     * Ribbon access tiers — mirror App\Core\Ribbon\Models\Ribbon::userRights().
     *
     * A ribbon appears in the nav when  ribbon.access_level <= the user's module
     * access level (see BuildsNavigation), so a LOWER number = visible to MORE
     * users. Assign each ribbon the lowest tier that is still appropriate:
     *   READ      view-only screens everyone with the module may see
     *   WRITE     create / edit records
     *   MODIFY    managers / secretariat: approvals, templates, structural changes
     *   ADMIN     module administration: roles, security, sessions, configuration
     *   SUPERUSER system / setup level (also the safe default for un-tiered ribbons)
     */
    private const READ      = 1;
    private const WRITE     = 2;
    private const MODIFY    = 3;
    private const ADMIN     = 4;
    private const SUPERUSER = 5;

    public function run(): void
    {
        DB::transaction(function () {

            $userId = User::first()?->id ?? 1;

            $category = Category::firstOrCreate([
                'name' => 'System Administration'
            ], [
                'active' => 1,
                'description' => 'System Administration',
                'icon' => 'building',
            ]);

            $this->createCategoryAccess($category->id, $userId);

            $modules = $this->modulesData();

            foreach ($modules as $moduleData) {
                $module = $this->createModule($category->id, $moduleData);

                foreach ($moduleData['ribbons'] as $index => $ribbonData) {
                    $this->createRibbon($module, $ribbonData, $index + 1, $userId);
                }

                $this->createModuleAccess($module->id, $userId);
            }

            $hrCategory = Category::firstOrCreate([
                'name' => 'Human Resources'
            ], [
                'active' => 1,
                'description' => 'Human Resources',
                'icon' => 'users-cog',
            ]);

            $this->createCategoryAccess($hrCategory->id, $userId);

            $policyModuleData = $this->policyEnforcementModuleData();
            $policyModule = $this->createModule($hrCategory->id, $policyModuleData);

            foreach ($policyModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($policyModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($policyModule->id, $userId);

            // ── Recruitment module ────────────────────────────────────────────
            $recruitmentModuleData = $this->recruitmentModuleData();
            $recruitmentModule = $this->createModule($hrCategory->id, $recruitmentModuleData);

            foreach ($recruitmentModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($recruitmentModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($recruitmentModule->id, $userId);

            // ── Boarding module ───────────────────────────────────────────────
            $boardingModuleData = $this->boardingModuleData();
            $boardingModule = $this->createModule($hrCategory->id, $boardingModuleData);

            foreach ($boardingModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($boardingModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($boardingModule->id, $userId);

            // ── Asset Management category & module ────────────────────────────
            $assetCategory = Category::firstOrCreate([
                'name' => 'Asset Management'
            ], [
                'active'      => 1,
                'description' => 'Asset Management',
                'icon'        => 'boxes',
            ]);

            $this->createCategoryAccess($assetCategory->id, $userId);

            $assetModuleData = $this->assetModuleData();
            $assetModule = $this->createModule($assetCategory->id, $assetModuleData);

            foreach ($assetModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($assetModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($assetModule->id, $userId);

            // ── Fleet Management module ────────────────────────────────────────
            $fleetModuleData = $this->fleetModuleData();
            $fleetModule = $this->createModule($assetCategory->id, $fleetModuleData);

            foreach ($fleetModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($fleetModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($fleetModule->id, $userId);

            // ── Disciplinary & Misconduct module ──────────────────────────────
            $disciplinaryModuleData = $this->disciplinaryModuleData();
            $disciplinaryModule = $this->createModule($hrCategory->id, $disciplinaryModuleData);

            foreach ($disciplinaryModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($disciplinaryModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($disciplinaryModule->id, $userId);

            // ── Payroll module ────────────────────────────────────────────────
            $payrollModuleData = $this->payrollModuleData();
            $payrollModule     = $this->createModule($hrCategory->id, $payrollModuleData);

            foreach ($payrollModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($payrollModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($payrollModule->id, $userId);

            // ── Finance category & Claims module ──────────────────────────────
            $financeCategory = Category::firstOrCreate(
                ['name' => 'Finance'],
                ['active' => 1, 'description' => 'Finance & Claims Management', 'icon' => 'wallet']
            );

            $this->createCategoryAccess($financeCategory->id, $userId);

            // ── Accounting (General Ledger) module ────────────────────────────
            $accountingModuleData = $this->accountingModuleData();
            $accountingModule     = $this->createModule($financeCategory->id, $accountingModuleData);
            foreach ($accountingModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($accountingModule, $ribbonData, $index + 1, $userId);
            }
            $this->createModuleAccess($accountingModule->id, $userId);

            $claimsModuleData = $this->claimsModuleData();
            $claimsModule     = $this->createModule($financeCategory->id, $claimsModuleData);

            foreach ($claimsModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($claimsModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($claimsModule->id, $userId);

            // ── Petty Cash module ─────────────────────────────────────────────
            $pettyCashModuleData = $this->pettyCashModuleData();
            $pettyCashModule     = $this->createModule($financeCategory->id, $pettyCashModuleData);

            foreach ($pettyCashModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($pettyCashModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($pettyCashModule->id, $userId);

            // ── Staff Loans module ────────────────────────────────────────────
            $staffLoanModuleData = $this->staffLoanModuleData();
            $staffLoanModule     = $this->createModule($financeCategory->id, $staffLoanModuleData);

            foreach ($staffLoanModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($staffLoanModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($staffLoanModule->id, $userId);

            // ── Executive Finance Dashboard module ────────────────────────────
            $execDashModuleData = $this->executiveDashboardModuleData();
            $execDashModule     = $this->createModule($financeCategory->id, $execDashModuleData);

            foreach ($execDashModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($execDashModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($execDashModule->id, $userId);

            // ── Operations & Support category ─────────────────────────────────
            $opsCategory = Category::firstOrCreate(
                ['name' => 'Operations & Support'],
                ['active' => 1, 'description' => 'Operations & Support', 'icon' => 'headset']
            );

            $this->createCategoryAccess($opsCategory->id, $userId);

            // ── POPIA Compliance module (under Operations & Support) ──────────
            $complianceModuleData = $this->popiaComplianceModuleData();
            $complianceModule     = $this->createModule($opsCategory->id, $complianceModuleData);

            foreach ($complianceModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($complianceModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($complianceModule->id, $userId);

            // ── E-Sign module (under Operations & Support) ────────────────────
            $eSignModuleData = $this->eSignModuleData();
            $eSignModule     = $this->createModule($opsCategory->id, $eSignModuleData);

            foreach ($eSignModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($eSignModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($eSignModule->id, $userId);

            // ── Marketing & Sales category (CRM) ──────────────────────────────
            $marketingCategory = Category::firstOrCreate(
                ['name' => 'Marketing & Sales'],
                ['active' => 1, 'description' => 'Marketing & Sales', 'icon' => 'bullhorn']
            );
            $this->createCategoryAccess($marketingCategory->id, $userId);

            // ── CRM module (under Marketing & Sales) ──────────────────────────
            $crmModuleData = $this->crmModuleData();
            $crmModule     = $this->createModule($marketingCategory->id, $crmModuleData);

            foreach ($crmModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($crmModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($crmModule->id, $userId);

            // ── Learning Management System module ─────────────────────────────
            $lmsModuleData = $this->lmsModuleData();
            $lmsModule     = $this->createModule($hrCategory->id, $lmsModuleData);

            foreach ($lmsModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($lmsModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($lmsModule->id, $userId);

            // ── Offboarding module ────────────────────────────────────────────
            $offboardingModuleData = $this->offboardingModuleData();
            $offboardingModule     = $this->createModule($hrCategory->id, $offboardingModuleData);

            foreach ($offboardingModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($offboardingModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($offboardingModule->id, $userId);

            // ── Time & Attendance module ──────────────────────────────────────
            $attendanceModuleData = $this->timeAttendanceModuleData();
            $attendanceModule     = $this->createModule($hrCategory->id, $attendanceModuleData);

            foreach ($attendanceModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($attendanceModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($attendanceModule->id, $userId);

            // ── Leave Management module ────────────────────────────────────────
            $leaveModuleData = $this->leaveManagementModuleData();
            $leaveModule     = $this->createModule($hrCategory->id, $leaveModuleData);

            foreach ($leaveModuleData['ribbons'] as $index => $ribbonData) {
                $this->createRibbon($leaveModule, $ribbonData, $index + 1, $userId);
            }

            $this->createModuleAccess($leaveModule->id, $userId);

            // ── Cleanup: retire deprecated categories ─────────────────────────
            // Mkhaya Agri has no governance or helpdesk categories; anything still filed under
            // the Hub's old names is re-homed to Operations & Support.
            foreach (['Support', 'Governance', 'Governance & Compliance Management', 'Governance Management'] as $deprecated) {
                $stale = Category::where('name', $deprecated)->first();
                if (! $stale) {
                    continue;
                }

                Modules::where('category_id', $stale->id)->update(['category_id' => $opsCategory->id]);

                CategoryAccess::where('category_id', $stale->id)->delete();
                $stale->delete();
            }
        });
    }

    /**
     * CRM module (Marketing & Sales).
     */
    private function crmModuleData(): array
    {
        return [
            'name'      => 'CRM',
            'code_name' => 'crm',
            'path'      => '/crm',
            'icon'      => 'users',
            'ribbons'   => \App\Modules\CRM\Support\CrmHubs::navRibbons(),
        ];
    }

    /**
     * CATEGORY ACCESS
     */
    private function createCategoryAccess($categoryId, $userId)
    {
        CategoryAccess::firstOrCreate([
            'category_id' => $categoryId,
            'user_id'     => $userId,
        ], [
            'active'       => 1,
            'access_level' => 5,
        ]);
    }

    /**
     * MODULE CREATION
     */
    private function createModule($categoryId, $data)
    {
        $existing = Modules::where('code_name', $data['code_name'])->first();

        if ($existing) {
            $existing->update([
                'active'       => 1,
                'category_id'  => $categoryId,
                'name'         => $data['name'],
                'path'         => $data['path'],
                'font_awesome' => $data['icon'],
            ]);
            return $existing;
        }

        return Modules::create([
            'uuid'         => (string) Str::uuid(),
            'code_name'    => $data['code_name'],
            'active'       => 1,
            'category_id'  => $categoryId,
            'name'         => $data['name'],
            'path'         => $data['path'],
            'font_awesome' => $data['icon'],
        ]);
    }

    /**
     * RIBBON CREATION + ACCESS
     */
    private function createRibbon($module, $data, $order, $userId)
    {
        // Each ribbon declares its own tier (see the access-level constants and
        // Ribbon::userRights()). Un-tiered ribbons fall back to SUPERUSER so a new
        // ribbon is locked to admins until it is deliberately opened up.
        $ribbon = Ribbon::firstOrNew([
            'module_id'   => $module->id,
            'ribbon_name' => $data['name'],
        ]);

        // uuid is assigned once, on first insert, and never regenerated.
        if (! $ribbon->exists) {
            $ribbon->uuid = (string) Str::uuid();
        }

        $ribbon->fill([
            'active'       => 1,
            'sort_order'   => $order,
            'ribbon_path'  => $data['path'],
            'description'  => $data['name'],
            'font_awesome' => $data['icon'] ?? 'circle',
            'access_level' => $data['access_level'] ?? self::SUPERUSER,
        ])->save();

        $this->createRibbonAccess($ribbon->id, $userId);
    }

    /**
     * RIBBON ACCESS
     */
    private function createRibbonAccess($ribbonId, $userId)
    {
        RibbonAccess::firstOrCreate([
            'ribbon_id' => $ribbonId,
            'user_id'   => $userId,
        ], [
            'active'       => 1,
            'access_level' => 5,
        ]);
    }

    /**
     * MODULE ACCESS
     */
    private function createModuleAccess($moduleId, $userId)
    {
        ModuleAccess::firstOrCreate([
            'module_id' => $moduleId,
            'user_id'   => $userId,
        ], [
            'active'       => 1,
            'access_level' => 5,
        ]);
    }

    /**
     * DATA
     */
    private function modulesData(): array
    {
        return [
            [
                'name'      => 'User Management',
                'code_name' => 'user_management',
                'path'      => '/user-management',
                'icon'      => 'users',
                'ribbons'   => [
                    ['name' => 'Profile Updates Approval', 'path' => 'settings/profile-updates-approval', 'icon' => 'users'],
                    ['name' => 'Users Access',             'path' => 'settings/user-access',             'icon' => 'key'],
                    ['name' => 'Manage Users',             'path' => 'settings/manage-users',            'icon' => 'users'],
                ]
            ],
            [
                'name'      => 'System Settings',
                'code_name' => 'system_settings',
                'path'      => '/system-settings',
                'icon'      => 'cog',
                'ribbons'   => [
                    ['name' => 'Public Holidays',        'path' => 'settings/public-holidays',  'icon' => 'calendar'],
                    ['name' => 'Document Types',         'path' => 'settings/document-types',   'icon' => 'file'],
                    ['name' => 'Company Identity',       'path' => 'settings/setup',            'icon' => 'cog'],
                    ['name' => 'Modules Management',     'path' => 'settings/category',         'icon' => 'building'],
                    ['name' => 'System Restoration',     'path' => 'settings/restoration',      'icon' => 'trash-restore'],
                    ['name' => 'Platform Admins',        'path' => 'settings/platform-admins',   'icon' => 'user-shield'],
                    ['name' => 'Offline Guard Policy',   'path' => 'settings/offline-policy',     'icon' => 'wifi-off'],
                    ['name' => 'How To',             'path' => 'how-to',            'icon' => 'fa-book-atlas'],

                ]
            ],
            [
                'name'      => 'Company Settings',
                'code_name' => 'company_settings',
                'path'      => '/company-settings',
                'icon'      => 'building',
                'ribbons'   => [
                    ['name' => 'Organisation Structure',   'path' => 'settings/organization',   'icon' => 'sitemap'],
                    ['name' => 'Positions',                'path' => 'settings/positions',                'icon' => 'id-badge'],
                ]
            ],
            [
                'name'      => 'Roles & Permissions',
                'code_name' => 'roles_permissions',
                'path'      => '/roles-permissions',
                'icon'      => 'user-shield',
                'ribbons'   => [
                    ['name' => 'Roles',       'path' => 'settings/roles',       'icon' => 'fa-user-tag'],
                    ['name' => 'Permissions', 'path' => 'settings/permissions', 'icon' => 'lock'],
                ]
            ],
            [
                'name'      => 'Reports',
                'code_name' => 'reports',
                'path'      => '/reports',
                'icon'      => 'chart-bar',
                'ribbons'   => [
                    ['name' => 'Audits',       'path' => 'settings/audits',       'icon' => 'file'],
                    ['name' => 'System Usage', 'path' => 'settings/manage-users/report', 'icon' => 'chart-line'],
                    ['name' => 'User Rights',  'path' => 'settings/user-access',  'icon' => 'shield'],
                ]
            ],
        ];
    }

    private function recruitmentModuleData(): array
    {
        return [
            'name'      => 'Recruitment',
            'code_name' => 'recruitment',
            'path'      => '/recruitment/dashboard',
            'icon'      => 'briefcase',
            'ribbons'   => [
                ['name' => 'Dashboard',           'path' => 'recruitment/dashboard',                            'icon' => 'gauge-high'],
                ['name' => 'Jobs',                'path' => 'recruitment/jobs',                                  'icon' => 'briefcase'],
                ['name' => 'Vacancies',           'path' => 'recruitment/vacancies',                             'icon' => 'bullhorn'],
                ['name' => 'Requisitions',        'path' => 'recruitment/requisitions',                          'icon' => 'file-signature'],
                ['name' => 'Candidates',          'path' => 'recruitment/candidates',                            'icon' => 'user-tie'],
                ['name' => 'Approvals',           'path' => 'recruitment/approvals/pending',                     'icon' => 'check-double'],
                ['name' => 'Succession Planning', 'path' => 'recruitment/succession',                            'icon' => 'sitemap'],
                ['name' => 'Internal Job Board',  'path' => 'recruitment/internal/job-board',                    'icon' => 'building-user'],
                ['name' => 'Offers',              'path' => 'offers',                                            'icon' => 'file-contract'],
                ['name' => 'Analytics',           'path' => 'recruitment/analytics',                             'icon' => 'chart-bar'],
                ['name' => 'Reports',             'path' => 'recruitment/reports',                               'icon' => 'chart-line'],
                ['name' => 'Approval Workflows',  'path' => 'recruitment/settings/approval-workflows',           'icon' => 'diagram-project'],
                ['name' => 'Social Accounts',     'path' => 'recruitment/settings/social-accounts',              'icon' => 'share-nodes'],
            ]
        ];
    }

    private function boardingModuleData(): array
    {
        return [
            'name'      => 'Boarding',
            'code_name' => 'boarding',
            'path'      => '/pre-boarding',
            'icon'      => 'user-check',
            'ribbons'   => [
                ['name' => 'Pre-Boarding', 'path' => 'pre-boarding', 'icon' => 'clipboard-list'],
                ['name' => 'Onboarding',   'path' => 'onboarding',   'icon' => 'graduation-cap'],
            ]
        ];
    }

    private function assetModuleData(): array
    {
        return [
            'name'      => 'Assets',
            'code_name' => 'asset_management',
            'path'      => '/assets',
            'icon'      => 'boxes',
            'ribbons'   => \App\Support\Hubs\Modules\AssetHubs::navRibbons(),
        ];
    }

    private function fleetModuleData(): array
    {
        return [
            'name'      => 'Fleet Management',
            'code_name' => 'fleet_management',
            'path'      => '/fleet',
            'icon'      => 'car',
            'ribbons'   => \App\Support\Hubs\Modules\FleetHubs::navRibbons(),
        ];
    }

    private function popiaComplianceModuleData(): array
    {
        return [
            'name'      => 'POPIA Compliance',
            'code_name' => 'popia_compliance',
            'path'      => '/compliance',
            'icon'      => 'shield-lock',
            'ribbons'   => [
                ['name' => 'Dashboard',       'path' => 'compliance',          'icon' => 'layout-dashboard'],
                ['name' => 'Breach Register', 'path' => 'compliance/breaches', 'icon' => 'alert-triangle'],
                ['name' => 'Consent Register','path' => 'compliance/consents', 'icon' => 'clipboard-check'],
                ['name' => 'PHI Access Log',  'path' => 'compliance/phi-log',  'icon' => 'eye'],
            ],
        ];
    }

    private function disciplinaryModuleData(): array
    {
        return [
            'name'      => 'Disciplinary & Misconduct',
            'code_name' => 'disciplinary',
            'path'      => '/disciplinary',
            'icon'      => 'gavel',
            'ribbons'   => \App\Support\Hubs\Modules\DisciplinaryHubs::navRibbons(),
        ];
    }

    private function claimsModuleData(): array
    {
        return [
            'name'      => 'Claims Management',
            'code_name' => 'claims',
            'path'      => '/claims',
            'icon'      => 'receipt',
            'ribbons'   => [
                ['name' => 'Dashboard',          'path' => 'claims',                       'icon' => 'layout-dashboard'],
                ['name' => 'My Claims',          'path' => 'claims/my',                    'icon' => 'receipt'],
                ['name' => 'New Claim',          'path' => 'claims/my/create',             'icon' => 'plus-circle'],
                ['name' => 'Approvals',          'path' => 'claims/approvals',             'icon' => 'check-decagram'],
                ['name' => 'Delegations',          'path' => 'claims/approvals/delegations', 'icon' => 'user-group'],
                ['name' => 'Payment Processing',   'path' => 'claims/payments',              'icon' => 'cash'],
                ['name' => 'Reports',              'path' => 'claims/reports',               'icon' => 'chart-bar'],
                ['name' => 'Scheduled Reports',    'path' => 'claims/reports/scheduled',     'icon' => 'calendar-clock'],
                ['name' => 'Executive Dashboard',  'path' => 'claims/executive',             'icon' => 'chart-line'],
                ['name' => 'AI Intelligence',      'path' => 'claims/ai-intelligence',       'icon' => 'robot'],
                ['name' => 'Setup',                'path' => 'claims/setup',                 'icon' => 'settings'],
            ],
        ];
    }

    private function accountingModuleData(): array
    {
        return [
            'name'      => 'Accounting (GL)',
            'code_name' => 'accounting',
            'path'      => '/finance/accounting/journals',
            'icon'      => 'book',
            'ribbons'   => \App\Support\Hubs\Modules\AccountingHubs::navRibbons(),
        ];
    }

    private function pettyCashModuleData(): array
    {
        return [
            'name'      => 'Petty Cash',
            'code_name' => 'petty_cash',
            'path'      => '/petty-cash',
            'icon'      => 'wallet',
            'ribbons'   => [
                ['name' => 'Dashboard',          'path' => 'petty-cash',                          'icon' => 'layout-dashboard'],
                ['name' => 'Floats',             'path' => 'petty-cash/floats',                   'icon' => 'cash-multiple'],
                ['name' => 'New Float',          'path' => 'petty-cash/floats/create',            'icon' => 'plus-circle'],
                ['name' => 'Requests',           'path' => 'petty-cash/requests',                 'icon' => 'file-invoice'],
                ['name' => 'New Request',        'path' => 'petty-cash/requests/create',          'icon' => 'plus-circle'],
                ['name' => 'Reconciliations',    'path' => 'petty-cash/reconciliations',          'icon' => 'scale-balance'],
                ['name' => 'Reports',            'path' => 'petty-cash/reports',                  'icon' => 'chart-bar'],
                ['name' => 'Settings',           'path' => 'petty-cash/settings',                 'icon' => 'settings'],
                ['name' => 'Policy Violations',  'path' => 'petty-cash/settings/violations',      'icon' => 'shield-alert'],
            ],
        ];
    }

    private function staffLoanModuleData(): array
    {
        return [
            'name'      => 'Staff Loans',
            'code_name' => 'staff_loans',
            'path'      => '/staff-loans',
            'icon'      => 'bank',
            'ribbons'   => [
                ['name' => 'Dashboard',           'path' => 'staff-loans',                              'icon' => 'layout-dashboard'],
                ['name' => 'Staff Loans',         'path' => 'staff-loans/loans',                        'icon' => 'bank'],
                ['name' => 'New Loan',            'path' => 'staff-loans/loans/create',                 'icon' => 'plus-circle'],
                ['name' => 'Salary Advances',     'path' => 'staff-loans/advances',                     'icon' => 'cash-fast'],
                ['name' => 'New Advance',         'path' => 'staff-loans/advances/create',              'icon' => 'plus-circle'],
                ['name' => 'Settings',            'path' => 'staff-loans/settings',                     'icon' => 'settings'],
                ['name' => 'Policy Violations',   'path' => 'staff-loans/settings/violations',          'icon' => 'shield-alert'],
            ],
        ];
    }

    private function executiveDashboardModuleData(): array
    {
        return [
            'name'      => 'Finance Executive',
            'code_name' => 'finance_executive',
            'path'      => '/finance/executive',
            'icon'      => 'chart-line',
            'ribbons'   => [
                ['name' => 'Executive Dashboard', 'path' => 'finance/executive/dashboard', 'icon' => 'chart-line'],
            ],
        ];
    }

    private function payrollModuleData(): array
    {
        return [
            'name'      => 'Payroll',
            'code_name' => 'payroll',
            'path'      => '/payroll',
            'icon'      => 'cash-multiple',
            'ribbons'   => \App\Support\Hubs\Modules\PayrollHubs::navRibbons(),
        ];
    }

    private function lmsModuleData(): array
    {
        return [
            'name'      => 'LMS',
            'code_name' => 'learning_management',
            'path'      => '/lms',
            'icon'      => 'school',
            'ribbons'   => \App\Support\Hubs\Modules\LmsHubs::navRibbons(),
        ];
    }

    private function offboardingModuleData(): array
    {
        return [
            'name'      => 'Offboarding',
            'code_name' => 'offboarding',
            'path'      => '/offboarding',
            'icon'      => 'door-exit',
            'ribbons'   => [
                ['name' => 'Offboarding Plans', 'path' => 'offboarding',            'icon' => 'layout-dashboard'],
                ['name' => 'Templates',         'path' => 'offboarding/templates',  'icon' => 'file-description'],
                ['name' => 'Analytics',         'path' => 'offboarding/analytics',  'icon' => 'chart-bar'],
                ['name' => 'Reports',           'path' => 'offboarding/reports',    'icon' => 'file-analytics'],
            ],
        ];
    }

    private function timeAttendanceModuleData(): array
    {
        return [
            'name'      => 'Time & Attendance',
            'code_name' => 'time_attendance',
            'path'      => '/attendance',
            'icon'      => 'clock',
            'ribbons'   => \App\Support\Hubs\Modules\AttendanceHubs::navRibbons(),
        ];
    }

    private function leaveManagementModuleData(): array
    {
        return [
            'name'      => 'Leave Management',
            'code_name' => 'leave_management',
            'path'      => '/leave-management',
            'icon'      => 'calendar-minus',
            'ribbons'   => \App\Support\Hubs\Modules\LeaveHubs::navRibbons(),
        ];
    }

    private function policyEnforcementModuleData(): array
    {
        return [
            'name'      => 'Policy enforcement',
            'code_name' => 'policy_enforcement',
            'path'      => '/policies',
            'icon'      => 'gavel',
            'ribbons'   => [
                ['name' => 'Policies',          'path' => 'policies',          'icon' => 'file-contract'],
                ['name' => 'Policy Categories', 'path' => 'policy-categories', 'icon' => 'tags'],
                ['name' => 'Policy Attestations', 'path' => 'policy-attestations', 'icon' => 'clipboard-check'],
                ['name' => 'Attestation Dashboard', 'path' => 'policy-attestations/dashboard/overview', 'icon' => 'chart-pie'],
                ['name' => 'Policy Violations', 'path' => 'policy-violations', 'icon' => 'exclamation-triangle'],
                ['name' => 'Policy Exceptions', 'path' => 'policy-exceptions', 'icon' => 'shield-alt'],
                ['name' => 'Dashboard',         'path' => 'policy-dashboard',    'icon' => 'chart-line'],
                ['name' => 'Reports',           'path' => 'policy-reports',      'icon' => 'file-alt'],
                ['name' => 'Simulation',        'path' => 'policy-simulation',   'icon' => 'flask'],
                ['name' => 'Policy Quality',    'path' => 'policy-quality',      'icon' => 'check-double'],
            ]
        ];
    }

    private function eSignModuleData(): array
    {
        return [
            'name'      => 'E-Sign',
            'code_name' => 'esign',
            'path'      => '/esign',
            'icon'      => 'file-signature',
            'ribbons'   => [
                ['name' => 'Envelopes',          'path' => 'esign',                       'icon' => 'mail'],
                ['name' => 'New Envelope',        'path' => 'esign/create',                'icon' => 'file-plus'],
            ],
        ];
    }
}
