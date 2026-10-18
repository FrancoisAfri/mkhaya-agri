<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approval_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();

            // level: 1=manager, 2=department_head, 3=hr_manager, 4=payroll_officer
            $table->unsignedTinyInteger('level');
            $table->string('level_name', 100);

            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegated_from_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Original approver when delegation is active');

            $table->enum('status', [
                'pending', 'approved', 'rejected', 'skipped', 'escalated', 'delegated',
            ])->default('pending');

            $table->timestamp('actioned_at')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_required')->default(true);

            $table->timestamp('escalated_at')->nullable();
            $table->string('escalation_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['leave_request_id', 'level']);
            $table->index(['approver_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approval_levels');
    }
};
