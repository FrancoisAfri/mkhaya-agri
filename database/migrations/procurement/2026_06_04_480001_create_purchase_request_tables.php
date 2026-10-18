<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee purchase requests: the entry point of the procurement flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('request_number')->unique();
            $table->unsignedBigInteger('requester_id')->nullable()->index();
            $table->string('department')->nullable();
            $table->string('project_code')->nullable();
            $table->unsignedBigInteger('budget_id')->nullable()->index();
            $table->string('urgency')->default('normal'); // low|normal|high|emergency
            $table->boolean('is_emergency')->default(false);
            $table->text('justification')->nullable();
            $table->string('delivery_location')->nullable();
            $table->date('needed_by')->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->string('status')->default('draft'); // draft|submitted|validation_failed|pending_approval|approved|rejected|cancelled|converted
            $table->unsignedBigInteger('budget_reservation_id')->nullable();
            $table->decimal('reserved_amount', 18, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('purchase_request_id')->index();
            $table->string('item_type')->default('custom'); // catalog|custom|service
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('budget_category_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->string('source')->default('manual'); // manual|marketplace
            $table->string('external_ref')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('purchase_request_id')->index();
            $table->longText('file_data')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_request_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();
        });

        Schema::create('purchase_request_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_request_id')->index();
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
        Schema::dropIfExists('purchase_request_history');
        Schema::dropIfExists('purchase_request_statuses');
        Schema::dropIfExists('purchase_request_attachments');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};
