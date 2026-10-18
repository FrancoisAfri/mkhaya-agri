<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_purchase_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('request_number', 20)->unique();
            $table->unsignedInteger('requested_by');
            $table->string('item_description');
            $table->unsignedInteger('asset_category_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('estimated_unit_cost', 15, 2);
            $table->decimal('estimated_total_cost', 15, 2);
            $table->text('justification');
            $table->string('priority', 20)->default('medium')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('approval_id')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_reference')->nullable();
            $table->decimal('actual_unit_cost', 15, 2)->nullable();
            $table->decimal('actual_total_cost', 15, 2)->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->date('delivered_at')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('asset_category_id')->references('id')->on('asset_categories')->nullOnDelete();
            $table->index(['requested_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_purchase_requests');
    }
};
