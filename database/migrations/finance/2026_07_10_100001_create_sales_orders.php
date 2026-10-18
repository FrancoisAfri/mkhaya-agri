<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales orders + delivery notes (2.2) — the quote → SO → delivery → invoice
 * pipeline. Orders and delivery notes are non-posting; invoicing an order posts
 * to the GL through the shared engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_sales_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('party_id');
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('number', 60);
            $table->date('order_date');
            $table->char('currency', 3)->default('ZAR');
            $table->string('status', 14)->default('open'); // open | part_delivered | delivered | invoiced | cancelled
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('customer_ref', 120)->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'party_id']);
        });

        Schema::create('fin_sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('qty_delivered', 14, 4)->default(0);
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('fin_delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('party_id');
            $table->string('number', 60);
            $table->date('delivery_date');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('fin_delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_note_id');
            $table->unsignedBigInteger('order_line_id');
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(0);
            $table->timestamps();

            $table->index('delivery_note_id');
        });

        Schema::table('fin_quotes', fn (Blueprint $table) => $table->unsignedBigInteger('sales_order_id')->nullable()->after('invoice_id'));
    }

    public function down(): void
    {
        Schema::table('fin_quotes', fn (Blueprint $table) => $table->dropColumn('sales_order_id'));
        Schema::dropIfExists('fin_delivery_note_lines');
        Schema::dropIfExists('fin_delivery_notes');
        Schema::dropIfExists('fin_sales_order_lines');
        Schema::dropIfExists('fin_sales_orders');
    }
};
