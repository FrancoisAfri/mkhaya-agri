<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_reviewers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->enum('role', ['owner', 'reviewer', 'approver', 'observer'])->default('reviewer');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'user_id', 'role']);
            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_reviewers');
    }
};
