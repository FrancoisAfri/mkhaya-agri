<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_warranty_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained('asset_documents')->cascadeOnDelete();
            
            $table->string('warranty_provider')->nullable();
            $table->enum('warranty_type', ['manufacturer', 'extended', 'insurance'])->nullable();
            
            $table->date('coverage_start_date')->nullable();
            $table->date('coverage_end_date')->nullable();
            
            $table->json('coverage_terms')->nullable();
            $table->text('claim_process')->nullable();
            $table->text('contact_info')->nullable();
            
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->unique('document_id');
            $table->index('coverage_end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_warranty_documents');
    }
};
