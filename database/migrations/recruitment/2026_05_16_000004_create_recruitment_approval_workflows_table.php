<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('entity_type', 50); // e.g. 'vacancy', 'offer', 'requisition'
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('recruitment_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_id')
                ->constrained('recruitment_approval_workflows')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('stage_number');
            $table->string('name', 100); // e.g. 'Department Manager', 'HR Manager'
            $table->string('approver_role', 100)->nullable(); // Spatie role name
            $table->unsignedBigInteger('approver_user_id')->nullable(); // specific user override
            $table->boolean('is_required')->default(true);
            $table->unsignedTinyInteger('min_approvers')->default(1);
            $table->timestamps();
        });

        Schema::create('recruitment_approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('recruitment_approval_workflows');
            $table->foreignId('stage_id')->constrained('recruitment_approval_stages');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('decided_by')->constrained('users');
            $table->string('decision', 20); // 'approved' | 'rejected'
            $table->text('notes')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_approval_decisions');
        Schema::dropIfExists('recruitment_approval_stages');
        Schema::dropIfExists('recruitment_approval_workflows');
    }
};
