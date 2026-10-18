<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing purchase_orders with P2P fields and adds the PO line items,
 * delivery notes, GRN (goods received), amendments, and asset/inventory links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('procurement_requisition_id')->nullable()->after('contract_id');
            $table->unsignedBigInteger('budget_id')->nullable()->after('procurement_requisition_id');
            $table->unsignedBigInteger('budget_commitment_id')->nullable()->after('budget_id');
            $table->string('source')->default('manual')->after('status'); // manual|requisition|marketplace
            $table->date('expected_delivery')->nullable()->after('order_date');
            $table->timestamp('delivered_at')->nullable()->after('expected_delivery');
            $table->string('received_status')->default('pending')->after('delivered_at'); // pending|partial|received
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('budget_category_id')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('received_qty', 12, 2)->default(0);
            $table->boolean('is_asset')->default(false);
            $table->timestamps();
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->string('delivery_number');
            $table->string('supplier_ref')->nullable();
            $table->date('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->timestamps();
        });

        Schema::create('goods_received_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->string('grn_number')->unique();
            $table->string('status')->default('partial'); // partial|complete
            $table->decimal('received_value', 18, 2)->default(0);
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('goods_received_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('goods_received_note_id')->index();
            $table->unsignedBigInteger('purchase_order_item_id')->nullable();
            $table->decimal('received_qty', 12, 2)->default(0);
            $table->decimal('accepted_qty', 12, 2)->default(0);
            $table->decimal('rejected_qty', 12, 2)->default(0);
            $table->string('condition')->default('good'); // good|damaged
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_amendments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->string('type'); // value|quantity|cancel|other
            $table->decimal('old_amount', 18, 2)->nullable();
            $table->decimal('new_amount', 18, 2)->nullable();
            $table->text('detail')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('amended_by')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_asset_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->unsignedBigInteger('purchase_order_item_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('asset_ref')->nullable();
            $table->string('name')->nullable();
            $table->decimal('value', 18, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_inventory_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->unsignedBigInteger('purchase_order_item_id')->nullable();
            $table->string('inventory_ref')->nullable();
            $table->string('name')->nullable();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_inventory_links');
        Schema::dropIfExists('procurement_asset_links');
        Schema::dropIfExists('purchase_order_amendments');
        Schema::dropIfExists('goods_received_items');
        Schema::dropIfExists('goods_received_notes');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('purchase_order_items');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'procurement_requisition_id', 'budget_id', 'budget_commitment_id',
                'source', 'expected_delivery', 'delivered_at', 'received_status',
            ]);
        });
    }
};
