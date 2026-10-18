<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_violation_investigations')) {
            Schema::create('policy_violation_investigations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('violation_id')
                    ->constrained('policy_violations')
                    ->onDelete('cascade');
                $table->foreignId('policy_id')
                    ->constrained('policy')
                    ->onDelete('cascade');
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->onDelete('cascade');

                $table->enum('status', [
                    'open',
                    'in_progress',
                    'reviewing',
                    'resolved',
                    'closed',
                ])->default('open');

                $table->foreignId('assigned_to')->nullable()
                    ->constrained('users')
                    ->onDelete('set null');
                $table->timestamp('assigned_at')->nullable();

                $table->text('initial_notes')->nullable();
                $table->text('findings')->nullable();
                $table->enum('severity_finding', [
                    'resolved_satisfactorily',
                    'requires_training',
                    'requires_discipline',
                    'repeat_offender',
                    'systemic_issue',
                ])->nullable();

                $table->text('resolution_action')->nullable();
                $table->enum('resolution_type', [
                    'no_action',
                    'coaching',
                    'training',
                    'warning',
                    'suspension',
                    'termination',
                    'policy_change',
                ])->nullable();

                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by')->nullable()
                    ->constrained('users')
                    ->onDelete('set null');

                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();

                $table->index('violation_id');
                $table->index('user_id');
                $table->index('status');
                $table->index('assigned_to');
            });
        }

        if (! Schema::hasTable('policy_investigation_notes')) {
            Schema::create('policy_investigation_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('investigation_id')
                    ->constrained('policy_violation_investigations')
                    ->onDelete('cascade');
                $table->foreignId('created_by')
                    ->constrained('users')
                    ->onDelete('cascade');

                $table->text('note');
                $table->json('attachments')->nullable();
                $table->enum('visibility', [
                    'internal',
                    'user',
                    'manager',
                ])->default('internal');

                $table->timestamps();

                $table->index('investigation_id');
                $table->index('created_by');
            });
        }

        if (! Schema::hasTable('policy_investigation_evidence')) {
            Schema::create('policy_investigation_evidence', function (Blueprint $table) {
                $table->id();
                $table->foreignId('investigation_id')
                    ->constrained('policy_violation_investigations')
                    ->onDelete('cascade');

                $table->string('file_path');
                $table->string('file_name');
                $table->string('file_type');
                $table->unsignedInteger('file_size');
                $table->text('description')->nullable();

                $table->foreignId('uploaded_by')
                    ->constrained('users')
                    ->onDelete('cascade');

                $table->timestamps();

                $table->index('investigation_id');
                $table->index('uploaded_by');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_investigation_evidence');
        Schema::dropIfExists('policy_investigation_notes');
        Schema::dropIfExists('policy_violation_investigations');
    }
};
