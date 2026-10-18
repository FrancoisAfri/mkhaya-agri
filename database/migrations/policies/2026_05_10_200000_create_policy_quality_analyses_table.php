<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_quality_analyses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('analysed_by')->nullable()->index();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->json('suggestions')->nullable();   // [{type, severity, clause_reference, description, suggested_text}]
            $table->text('summary')->nullable();       // AI narrative summary
            $table->unsignedInteger('issue_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_quality_analyses');
    }
};
