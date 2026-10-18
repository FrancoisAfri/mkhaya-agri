<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_id');
            $table->unsignedBigInteger('approver_id');
            $table->string('level', 50);
            $table->enum('action', ['approved', 'rejected', 'info_requested', 'escalated']);
            $table->text('comments')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->foreign('approval_id')
                  ->references('id')
                  ->on('payroll_approvals')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_approval_steps');
    }
};
