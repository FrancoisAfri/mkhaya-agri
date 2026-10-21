<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §5.4: a farm's purchase arrives in the store it was asked for, as the stock item it was
 * asked for. The purchase request names its delivery store; the requisition line keeps the stock
 * item; the purchase order inherits both — so goods received post to the right farm store once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', fn (Blueprint $t) => $t->unsignedBigInteger('warehouse_id')->nullable());
        Schema::table('procurement_requisition_items', fn (Blueprint $t) => $t->unsignedBigInteger('stock_item_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('procurement_requisition_items', fn (Blueprint $t) => $t->dropColumn('stock_item_id'));
        Schema::table('purchase_requests', fn (Blueprint $t) => $t->dropColumn('warehouse_id'));
    }
};
