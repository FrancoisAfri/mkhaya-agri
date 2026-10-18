<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extra trading businesses, sold the way extra websites are.
 *
 * The Accounting module covers one set of books. A tenant who trades as Mkhaya MK, Mkhaya Move and
 * Mkhaya Hub needs three, and the second and third are what is being sold here — the same shape
 * Content Marketing uses for websites, so the subscription screen, the invoice and the reseller
 * commission all handle it without knowing what an entity is.
 *
 * Priced per business rather than in packs of five. A tenant adds a business when they start one,
 * which is a deliberate act that happens rarely; nobody starts five companies at once, and a pack
 * would mean paying for four they have not opened.
 */
return new class extends Migration
{
    private const ADDONS = [
        [
            'type' => 'entities', 'code' => 'entity_1',
            'name' => 'Extra Business (Accounting)',
            'unit_label' => 'businesses', 'units' => 1,
            'price_monthly' => 150.00, 'sort' => 70,
        ],
        [
            'type' => 'entities', 'code' => 'entity_3',
            'name' => 'Extra Businesses — 3',
            'unit_label' => 'businesses', 'units' => 3,
            'price_monthly' => 390.00, 'sort' => 71,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('billing_addons')) {
            return;
        }

        foreach (self::ADDONS as $addon) {
            DB::table('billing_addons')->updateOrInsert(
                ['code' => $addon['code']],
                $addon + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_addons')) {
            DB::table('billing_addons')->whereIn('code', array_column(self::ADDONS, 'code'))->delete();
        }
    }
};
