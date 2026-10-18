<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_procurements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            $table->string('procurement_number')->unique();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->foreignId('asset_category_id')->constrained('asset_categories');
            
            $table->text('description');
            $table->text('justification')->nullable();
            
            $table->integer('quantity')->default(1);
            $table->decimal('estimated_unit_cost', 12, 2);
            $table->decimal('estimated_total_cost', 12, 2);
            $table->string('budget_code')->nullable();
            
            $table->foreignId('supplier_id')->nullable()->constrained('asset_suppliers');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            
            $table->enum('status', [
                'requested',
                'approved',
                'ordered',
                'received',
                'capitalised',
                'cancelled'
            ])->default('requested');
            
            $table->date('requested_date');
            $table->date('approved_date')->nullable();
            $table->date('ordered_date')->nullable();
            $table->date('received_date')->nullable();
            $table->date('capitalised_date')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'requested_date']);
            $table->index('procurement_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_procurements');
    }
};
