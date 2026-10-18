<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracked contract obligations (deliverables, SLAs, commitments). Can be added
 * manually or auto-populated by the contract AI extraction. Each has an owner,
 * due date and fulfilment status, and feeds the expiry/reminder engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_obligations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('contract_id')->index();
            $table->text('description');
            $table->string('owner_name')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('open'); // open|in_progress|met|breached|waived
            $table->string('source')->default('manual'); // manual|ai
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_obligations');
    }
};
