<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            $table->string('po_number')->unique();
            $table->foreignId('procurement_id')->constrained('asset_procurements');
            $table->foreignId('supplier_id')->constrained('asset_suppliers');
            $table->foreignId('issued_by_user_id')->constrained('users');
            
            $table->date('po_date');
            $table->date('expected_delivery_date');
            $table->decimal('total_amount', 12, 2);
            
            $table->enum('status', [
                'issued',
                'acknowledged',
                'delivered',
                'invoiced',
                'closed'
            ])->default('issued');
            
            $table->text('terms_and_conditions')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'po_date']);
            $table->index('po_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_purchase_orders');
    }
};
