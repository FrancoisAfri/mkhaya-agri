<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();

            $table->string('notes', 255)->nullable();
            $table->string('supporting_docs', 255)->nullable();

            // Use Laravel timestamps instead of manual
            $table->timestamps();

            // Unix timestamps (if required)
            $table->unsignedBigInteger('start_date')->nullable();
            $table->unsignedBigInteger('end_date')->nullable();
            $table->unsignedBigInteger('start_time')->nullable();
            $table->unsignedBigInteger('end_time')->nullable();

            $table->integer('status')->nullable()
                ->comment('0=Pending,1=Approved,2=Rejected,3=Cancelled');

            // Foreign keys (SAFE)
            $table->foreignId('hr_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('leave_type_id')
                ->nullable()
                ->constrained('leave_types')
                ->nullOnDelete();

            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('canceller_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('reject_reason', 255)->nullable();
            $table->string('cancellation_reason', 1000)->nullable();

            $table->integer('leave_days')->nullable();
            $table->integer('leave_hours')->nullable();
            $table->float('leave_taken')->nullable();

            // Indexes
            $table->index('hr_id');
            $table->index('manager_id');
            $table->index('leave_type_id');
            $table->index('status');
            $table->index('start_date');
        });
    }

    public function down(): void
    {
        // MUST drop child first
        Schema::dropIfExists('leave_applications');
    }
};
