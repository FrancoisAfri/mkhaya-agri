<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Module pricing (à la carte) + per-company module composition. Each sellable
 * module has its own monthly price; companies can subscribe to a bundle (plan) OR
 * pick individual modules. `company_modules` is the effective, priced module set
 * per company (source = bundle | addon). Core modules are always included (price 0,
 * not sellable on their own).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sellable module catalog with individual prices.
        if (! Schema::hasTable('billing_modules')) {
            Schema::create('billing_modules', function (Blueprint $t) {
                $t->id();
                $t->string('code', 60)->unique();
                $t->string('name', 120);
                $t->string('category', 60)->nullable();
                $t->decimal('price_monthly', 12, 2)->default(0);
                $t->boolean('is_sellable')->default(true); // core modules = false (always included)
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort')->default(0);
                $t->timestamps();
            });
        }

        // Effective module set per company (bundle-provided + à la carte add-ons).
        if (! Schema::hasTable('company_modules')) {
            Schema::create('company_modules', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('module_code', 60);
                $t->decimal('price_monthly', 12, 2)->default(0); // snapshot of price paid
                $t->string('source', 12)->default('addon');      // bundle | addon
                $t->timestamps();
                $t->unique(['company_id', 'module_code']);
            });
        }

        // Seed the catalog. Core modules are always-on (not individually sellable).
        $core = [
            'user_management' => 'User Management', 'roles_permissions' => 'Roles & Permissions',
            'reports' => 'Reports', 'company_settings' => 'Company Settings', 'system_settings' => 'System Settings',
        ];
        // Sellable modules with a sensible default price (admin adjusts on the Modules page).
        $sellable = [
            'leave_management' => ['HR & People', 120], 'time_attendance' => ['HR & People', 120],
            'payroll' => ['HR & People', 250], 'payroll_bureau' => ['HR & People', 300],
            'performance_appraisal' => ['HR & People', 150], 'recruitment' => ['HR & People', 150],
            'boarding' => ['HR & People', 90], 'offboarding' => ['HR & People', 90],
            'learning_management' => ['HR & People', 180], 'disciplinary' => ['HR & People', 120],
            'claims' => ['Finance', 120], 'staff_loans' => ['Finance', 120], 'petty_cash' => ['Finance', 90],
            'accounting' => ['Finance', 250], 'budget_management' => ['Finance', 150],
            'procurement_requests' => ['Operations', 150], 'supplier_management' => ['Operations', 150],
            'contract_management' => ['Operations', 150], 'asset_management' => ['Operations', 150],
            'fleet_management' => ['Operations', 180], 'marketplace' => ['Operations', 120],
            'tender_pursuit' => ['Operations', 200], 'resources' => ['Operations', 90],
            'crm' => ['Sales & Service', 180], 'help_desk' => ['Sales & Service', 120],
            'surveys' => ['Sales & Service', 90], 'conferences' => ['Sales & Service', 120],
            'content_management' => ['Sales & Service', 90], 'recognition' => ['Culture', 90],
            'policy_enforcement' => ['Governance', 150], 'popia_compliance' => ['Governance', 150],
            'board_committee' => ['Governance', 200], 'whistleblower' => ['Governance', 120],
        ];

        $now = now();
        $sort = 0;
        foreach ($core as $code => $name) {
            DB::table('billing_modules')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'category' => 'Core', 'price_monthly' => 0, 'is_sellable' => false, 'is_active' => true, 'sort' => $sort++, 'updated_at' => $now, 'created_at' => $now]
            );
        }
        foreach ($sellable as $code => [$category, $price]) {
            DB::table('billing_modules')->updateOrInsert(
                ['code' => $code],
                ['name' => Str::of($code)->replace('_', ' ')->title()->toString(), 'category' => $category, 'price_monthly' => $price, 'is_sellable' => true, 'is_active' => true, 'sort' => $sort++, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_modules');
        Schema::dropIfExists('billing_modules');
    }
};
