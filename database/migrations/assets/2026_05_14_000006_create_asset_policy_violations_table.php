<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_policy_violations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('policy_rule_id')->nullable()->comment('FK to PolicyOS rules');
            
            $table->enum('violation_type', [
                'assignment_limit',
                'value_threshold',
                'unauthorized_transfer',
                'missing_approval',
                'retention_breach'
            ]);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            
            $table->timestamp('detected_at');
            $table->text('description');
            $table->json('policy_rule_details')->nullable()->comment('Rule snapshot');
            
            $table->boolean('action_blocked')->default(false);
            
            $table->enum('status', ['open', 'acknowledged', 'resolved', 'exception_granted'])->default('open');
            
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['asset_id', 'detected_at']);
            $table->index(['severity', 'status']);
            $table->index('violation_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_policy_violations');
    }
};
