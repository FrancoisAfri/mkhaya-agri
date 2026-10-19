<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produce sold out of a farm store (the stock leaves at cost, the sale posts as income), and the
 * correction of a farm transaction entered in error — by reversing its journal, never by deleting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_item_id')->nullable()->after('unit');
            $table->unsignedBigInteger('stock_movement_id')->nullable()->after('stock_item_id');
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->string('reversal_reason', 300)->nullable();
            $table->unsignedBigInteger('reversal_journal_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('farm_transactions', function (Blueprint $table) {
            $table->dropColumn(['stock_item_id', 'stock_movement_id', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
        });
    }
};
