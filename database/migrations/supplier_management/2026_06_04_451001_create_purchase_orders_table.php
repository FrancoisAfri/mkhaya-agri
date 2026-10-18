<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('po_number')->unique();
            $table->unsignedBigInteger('supplier_id')->index();
            // Contracts live in another module — nullable, no cross-module FK constraint.
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->string('title');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency')->default('ZAR');
            $table->string('status')->default('draft'); // draft|approved|received|closed|cancelled
            $table->date('order_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
