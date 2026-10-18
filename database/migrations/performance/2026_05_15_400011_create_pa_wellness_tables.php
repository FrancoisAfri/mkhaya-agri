<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Point-in-time wellness score per employee per cycle
        Schema::create('pa_wellness_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->unsignedTinyInteger('wellness_score');  // 0–100
            $table->string('wellness_grade');               // A B C D F
            $table->unsignedTinyInteger('kpi_score')->nullable();
            $table->unsignedTinyInteger('feedback_score')->nullable();
            $table->unsignedTinyInteger('checkin_score')->nullable();
            $table->unsignedTinyInteger('engagement_score')->nullable();
            $table->string('burnout_risk');                 // low | medium | high | critical
            $table->string('disengagement_level');          // low | medium | high
            $table->json('signals')->nullable();            // detected signal keys
            $table->boolean('is_ai_generated')->default(false);
            $table->foreignId('generated_by')->constrained('users');
            $table->timestamps();
        });

        // Individual detected signals / alerts per employee
        Schema::create('pa_behavior_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->string('signal_type'); // burnout | disengagement | productivity_decline | workload_imbalance | attendance_correlation
            $table->string('severity');    // info | warning | critical
            $table->string('title');
            $table->text('description');
            $table->json('data')->nullable();           // supporting metrics
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_behavior_signals');
        Schema::dropIfExists('pa_wellness_scores');
    }
};
