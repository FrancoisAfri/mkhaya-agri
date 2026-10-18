<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('module', ['claims', 'petty_cash', 'loans', 'advances']);
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->enum('current_level', ['line_manager', 'hr', 'finance', 'executive'])->default('line_manager');
            $table->enum('status', ['pending', 'approved', 'rejected', 'escalated', 'info_requested'])->default('pending');
            $table->unsignedBigInteger('requested_by')->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->enum('priority', ['normal', 'high', 'urgent'])->default('normal');
            $table->date('due_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('requested_by')->references('id')->on('users')->onDelete('restrict');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_approval_requests');
    }
};
