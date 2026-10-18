<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_total_cost_ownership', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            
            $table->date('calculation_date');
            
            $table->decimal('acquisition_cost', 12, 2);
            $table->decimal('maintenance_cost', 12, 2)->default(0);
            $table->decimal('operating_cost', 12, 2)->default(0);
            $table->decimal('downtime_cost', 12, 2)->default(0);
            $table->decimal('disposal_value', 12, 2)->default(0);
            
            $table->decimal('total_tco', 12, 2);
            $table->decimal('tco_per_year', 12, 2);
            $table->decimal('tco_per_month', 12, 2);
            
            $table->timestamps();
            
            $table->index(['asset_id', 'calculation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_total_cost_ownership');
    }
};
