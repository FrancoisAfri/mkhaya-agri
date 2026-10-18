<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('department_efficiency_scores', function (Blueprint $table) {
            $table->id();
            $table->string('department');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('efficiency_score')->default(0);  // 0-100
            $table->unsignedSmallInteger('headcount')->default(0);
            $table->decimal('avg_attendance_rate', 5, 2)->default(0);
            $table->decimal('avg_punctuality_rate', 5, 2)->default(0);
            $table->decimal('total_overtime_hours', 8, 2)->default(0);
            $table->unsignedSmallInteger('overworked_count')->default(0);   // employees > threshold
            $table->unsignedSmallInteger('low_productivity_count')->default(0);
            $table->string('efficiency_tier')->default('average');           // high / average / low / critical
            $table->boolean('is_overworked_dept')->default(false);
            $table->text('ai_summary')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->index(['department', 'period_start']);
        });
    }

    public function down(): void { Schema::dropIfExists('department_efficiency_scores'); }
};
