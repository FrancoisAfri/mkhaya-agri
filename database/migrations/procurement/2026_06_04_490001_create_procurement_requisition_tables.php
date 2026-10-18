<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement requisitions: an approved purchase request handed to procurement
 * for sourcing (RFQ / direct / marketplace / contract) and supplier selection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_requisitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('requisition_number')->unique();
            $table->unsignedBigInteger('purchase_request_id')->nullable()->index();
            $table->string('title');
            $table->string('procurement_method')->default('rfq'); // rfq | direct | marketplace | contract
            $table->unsignedBigInteger('officer_id')->nullable();
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('rfx_event_id')->nullable();
            $table->unsignedBigInteger('budget_reservation_id')->nullable();
            $table->boolean('reservation_confirmed')->default(false);
            $table->string('status')->default('open'); // open|sourcing|quoting|awarded|po_created|closed|cancelled
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('procurement_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('procurement_requisition_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('budget_category_id')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('procurement_requisition_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('procurement_requisition_id')->index();
            $table->longText('file_data')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_requisition_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('procurement_requisition_id')->index();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_requisition_history');
        Schema::dropIfExists('procurement_requisition_attachments');
        Schema::dropIfExists('procurement_requisition_items');
        Schema::dropIfExists('procurement_requisitions');
    }
};
