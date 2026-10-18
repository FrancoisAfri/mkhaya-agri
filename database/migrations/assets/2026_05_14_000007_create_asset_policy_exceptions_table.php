<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->unsignedBigInteger('policy_rule_id')->nullable()->comment('FK to PolicyOS rules');
            
            $table->text('exception_reason');
            $table->text('business_justification');
            
            $table->timestamp('requested_at');
            $table->date('valid_from');
            $table->date('valid_until');
            
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            $table->timestamps();
            
            $table->index(['asset_id', 'status']);
            $table->index(['valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_policy_exceptions');
    }
};
