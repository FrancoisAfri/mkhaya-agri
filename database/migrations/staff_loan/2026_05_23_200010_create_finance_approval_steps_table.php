<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_request_id')->index();
            $table->unsignedBigInteger('approver_id')->index();
            $table->string('level');
            $table->enum('action', ['approved', 'rejected', 'info_requested', 'escalated']);
            $table->text('comments')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->foreign('approval_request_id')->references('id')->on('finance_approval_requests')->onDelete('cascade');
            $table->foreign('approver_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_approval_steps');
    }
};
