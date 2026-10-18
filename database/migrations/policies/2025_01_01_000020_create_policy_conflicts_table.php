<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_conflicts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_a_id')->index();
            $table->unsignedBigInteger('policy_b_id')->index();
            $table->unsignedBigInteger('rule_a_id')->nullable();
            $table->unsignedBigInteger('rule_b_id')->nullable();
            $table->enum('conflict_type', ['duplicate_rule', 'contradicting_clause', 'overlapping_scope', 'ambiguous_threshold']);
            $table->text('description');
            $table->enum('status', ['open', 'resolved', 'acknowledged', 'false_positive'])->default('open');
            $table->unsignedBigInteger('detected_by')->nullable(); // system or user
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_conflicts');
    }
};
