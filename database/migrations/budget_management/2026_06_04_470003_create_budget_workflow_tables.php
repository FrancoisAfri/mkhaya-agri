<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budget supporting/workflow tables: allocations, owners, approvals,
 * adjustments (increase/decrease/transfer) and overspend overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('budget_id')->index();
            $table->string('label');
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('allocated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_owners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('role')->default('owner'); // owner | delegate
            $table->timestamps();
            $table->unique(['budget_id', 'user_id']);
        });

        Schema::create('budget_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('budget_id')->index();
            $table->unsignedInteger('step')->default(1);
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('budget_id')->index();
            $table->string('type'); // increase | decrease | transfer
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('transfer_to_budget_id')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('budget_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('budget_id')->index();
            $table->decimal('amount', 18, 2);
            $table->text('reason');
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_overrides');
        Schema::dropIfExists('budget_adjustments');
        Schema::dropIfExists('budget_approvals');
        Schema::dropIfExists('budget_owners');
        Schema::dropIfExists('budget_allocations');
    }
};
