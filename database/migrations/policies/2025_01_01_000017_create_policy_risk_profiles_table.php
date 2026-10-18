<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('subject');               // user or department (polymorphic)
            $table->unsignedSmallInteger('risk_score')->default(0); // 0-100
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->unsignedInteger('total_violations')->default(0);
            $table->unsignedInteger('total_near_misses')->default(0);
            $table->unsignedInteger('total_overrides')->default(0);
            $table->json('top_risk_policies')->nullable();   // policy IDs most violated
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();

            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_risk_profiles');
    }
};
