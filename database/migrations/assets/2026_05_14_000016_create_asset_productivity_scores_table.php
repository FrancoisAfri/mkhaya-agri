<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_productivity_scores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            
            $table->date('score_date');
            
            $table->integer('productivity_score')->comment('0-100');
            $table->integer('utilization_score')->comment('0-100');
            $table->integer('efficiency_score')->comment('0-100');
            $table->integer('reliability_score')->comment('0-100');
            
            $table->decimal('output_per_hour', 10, 2)->nullable();
            $table->decimal('cost_per_output_unit', 10, 2)->nullable();
            
            $table->enum('benchmark_comparison', ['above', 'at', 'below'])->nullable();
            
            $table->timestamps();
            
            $table->unique(['asset_id', 'score_date']);
            $table->index('productivity_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_productivity_scores');
    }
};
