<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_book_values', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            
            $table->date('snapshot_date');
            
            $table->decimal('accounting_book_value', 12, 2);
            $table->decimal('tax_book_value', 12, 2);
            $table->decimal('accumulated_depreciation_accounting', 12, 2)->default(0);
            $table->decimal('accumulated_depreciation_tax', 12, 2)->default(0);
            $table->decimal('impairment_loss', 12, 2)->default(0);
            $table->decimal('revaluation_surplus', 12, 2)->default(0);
            
            $table->timestamps();
            
            $table->unique(['asset_id', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_book_values');
    }
};
