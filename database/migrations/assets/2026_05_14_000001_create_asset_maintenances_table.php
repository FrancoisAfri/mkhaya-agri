<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('maintenance_schedule_id')->nullable()->constrained('asset_maintenance_schedules');
            
            $table->enum('maintenance_type', ['preventive', 'corrective', 'predictive', 'breakdown']);
            $table->date('maintenance_date');
            $table->date('completed_date')->nullable();
            
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users');
            $table->string('vendor_name')->nullable();
            $table->string('vendor_contact')->nullable();
            
            $table->text('description');
            $table->text('work_performed')->nullable();
            $table->text('parts_replaced')->nullable();
            
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('downtime_hours', 8, 2)->nullable();
            
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled');
            $table->date('next_due_date')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['asset_id', 'maintenance_date']);
            $table->index('status');
            $table->index('maintenance_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenances');
    }
};
