<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetMaintenanceSchedulesTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenance_schedules', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('maintenance_type', 30)->default('preventive')->index();
            $table->string('status', 30)->default('scheduled')->index();
            $table->unsignedInteger('assigned_to')->nullable();
            $table->string('vendor_name')->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->decimal('actual_cost', 12, 2)->nullable();
            $table->date('due_date')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('next_due_date')->nullable();
            $table->unsignedSmallInteger('recurrence_days')->nullable()->comment('Auto-schedule next occurrence');
            $table->unsignedInteger('approval_id')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('approval_id')->references('id')->on('asset_approvals')->nullOnDelete();
            $table->index(['asset_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_schedules');
    }
}
