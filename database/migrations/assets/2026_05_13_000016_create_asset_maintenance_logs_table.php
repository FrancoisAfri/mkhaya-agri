<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetMaintenanceLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenance_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('schedule_id')->nullable();
            $table->unsignedInteger('performed_by');
            $table->text('action_taken');
            $table->json('parts_replaced')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('condition_before', 20)->nullable();
            $table->string('condition_after', 20)->nullable();
            $table->float('hours_taken')->nullable();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('schedule_id')->references('id')->on('asset_maintenance_schedules')->nullOnDelete();
            $table->index(['asset_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_logs');
    }
}
