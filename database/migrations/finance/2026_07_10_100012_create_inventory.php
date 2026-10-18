<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory accounting (Phase 5.2). Costed stock items with FIFO / weighted-average
 * / standard costing. Receipts and issues post to the Inventory and COGS accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_stock_items', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('code', 60);
            $t->string('name');
            $t->string('cost_method', 15)->default('weighted_avg'); // fifo | weighted_avg | standard
            $t->string('uom', 20)->nullable();
            $t->decimal('standard_cost', 18, 4)->default(0);
            $t->decimal('qty_on_hand', 18, 4)->default(0);
            $t->decimal('avg_cost', 18, 4)->default(0);
            $t->unsignedBigInteger('inventory_account_id')->nullable();
            $t->unsignedBigInteger('cogs_account_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('fin_stock_layers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('item_id');
            $t->date('received_at');
            $t->decimal('qty_remaining', 18, 4);
            $t->decimal('unit_cost', 18, 4);
            $t->timestamps();
            $t->index(['item_id', 'id']);
        });

        Schema::create('fin_stock_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('item_id');
            $t->string('type', 12);                 // receipt | issue | adjustment
            $t->decimal('qty', 18, 4);              // signed (+in / −out)
            $t->decimal('unit_cost', 18, 4)->default(0);
            $t->decimal('value', 18, 2);
            $t->string('reference', 120)->nullable();
            $t->date('movement_date');
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_stock_movements');
        Schema::dropIfExists('fin_stock_layers');
        Schema::dropIfExists('fin_stock_items');
    }
};
