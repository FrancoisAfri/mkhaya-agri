<?php

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Finance\Accounting\Support\StockContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The packhouse runs on the warehouse and the factory.
 *
 * A produce lot in a store is a stock lot (fin_stock_lots): the warehouse holds it by bin and status,
 * picks it, ships it and recalls it. The farm's produce lot stays as the lot's provenance — the field,
 * crop plan and harvest it came from — and names its stock lot. Grading and packing are Manufacturing
 * production runs, so an operation names its production order. Dispatches are warehouse fulfilment
 * orders and shipments, and recalls are Manufacturing recalls: produce_dispatches, produce_dispatch_items
 * and produce_recalls are no longer written.
 *
 * Every produce lot already in a store gets its stock lot, and the stock it holds is moved into that
 * lot from the unlotted position it was received into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produce_lots', fn (Blueprint $t) => $t->unsignedBigInteger('stock_lot_id')->nullable()->index());
        Schema::table('packhouse_operations', fn (Blueprint $t) => $t->unsignedBigInteger('production_order_id')->nullable()->index());

        // Farm stores are counted, not scanned, until the farm switches scanning on.
        foreach (DB::table('farm_stores')->whereNotNull('warehouse_id')->pluck('warehouse_id')->unique() as $wid) {
            $policies = json_decode((string) DB::table('fin_warehouses')->where('id', $wid)->value('policies'), true) ?: [];
            if (! array_key_exists('scan_to_confirm', $policies)) {
                DB::table('fin_warehouses')->where('id', $wid)->update(['policies' => json_encode($policies + ['scan_to_confirm' => false])]);
            }
        }

        foreach (DB::table('produce_lots')->whereNotNull('stock_item_id')->whereNotNull('warehouse_id')->whereNull('stock_lot_id')->orderBy('id')->get() as $l) {
            DB::select("SELECT set_config('app.company_id', ?, false)", [(string) $l->company_id]);
            try {
                DB::transaction(function () use ($l) {
                    $item = FinStockItem::withoutGlobalScopes()->find($l->stock_item_id);
                    $wh = FinWarehouse::withoutGlobalScopes()->find($l->warehouse_id);
                    if (! $item || ! $wh) {
                        return;
                    }
                    $lot = app(StockLedger::class)->lot($item, $l->code, ['kind' => 'LOT', 'origin' => $l->kind === 'harvest' ? 'RECEIPT' : 'PRODUCTION',
                        'manufactured_on' => $l->produced_on, 'expires_on' => $l->best_before, 'source_type' => 'agri_produce_lot', 'source_id' => $l->id]);
                    DB::table('produce_lots')->where('id', $l->id)->update(['stock_lot_id' => $lot->id]);
                    $status = $l->status === 'available' ? 'AVAILABLE' : 'QUARANTINE';
                    $loose = (float) DB::table('fin_stock_positions')->where('item_id', $item->id)->where('warehouse_id', $wh->id)
                        ->whereNull('lot_id')->where('status', $status)->sum('qty');
                    $qty = min((float) $l->remaining, $loose);
                    if ($qty > 0.0005 && in_array($l->status, ['available', 'quarantined', 'recalled'], true)) {
                        app(InventoryService::class)->relot($item, $wh, $qty, new StockContext(status: $status, sourceType: 'agri_produce_lot', sourceId: (int) $l->id),
                            $lot->id, "Produce lot {$l->code} becomes a stock lot");
                    }
                });
            } finally {
                DB::select("SELECT set_config('app.company_id', '', false)");
            }
        }
    }

    public function down(): void
    {
        Schema::table('packhouse_operations', fn (Blueprint $t) => $t->dropColumn('production_order_id'));
        Schema::table('produce_lots', fn (Blueprint $t) => $t->dropColumn('stock_lot_id'));
    }
};
