<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_shortlist_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shortlist_id')->constrained('recruitment_shortlists')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->restrictOnDelete();
            $table->enum('action', ['added', 'removed']);
            $table->text('override_reason');
            // Four-eyes enforcement: overridden_by !== approver_id checked in policy
            $table->foreignId('overridden_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['shortlist_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_shortlist_overrides');
    }
};
