<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_productivity_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('overall_score')->default(0);   // 0-100
            $table->unsignedTinyInteger('punctuality_score')->default(0);
            $table->unsignedTinyInteger('attendance_rate_score')->default(0);
            $table->unsignedTinyInteger('overtime_balance_score')->default(0);
            $table->unsignedTinyInteger('consistency_score')->default(0);
            $table->decimal('attendance_rate', 5, 2)->default(0);        // % of working days present
            $table->unsignedSmallInteger('days_present')->default(0);
            $table->unsignedSmallInteger('days_absent')->default(0);
            $table->unsignedSmallInteger('late_arrivals')->default(0);
            $table->unsignedSmallInteger('early_departures')->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->string('productivity_tier')->default('average'); // high / average / low / at_risk
            $table->text('ai_insights')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'period_start']);
        });
    }

    public function down(): void { Schema::dropIfExists('attendance_productivity_scores'); }
};
