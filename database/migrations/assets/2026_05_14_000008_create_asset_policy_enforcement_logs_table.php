<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_policy_enforcement_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            
            $table->enum('action_type', ['assignment', 'transfer', 'disposal', 'maintenance']);
            $table->unsignedBigInteger('policy_rule_id')->nullable()->comment('FK to PolicyOS rules');
            
            $table->enum('check_result', ['passed', 'failed', 'exception_granted']);
            $table->timestamp('checked_at');
            
            $table->json('policy_snapshot')->nullable()->comment('Rule state at check time');
            $table->json('context')->nullable()->comment('Request context');
            
            $table->timestamps();
            
            // Immutable — no updates/deletes allowed
            $table->index(['asset_id', 'checked_at']);
            $table->index(['user_id', 'action_type']);
            $table->index('check_result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_policy_enforcement_logs');
    }
};
