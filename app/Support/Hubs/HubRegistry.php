<?php

namespace App\Support\Hubs;

use App\Modules\CRM\Support\CrmHubs;
use App\Modules\Operations\ProjectManagement\Support\ProjectHubs;
use App\Modules\Operations\Manufacturing\Support\ManufacturingHubs;
use App\Modules\Operations\WarehouseManagement\Support\WarehouseHubs;
use App\Modules\Procurement\Governance\Support\ProcurementGovernanceHubs;
use App\Support\Hubs\Modules\AccountingHubs;
use App\Support\Hubs\Modules\AssetHubs;
use App\Support\Hubs\Modules\AttendanceHubs;
use App\Support\Hubs\Modules\BoardingHubs;
use App\Support\Hubs\Modules\BudgetHubs;
use App\Support\Hubs\Modules\ClaimsHubs;
use App\Support\Hubs\Modules\CompanySettingsHubs;
use App\Support\Hubs\Modules\ContentManagementHubs;
use App\Support\Hubs\Modules\ContractManagementHubs;
use App\Support\Hubs\Modules\DisciplinaryHubs;
use App\Support\Hubs\Modules\EsignHubs;
use App\Support\Hubs\Modules\FinanceExecutiveHubs;
use App\Support\Hubs\Modules\FleetHubs;
use App\Support\Hubs\Modules\LeaveHubs;
use App\Support\Hubs\Modules\LmsHubs;
use App\Support\Hubs\Modules\OffboardingHubs;
use App\Support\Hubs\Modules\PayrollBureauHubs;
use App\Support\Hubs\Modules\PayrollHubs;
use App\Support\Hubs\Modules\PerformanceHubs;
use App\Support\Hubs\Modules\PettyCashHubs;
use App\Support\Hubs\Modules\PolicyHubs;
use App\Support\Hubs\Modules\PopiaHubs;
use App\Support\Hubs\Modules\ProcurementRequestHubs;
use App\Support\Hubs\Modules\RecruitmentHubs;
use App\Support\Hubs\Modules\ReportsHubs;
use App\Support\Hubs\Modules\ResourceHubs;
use App\Support\Hubs\Modules\RolesPermissionsHubs;
use App\Support\Hubs\Modules\StaffLoanHubs;
use App\Support\Hubs\Modules\SupplierHubs;
use App\Modules\Operations\DocumentManagement\Support\DocumentHubs;
use App\Modules\Operations\Sheq\Support\SheqHubs;
use App\Support\Hubs\Modules\SystemSettingsHubs;
use App\Support\Hubs\Modules\UserManagementHubs;

/**
 * Maps a module code_name to its ModuleHubs config class, so the generic middleware/controller
 * can serve any hubbed module without per-module wiring.
 */
class HubRegistry
{
    /** @var array<string, class-string<ModuleHubs>> */
    private const MAP = [
        'crm'                   => CrmHubs::class,
        'asset_management'      => AssetHubs::class,
        'fleet_management'      => FleetHubs::class,
        'accounting'            => AccountingHubs::class,
        'disciplinary'          => DisciplinaryHubs::class,
        'contract_management'   => ContractManagementHubs::class,
        'payroll'               => PayrollHubs::class,
        'learning_management'   => LmsHubs::class,
        'time_attendance'       => AttendanceHubs::class,
        'leave_management'      => LeaveHubs::class,
        'performance_appraisal' => PerformanceHubs::class,
        'project_management'    => ProjectHubs::class,
        'procurement_governance' => ProcurementGovernanceHubs::class,

        // ── Hubbed in the second wave ────────────────────────────────────────────────────────
        // Landing pages for these are served generically from routes/modules/hubs.php rather than
        // each module's own route file. The two single-screen modules at the end have no hub to
        // land on — they are registered so nav/tier resolution treats every module alike.
        'supplier_management'   => SupplierHubs::class,
        'recruitment'           => RecruitmentHubs::class,
        'claims'                => ClaimsHubs::class,
        'resources'             => ResourceHubs::class,
        'policy_enforcement'    => PolicyHubs::class,
        'content_management'    => ContentManagementHubs::class,
        'petty_cash'            => PettyCashHubs::class,
        'procurement_requests'  => ProcurementRequestHubs::class,
        'system_settings'       => SystemSettingsHubs::class,
        'staff_loans'           => StaffLoanHubs::class,
        'budget_management'     => BudgetHubs::class,
        'offboarding'           => OffboardingHubs::class,
        'popia_compliance'      => PopiaHubs::class,
        'boarding'              => BoardingHubs::class,
        'reports'               => ReportsHubs::class,
        'user_management'       => UserManagementHubs::class,
        'company_settings'      => CompanySettingsHubs::class,
        'esign'                 => EsignHubs::class,
        'roles_permissions'     => RolesPermissionsHubs::class,
        'finance_executive'     => FinanceExecutiveHubs::class,
        'payroll_bureau'        => PayrollBureauHubs::class,

        // Operations & Support — SHEQ. Declares its own hub routes in routes/modules/sheq.php.
        'sheq'                  => SheqHubs::class,
        'document_management'   => DocumentHubs::class,
        'manufacturing'         => ManufacturingHubs::class,
        'warehouse_management'  => WarehouseHubs::class,

        // Finance — Tax Management & Compliance. Declares its own hub routes in routes/modules/tax.php.
        'tax_management'        => \App\Modules\Finance\Tax\Support\TaxHubs::class,

        // Workflow & Automation. Declares its own hub routes in routes/modules/workflow.php.
        'workflow'              => \App\Modules\Workflow\Support\WorkflowHubs::class,

        // Mkhaya Agri — farm management. Declares its own routes in routes/modules/agri.php.
        'agri'                  => \App\Modules\Agri\Support\AgriHubs::class,
    ];

    /**
     * Modules whose hub landing routes are registered generically (routes/modules/hubs.php).
     * The first-wave modules declare theirs inside their own route files.
     *
     * @var list<string>
     */
    public const GENERIC_HUB_ROUTES = [
        'supplier_management', 'recruitment', 'claims', 'resources', 'policy_enforcement',
        'content_management', 'petty_cash', 'procurement_requests',
        'system_settings', 'staff_loans', 'budget_management',
        'offboarding', 'popia_compliance',
        'boarding', 'reports', 'user_management', 'company_settings', 'esign', 'roles_permissions',
        'finance_executive', 'payroll_bureau',
    ];

    /** @return class-string<ModuleHubs>|null */
    public static function for(string $code): ?string
    {
        return self::MAP[$code] ?? null;
    }

    /** @return array<string, class-string<ModuleHubs>> code_name => hubs class */
    public static function all(): array
    {
        return self::MAP;
    }
}
