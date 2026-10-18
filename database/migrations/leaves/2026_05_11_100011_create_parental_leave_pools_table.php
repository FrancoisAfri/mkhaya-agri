<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Van Wyk Constitutional Court 2025 — shared parental leave pool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parental_leave_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('primary_parent_id')->constrained('hr_people')->restrictOnDelete();
            $table->foreignId('secondary_parent_id')->nullable()->constrained('hr_people')->nullOnDelete();

            $table->date('child_birth_date')->nullable();
            $table->date('expected_birth_date')->nullable();

            $table->enum('pool_type', ['birth', 'adoption', 'surrogacy'])->default('birth');

            // Pool hours
            $table->decimal('total_pool_hours', 8, 2);
            $table->decimal('primary_allocated_hours', 8, 2)->nullable();
            $table->decimal('secondary_allocated_hours', 8, 2)->nullable();
            $table->decimal('primary_used_hours', 8, 2)->default(0);
            $table->decimal('secondary_used_hours', 8, 2)->default(0);

            // Van Wyk 2025: 6-week lock on primary parent
            $table->date('primary_lock_until')->nullable()
                ->comment('Primary parent cannot return to work before this date (Van Wyk 2025)');

            $table->boolean('concurrent_leave_allowed')->default(false)
                ->comment('Whether both parents may take leave simultaneously');

            // UIF
            $table->boolean('uif_eligible')->default(true);
            $table->string('uif_reference', 100)->nullable();

            $table->enum('status', ['pending', 'active', 'exhausted', 'closed'])->default('pending');
            $table->string('legislation_version', 50)->default('VanWyk2025');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('primary_parent_id');
            $table->index('secondary_parent_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parental_leave_pools');
    }
};
