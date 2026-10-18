<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            
            $table->date('score_date');
            
            // Overall risk
            $table->integer('risk_score')->comment('0-100');
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical']);
            
            // Component scores (each 0-100)
            $table->integer('misuse_score')->default(0);
            $table->integer('compliance_score')->default(100);
            $table->integer('utilization_score')->default(100);
            $table->integer('maintenance_score')->default(100);
            $table->integer('incident_score')->default(0);
            
            // Findings
            $table->json('anomalies_detected')->nullable();
            $table->json('recommendations')->nullable();
            
            $table->timestamps();
            
            $table->unique(['asset_id', 'score_date']);
            $table->index('risk_level');
            $table->index('score_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_risk_scores');
    }
};
