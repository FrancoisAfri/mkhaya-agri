<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Supplier Intelligence & Due Diligence, sold as an add-on to Supplier Management.
 *
 * Not a module of its own, by decision: it is worthless without a supplier directory to hang off,
 * and a customer who buys it is extending something they already run rather than starting something
 * new. So it bills as an add-on — `supplier_due_diligence` — and its screens live inside the supplier
 * module's own navigation rather than in a hub of their own.
 *
 * The entitlement is what everything else checks. A tenant without the add-on keeps the module
 * exactly as it was before any of this was built: the directory, onboarding, KYC, appraisals and
 * sourcing all work, and the due-diligence panels simply are not there. That is the property that
 * makes this safe to ship to every existing customer at once.
 *
 * Priced above the base module because each check has a real per-call cost behind it once a provider
 * is licensed, and the customer is buying the engine rather than the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'supplier_due_diligence'],
            [
                'name' => 'Supplier Intelligence & Due Diligence',
                'category' => 'Operations',
                'price_monthly' => 450,
                'is_sellable' => true,
                'is_active' => true,
                'sort' => 200,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // Every tenant that already runs Supplier Management gets it switched on, because this ships
        // as an upgrade to a module they are already paying for rather than as a new sale — and a
        // customer discovering the feature has vanished is a worse first impression than one
        // discovering it has appeared. Priced at zero for those tenants: they did not agree to a new
        // charge, and quietly billing them for one would be indefensible.
        //
        // New tenants buy it through the Subscription Builder like any other add-on.
        $existing = DB::table('company_modules')
            ->where('module_code', 'supplier_management')
            ->pluck('company_id')
            ->unique();

        foreach ($existing as $companyId) {
            DB::table('company_modules')->updateOrInsert(
                ['company_id' => $companyId, 'module_code' => 'supplier_due_diligence'],
                [
                    'price_monthly' => 0,
                    'source' => 'addon',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('company_modules')->where('module_code', 'supplier_due_diligence')->delete();
        DB::table('billing_modules')->where('code', 'supplier_due_diligence')->delete();
    }
};
