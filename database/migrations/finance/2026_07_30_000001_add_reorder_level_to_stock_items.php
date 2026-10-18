<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reorder point for inventory items — when qty_on_hand falls to/below this, the item is flagged
 * low-stock on the inventory page. Nullable/zero = no reorder tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_stock_items', function (Blueprint $t) {
            $t->decimal('reorder_level', 18, 4)->default(0)->after('qty_on_hand');
        });
    }

    public function down(): void
    {
        Schema::table('fin_stock_items', function (Blueprint $t) {
            $t->dropColumn('reorder_level');
        });
    }
};
