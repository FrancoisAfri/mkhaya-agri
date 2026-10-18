<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-025 -- manufacturing packages, sold the way every other module is: as add-ons in the
 * existing billing catalogue. Priced at nothing here; the operator prices them in the Subscription
 * Builder. Billing, invoicing and dunning stay where they are.
 */
return new class extends Migration
{
    private const PACKAGES = [
        'manufacturing_essentials' => 'Manufacturing — Essentials package',
        'manufacturing_professional' => 'Manufacturing — Professional package',
        'manufacturing_enterprise' => 'Manufacturing — Enterprise package',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('billing_modules')) {
            return;
        }
        $sort = (int) DB::table('billing_modules')->max('sort');
        foreach (self::PACKAGES as $code => $name) {
            DB::table('billing_modules')->updateOrInsert(['code' => $code], [
                'name' => $name, 'category' => 'Operations', 'price_monthly' => 0, 'is_sellable' => true, 'is_active' => true,
                'sort' => ++$sort, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_modules')) {
            DB::table('billing_modules')->whereIn('code', array_keys(self::PACKAGES))->delete();
        }
    }
};
