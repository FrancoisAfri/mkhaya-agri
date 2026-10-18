<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_patterns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('policy_id')->nullable()->index();
            $table->unsignedBigInteger('rule_id')->nullable()->index();
            $table->enum('pattern_type', ['repeat_violation', 'near_miss', 'approval_override', 'exception_abuse']);
            $table->unsignedSmallInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'pattern_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_patterns');
    }
};
