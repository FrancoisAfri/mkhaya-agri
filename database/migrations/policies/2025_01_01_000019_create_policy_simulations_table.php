<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_simulations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('run_by')->index();
            $table->json('simulation_config');               // date range, departments, modules to simulate
            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');
            $table->json('results')->nullable();             // projected violations, impacted depts
            $table->unsignedInteger('projected_violations')->nullable();
            $table->json('impacted_departments')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_simulations');
    }
};
