<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_simulations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('base_run_id')->nullable();
            $table->enum('simulation_type', [
                'salary_increase',
                'bonus',
                'new_hire',
                'overtime',
                'allowance',
                'union_increase',
                'annual_budget',
            ]);
            $table->json('parameters')->nullable();
            $table->decimal('total_current_cost', 16, 2)->default(0);
            $table->decimal('total_simulated_cost', 16, 2)->default(0);
            $table->decimal('total_difference', 16, 2)->default(0);
            $table->decimal('percentage_change', 8, 4)->default(0);
            $table->enum('status', ['draft', 'saved', 'archived'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_simulations');
    }
};
