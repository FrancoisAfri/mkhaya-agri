<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_optimization_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_schedule_id')->constrained('roster_schedules')->cascadeOnDelete();
            $table->string('optimization_type', 40)->comment('staffing_levels,shift_distribution,fatigue_reduction,full');
            $table->unsignedTinyInteger('coverage_score_before')->nullable();
            $table->unsignedTinyInteger('coverage_score_after')->nullable();
            $table->unsignedTinyInteger('balance_score_before')->nullable();
            $table->unsignedTinyInteger('balance_score_after')->nullable();
            $table->json('changes_made')->nullable();
            $table->json('recommendations')->nullable();
            $table->text('ai_report')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_optimization_logs');
    }
};
