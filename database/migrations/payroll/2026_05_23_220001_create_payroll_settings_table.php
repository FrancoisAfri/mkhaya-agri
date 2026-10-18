<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 10)->default('ZAR');
            $table->enum('pay_frequency', ['monthly', 'biweekly', 'weekly'])->default('monthly');
            $table->tinyInteger('pay_day')->default(25);
            $table->decimal('overtime_rate', 5, 2)->default(1.50);
            $table->boolean('paye_enabled')->default(true);
            $table->boolean('uif_enabled')->default(true);
            $table->boolean('sdl_enabled')->default(true);
            $table->decimal('uif_employee_rate', 5, 4)->default(0.0100);
            $table->decimal('uif_employer_rate', 5, 4)->default(0.0100);
            $table->decimal('sdl_rate', 5, 4)->default(0.0100);
            $table->decimal('payroll_budget', 16, 2)->nullable();
            $table->json('settings_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};
