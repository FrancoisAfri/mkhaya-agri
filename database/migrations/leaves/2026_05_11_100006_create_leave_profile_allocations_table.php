<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_profile_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_profile_id')->constrained('leave_profile')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            $table->decimal('min_hours', 8, 2)->default(0);
            $table->decimal('max_hours', 8, 2);
            $table->decimal('default_hours', 8, 2);
            $table->decimal('accrual_rate_hours_per_month', 8, 2)->nullable();
            $table->decimal('carry_over_max_hours', 8, 2)->nullable();

            $table->boolean('allow_negative')->default(false);
            $table->decimal('negative_max_hours', 8, 2)->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['leave_profile_id', 'leave_type_id']);
            $table->index(['leave_profile_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_profile_allocations');
    }
};
