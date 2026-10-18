<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_appeals', function (Blueprint $table) {
            $table->id();
            $table->string('appeal_number')->unique();
            $table->foreignId('case_id')->constrained('disciplinary_cases');
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('hearing_id')->nullable()->constrained('disciplinary_hearings')->nullOnDelete();
            $table->text('grounds');
            $table->text('supporting_argument')->nullable();
            $table->date('deadline_date')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('chairperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hr_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('appeal_hearing_date')->nullable();
            $table->enum('status', [
                'submitted', 'under_review', 'hearing_scheduled',
                'decided', 'withdrawn', 'lapsed',
            ])->default('submitted');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['case_id', 'status']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('disciplinary_appeal_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appeal_id')->constrained('disciplinary_appeals')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('uuid');
            $table->string('file_name');
            $table->string('original_name');
            $table->string('mime');
            $table->longText('file_data');
            $table->string('file_type');
            $table->integer('file_size')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('disciplinary_appeal_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appeal_id')->unique()->constrained('disciplinary_appeals')->cascadeOnDelete();
            $table->foreignId('decided_by')->constrained('users');
            $table->timestamp('decided_at')->useCurrent();
            $table->enum('verdict', ['upheld', 'dismissed', 'partially_upheld']);
            $table->text('reasoning');
            $table->foreignId('new_sanction_id')->nullable()->constrained('disciplinary_sanctions')->nullOnDelete();
            $table->text('outcome_notes')->nullable();
            $table->boolean('sanction_changed')->default(false);
            $table->boolean('case_overturned')->default(false);
            $table->boolean('notify_employee')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_appeal_outcomes');
        Schema::dropIfExists('disciplinary_appeal_evidence');
        Schema::dropIfExists('disciplinary_appeals');
    }
};
