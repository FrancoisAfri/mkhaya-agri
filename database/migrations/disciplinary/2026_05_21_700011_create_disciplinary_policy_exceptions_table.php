<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_policy_exceptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('violation_id')->constrained('disciplinary_policy_violations')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->foreignId('hearing_id')->nullable()->constrained('disciplinary_hearings')->nullOnDelete();

            $table->text('reason');           // short reason for the exception request
            $table->text('justification');    // detailed business justification
            $table->text('risk_acknowledged')->nullable(); // what risk the requester acknowledges

            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->date('approved_until')->nullable(); // exception validity window
            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->index(['violation_id', 'status']);
            $table->index('requested_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_policy_exceptions');
    }
};
