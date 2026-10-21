<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Farm selling moves onto Accounting's documents.
 *
 * Buyers are Accounting customers, price lists Accounting price lists, quotes and orders Accounting
 * quotes and sales orders, and disputes Accounting disputes; the farm keeps its supply contracts,
 * market prices and the marketplace, which point at the customer. The earlier migrations no longer
 * create the farm copies, so on a fresh install this does nothing. On a database that ran them it
 * re-points the farm's references at Accounting; the retired farm tables (agri_buyers,
 * agri_price_lists, agri_sales_quotes, agri_sales_disputes, produce_orders, produce_order_lines) are
 * left in place, unused, for whoever runs the database to drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('produce_dispatches')) {
            // A dispatch now fills an Accounting sales order, not a farm order.
            DB::statement('ALTER TABLE produce_dispatches DROP CONSTRAINT IF EXISTS produce_dispatches_order_id_foreign');
            if (! Schema::hasColumn('produce_dispatches', 'delivery_note_id')) {
                Schema::table('produce_dispatches', function (Blueprint $t) {
                    $t->unsignedBigInteger('delivery_note_id')->nullable();
                    $t->index('order_id');
                });
            }
        }

        foreach ([['agri_sales_contracts', 'buyer_id'], ['agri_market_requests', 'buyer_id'], ['harvest_commitments', 'customer_id']] as [$table, $old]) {
            if (Schema::hasColumn($table, $old)) {
                Schema::table($table, fn (Blueprint $t) => $t->renameColumn($old, 'party_id'));
            }
        }
    }

    public function down(): void
    {
        foreach ([['agri_sales_contracts', 'buyer_id'], ['agri_market_requests', 'buyer_id'], ['harvest_commitments', 'customer_id']] as [$table, $old]) {
            if (Schema::hasColumn($table, 'party_id') && ! Schema::hasColumn($table, $old)) {
                Schema::table($table, fn (Blueprint $t) => $t->renameColumn('party_id', $old));
            }
        }
    }
};
