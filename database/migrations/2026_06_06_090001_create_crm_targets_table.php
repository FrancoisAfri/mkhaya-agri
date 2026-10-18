<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user sales targets / quotas. A user has one target per metric per period
 * type (weekly or monthly) — e.g. 40 calls/week, 8 deals won/month, R500k
 * revenue/month — which the performance dashboards measure actuals against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('period', 10);   // weekly | monthly
            $table->string('metric', 20);   // calls|emails|meetings|activities|leads|deals_won|revenue
            $table->decimal('target_value', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'period', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_targets');
    }
};
