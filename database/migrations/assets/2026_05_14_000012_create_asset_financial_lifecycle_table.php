<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_financial_lifecycle', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            
            $table->decimal('acquisition_cost', 12, 2);
            $table->decimal('total_depreciation', 12, 2)->default(0);
            $table->decimal('total_maintenance_cost', 12, 2)->default(0);
            $table->decimal('total_repair_cost', 12, 2)->default(0);
            $table->decimal('total_upgrade_cost', 12, 2)->default(0);
            
            $table->decimal('disposal_proceeds', 12, 2)->nullable();
            $table->date('disposal_date')->nullable();
            $table->decimal('gain_loss_on_disposal', 12, 2)->nullable();
            
            $table->decimal('total_cost_of_ownership', 12, 2)->default(0);
            
            $table->timestamps();
            
            $table->unique('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_financial_lifecycle');
    }
};
