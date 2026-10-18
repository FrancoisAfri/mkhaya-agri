<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice AI extraction + 3-way matching + reconciliation. Extends the existing
 * supplier_invoices with the document/AI/matching/budget fields, and adds invoice
 * line items and match-result rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_id')->nullable()->after('purchase_order_id');
            $table->unsignedBigInteger('goods_received_note_id')->nullable()->after('budget_id');
            $table->decimal('subtotal', 15, 2)->default(0)->after('amount');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('subtotal');

            $table->string('source')->default('manual')->after('status'); // manual|ai|marketplace
            $table->string('match_status')->default('unmatched')->after('source'); // unmatched|matched|exception
            $table->timestamp('matched_at')->nullable()->after('match_status');
            $table->string('payment_status')->default('unpaid')->after('matched_at'); // unpaid|approved|paid
            $table->decimal('variance_amount', 15, 2)->default(0)->after('payment_status');
            $table->timestamp('reconciled_at')->nullable()->after('variance_amount');
            $table->unsignedBigInteger('reconciled_by')->nullable()->after('reconciled_at');
            $table->unsignedBigInteger('budget_consumption_id')->nullable()->after('reconciled_by');

            // AI extraction
            $table->string('ai_status')->nullable()->after('budget_consumption_id'); // processing|completed|failed
            $table->string('ai_provider')->nullable()->after('ai_status');
            $table->string('ai_model')->nullable()->after('ai_provider');
            $table->longText('ai_extracted')->nullable()->after('ai_model');
            $table->text('notes')->nullable()->after('ai_extracted');

            // Stored document (FileStorage trait)
            $table->longText('file_data')->nullable()->after('notes');
            $table->string('file_name')->nullable()->after('file_data');
            $table->string('file_mime')->nullable()->after('file_name');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_mime');
        });

        Schema::create('supplier_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('supplier_invoice_id')->index();
            $table->unsignedBigInteger('purchase_order_item_id')->nullable();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('tax', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_match_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_invoice_id')->index();
            $table->string('type')->default('3way'); // 2way|3way
            $table->string('status')->default('matched'); // matched|exception
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('goods_received_note_id')->nullable();
            $table->decimal('po_amount', 18, 2)->default(0);
            $table->decimal('grn_value', 18, 2)->default(0);
            $table->decimal('invoice_amount', 18, 2)->default(0);
            $table->boolean('qty_ok')->default(true);
            $table->boolean('price_ok')->default(true);
            $table->boolean('amount_ok')->default(true);
            $table->boolean('within_tolerance')->default(true);
            $table->decimal('tolerance_pct', 6, 2)->default(0);
            $table->decimal('variance_amount', 18, 2)->default(0);
            $table->text('discrepancies')->nullable(); // json
            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_match_results');
        Schema::dropIfExists('supplier_invoice_items');

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'budget_id', 'goods_received_note_id', 'subtotal', 'tax_amount',
                'source', 'match_status', 'matched_at', 'payment_status', 'variance_amount',
                'reconciled_at', 'reconciled_by', 'budget_consumption_id',
                'ai_status', 'ai_provider', 'ai_model', 'ai_extracted', 'notes',
                'file_data', 'file_name', 'file_mime', 'file_size',
            ]);
        });
    }
};
