<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('probation_period_id')->constrained('probation_periods')->cascadeOnDelete();
            $table->date('review_date');
            $table->string('review_type', 30)->default('mid'); // mid|final|extension
            $table->unsignedTinyInteger('performance_score')->nullable(); // 1-5
            $table->text('manager_feedback')->nullable();
            $table->text('employee_feedback')->nullable();
            $table->text('areas_of_improvement')->nullable();
            $table->string('recommendation', 30)->nullable(); // confirm|extend|dismiss
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['probation_period_id', 'review_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probation_reviews');
    }
};
