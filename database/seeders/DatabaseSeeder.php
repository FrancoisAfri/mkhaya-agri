<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Core (production-safe) seed.
 *
 * This seeder runs ONLY the data a live system requires: roles & permissions,
 * module/ribbon/navigation registration, reference/lookup data, business rules,
 * and reusable templates. It contains NO sample/demo records.
 *
 * Demo & sample data (organization structure, sample employees, fleet, etc.)
 * lives in {@see DemoDataSeeder} and is auto-run here only when APP_ENV is not
 * "production". Set SEED_DEMO_DATA=false to force it off even when APP_ENV is
 * mis-set to "local" on a live box (or SEED_DEMO_DATA=true to force it on). To
 * load demo data explicitly: `php artisan db:seed --class=DemoDataSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ── Roles & permissions ───────────────────────────────────────────────
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(LmsPaTrainingRoleSeeder::class);
        $this->call(CrmSalesRolesSeeder::class);

        // ── Admin user (depends on roles) ─────────────────────────────────────
        $this->call(AdminUserSeeder::class);

        // ── Module / ribbon / navigation registration ─────────────────────────
        $this->call(ModuleSeeders::class);
        $this->call(SaTaxYearSeeder::class);
        $this->call(CommunicationsModuleSeeder::class);
        $this->call(HumanResourcesLeaveAttendanceSeeder::class);
        $this->call(RecruitmentBoardingSeeder::class);
        $this->call(RecruitmentRibbonSeeder::class);
        $this->call(PerformanceAppraisalSeeder::class);
        $this->call(AssetModuleSeeder::class);
        $this->call(PayrollModuleSeeder::class);
        $this->call(PayrollBureauModuleSeeder::class);
        $this->call(PayrollRibbonSeeder::class);
        $this->call(SupplyChainNavigationSeeder::class);
        $this->call(CrmModuleSeeder::class);

        // ── Reference / lookup data ───────────────────────────────────────────
        $this->call(CountriesTableSeeder::class);
        $this->call(Provinces_Seeder::class);
        $this->call(CitiesSeeder::class);
        $this->call(Public_holidays_Seeder::class);
        $this->call(LmsSetaSeeder::class);
        $this->call(HrLookupSeeder::class);
        $this->call(AssetCategorySeeder::class);

        // ── Leave configuration ───────────────────────────────────────────────
        $this->call(LeaveTypesSeeder::class);
        $this->call(LeaveProfileSeeder::class);
        $this->call(LeaveCustomSeeder::class);
        $this->call(LeaveConfigurationSeeder::class);

        // ── Policies & governance ─────────────────────────────────────────────
        $this->call(PolicyCategorySeeder::class);
        $this->call(PolicySeeder::class);

        // ── Fleet configuration (config only — sample vehicles are demo) ──────
        $this->call(FleetSettingSeeder::class);
        $this->call(FleetDefaultsSeeder::class);
        $this->call(FleetApprovalStepSeeder::class);

        // ── Business rules ────────────────────────────────────────────────────
        $this->call(PayrollAnomalyRulesSeeder::class);
        $this->call(PayrollPolicyRulesSeeder::class);
        $this->call(StaffLoanPolicyRulesSeeder::class);
        $this->call(PettyCashPolicyRulesSeeder::class);

        // ── Templates & help content ──────────────────────────────────────────
        $this->call(CrmEmailTemplateSeeder::class);
        $this->call(CrmSurveyTemplateSeeder::class);
        $this->call(CrmAutomationTemplateSeeder::class);
        $this->call(CrmSequenceTemplateSeeder::class);
        $this->call(HowToSeeder::class);
        $this->call(HowToCoverageSeeder::class); // Baseline how-to for every uncovered ribbon
        $this->call(FinanceGlSeeder::class); // Chart of accounts + fiscal periods
        $this->call(FinanceAccessSeeder::class); // Manual-journal posting permission
        $this->call(OffboardingPermissionSeeder::class);   // Termination-authority gate
        $this->call(AccountingModuleSeeder::class); // Nav + access for all finance users
        $this->call(AgriTrainingLibrarySeeder::class); // SAQA agricultural courses for every farm

        // ── Demo / sample data (org structure, sample employees, fleet, etc.) ──
        // Never on a live system. By default this follows APP_ENV, but an
        // explicit SEED_DEMO_DATA flag overrides it — so a production box that
        // is (mis)configured as APP_ENV=local still gets NO demo data when
        // SEED_DEMO_DATA=false. Set SEED_DEMO_DATA=true to force demo anywhere.
        $seedDemo = filter_var(
            env('SEED_DEMO_DATA', ! app()->environment('production')),
            FILTER_VALIDATE_BOOL
        );

        if ($seedDemo) {
            $this->call(DemoDataSeeder::class);
        }

        $this->assignSeedDataToDefaultCompany();
    }

    /**
     * Seeders run in the console with no active tenant, so the row-level-security
     * DEFAULT tags every seeded row with a NULL company_id. Hand all of it to the
     * default company (id 1) so: (a) its scoped users actually see it under RLS,
     * and (b) a new tenant's reference data (which is cloned FROM company 1) isn't
     * empty. Only NULL rows are touched — real per-tenant data keeps its owner.
     */
    private function assignSeedDataToDefaultCompany(): void
    {
        $tables = \Illuminate\Support\Facades\DB::select(
            "select table_name from information_schema.columns
             where table_schema = current_schema() and column_name = 'company_id'"
        );
        foreach ($tables as $t) {
            $name = $t->table_name;
            if (in_array($name, ['companies', 'users'], true)) {
                continue; // platform tables — leave user/company ownership as seeded
            }
            try {
                \Illuminate\Support\Facades\DB::table($name)->whereNull('company_id')->update(['company_id' => 1]);
            } catch (\Throwable $e) {
                // best-effort per table
            }
        }
    }
}
