<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pa_performance_snapshots', function (Blueprint $table) {
            $table->decimal('employee_score', 5, 2)->nullable()->after('kpi_completion_rate')
                  ->comment('Employee self-assessment overall score %');
            $table->decimal('manager_score', 5, 2)->nullable()->after('employee_score')
                  ->comment('Manager-rated overall score %');
            $table->decimal('score_360', 5, 2)->nullable()->after('manager_score')
                  ->comment('Average 360 review score (normalised to %)');
            $table->decimal('final_score', 5, 2)->nullable()->after('score_360')
                  ->comment('Agreed final score after manager approval');
            $table->string('appraisal_status', 30)->default('draft')->after('final_score')
                  ->comment('draft | employee_submitted | manager_review | approved | rejected');
            $table->text('employee_comments')->nullable()->after('appraisal_status');
            $table->text('manager_comments')->nullable()->after('employee_comments');
            $table->timestamp('submitted_at')->nullable()->after('manager_comments');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('pa_performance_snapshots', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'employee_score', 'manager_score', 'score_360', 'final_score',
                'appraisal_status', 'employee_comments', 'manager_comments',
                'submitted_at', 'approved_by', 'approved_at',
            ]);
        });
    }
};
