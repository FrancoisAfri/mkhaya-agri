<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete()
                  ->comment('The person being given feedback about');
            $table->foreignId('given_by')->constrained('users')->cascadeOnDelete()
                  ->comment('The person submitting the feedback');
            $table->enum('feedback_type', ['manager', 'peer', 'upward', 'self'])
                  ->comment('manager=manager→report, peer=colleague→colleague, upward=report→manager, self=self-reflection');
            $table->text('content');
            $table->decimal('rating', 3, 1)->nullable()->comment('Optional numeric score 0-5');
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pa_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->string('title', 200)->default('1-on-1 Check-In');
            $table->timestamp('scheduled_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('location', 200)->nullable()->comment('Room, Zoom link, etc.');
            $table->text('agenda')->nullable();
            $table->text('notes')->nullable()->comment('Meeting notes / outcomes');
            $table->json('action_items')->nullable()->comment('Array of {task, owner, due_date}');
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('pa_coaching_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->string('title', 200);
            $table->date('session_date');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->text('objectives')->nullable();
            $table->text('outcomes')->nullable();
            $table->text('next_steps')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_coaching_sessions');
        Schema::dropIfExists('pa_checkins');
        Schema::dropIfExists('pa_feedback');
    }
};
