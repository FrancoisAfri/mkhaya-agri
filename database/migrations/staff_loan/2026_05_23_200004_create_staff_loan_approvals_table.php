<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loan_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_id')->index();
            $table->unsignedBigInteger('approver_id')->index();
            $table->enum('approval_level', ['line_manager', 'hr', 'finance', 'executive']);
            $table->enum('action', ['approved', 'rejected', 'info_requested', 'escalated']);
            $table->text('comments')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->foreign('loan_id')->references('id')->on('staff_loans')->onDelete('cascade');
            $table->foreign('approver_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loan_approvals');
    }
};
