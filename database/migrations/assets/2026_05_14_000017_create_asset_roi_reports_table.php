<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_roi_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            
            $table->date('report_date');
            
            $table->decimal('total_investment', 12, 2);
            $table->decimal('total_revenue_generated', 12, 2)->default(0);
            $table->decimal('total_cost_savings', 12, 2)->default(0);
            $table->decimal('total_operating_cost', 12, 2)->default(0);
            
            $table->decimal('net_benefit', 12, 2);
            $table->decimal('roi_percentage', 8, 2);
            $table->integer('payback_period_months')->nullable();
            
            $table->timestamps();
            
            $table->index(['asset_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_roi_reports');
    }
};
