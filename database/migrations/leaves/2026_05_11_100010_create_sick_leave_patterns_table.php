<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sick_leave_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();

            $table->enum('pattern_type', [
                'monday_absence',
                'friday_absence',
                'monday_friday_pattern',
                'pre_holiday',
                'post_holiday',
                'consecutive_3_plus',
                '8_week_rolling',
                'excessive_sick',
            ]);

            $table->date('detection_date');
            $table->date('pattern_start')->nullable();
            $table->date('pattern_end')->nullable();
            $table->unsignedSmallInteger('occurrence_count')->default(1);

            // 8-week rolling window tracking
            $table->date('rolling_window_start')->nullable();
            $table->date('rolling_window_end')->nullable();
            $table->decimal('total_sick_hours_in_window', 8, 2)->nullable();

            // Review workflow
            $table->boolean('flagged_for_review')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_outcome', 500)->nullable();

            // AI scoring
            $table->decimal('ai_confidence_score', 5, 2)->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'pattern_type']);
            $table->index(['employee_id', 'detection_date']);
            $table->index('flagged_for_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sick_leave_patterns');
    }
};
