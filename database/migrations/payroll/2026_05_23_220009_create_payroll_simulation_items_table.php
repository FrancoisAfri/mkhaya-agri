<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_simulation_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('simulation_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->decimal('current_value', 14, 2)->default(0);
            $table->decimal('simulated_value', 14, 2)->default(0);
            $table->decimal('difference', 14, 2)->default(0);
            $table->decimal('percentage_change', 8, 4)->default(0);
            $table->string('item_type', 100);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('simulation_id')
                  ->references('id')
                  ->on('payroll_simulations')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_simulation_items');
    }
};
