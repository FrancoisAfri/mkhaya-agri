<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_pressure_predictions', function (Blueprint $table) {
            $table->id();
            $table->string('prediction_type', 40)->comment('absenteeism_spike,turnover_risk,understaffing,fatigue_outbreak');
            $table->string('scope', 20)->default('department')->comment('employee,department,organisation');
            $table->foreignId('employee_id')->nullable()->constrained('hr_people')->nullOnDelete();
            $table->string('department', 100)->nullable();
            $table->date('predicted_for');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('confidence')->default(0)->comment('0-100');
            $table->string('risk_level', 20)->default('low')->comment('low,medium,high,critical');
            $table->string('risk_color', 20)->default('success');
            $table->json('evidence')->nullable();
            $table->text('ai_explanation')->nullable();
            $table->string('status', 20)->default('active')->comment('active,confirmed,dismissed,expired');
            $table->timestamps();

            $table->index(['prediction_type', 'predicted_for']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_pressure_predictions');
    }
};
