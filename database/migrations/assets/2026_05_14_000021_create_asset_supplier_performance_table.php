<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_supplier_performance', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained('asset_suppliers')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('asset_purchase_orders');
            
            $table->date('evaluation_date');
            
            $table->integer('delivery_timeliness_score')->comment('0-100');
            $table->integer('quality_score')->comment('0-100');
            $table->integer('pricing_competitiveness')->comment('0-100');
            $table->integer('communication_score')->comment('0-100');
            $table->integer('overall_score')->comment('0-100');
            
            $table->text('comments')->nullable();
            
            $table->timestamps();
            
            $table->index(['supplier_id', 'evaluation_date']);
            $table->index('overall_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_supplier_performance');
    }
};
