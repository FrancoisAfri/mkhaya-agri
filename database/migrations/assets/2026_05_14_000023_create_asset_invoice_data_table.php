<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_invoice_data', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained('asset_documents')->cascadeOnDelete();
            
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_tax_number')->nullable();
            
            $table->json('line_items')->nullable();
            
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('ZAR');
            
            $table->string('payment_terms')->nullable();
            
            $table->foreignId('matched_procurement_id')->nullable()->constrained('asset_procurements');
            $table->integer('match_confidence')->nullable()->comment('0-100');
            
            $table->timestamps();
            
            $table->unique('document_id');
            $table->index('invoice_number');
            $table->index('matched_procurement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_invoice_data');
    }
};
