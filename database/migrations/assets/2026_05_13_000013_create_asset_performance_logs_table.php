<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetPerformanceLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_performance_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('digital_twin_id')->nullable();
            $table->unsignedInteger('logged_by')->nullable();
            $table->float('operating_hours_delta')->default(0);
            $table->float('odometer_delta')->default(0);
            $table->float('utilization_rate')->nullable();
            $table->unsignedInteger('downtime_minutes')->default(0);
            $table->json('metrics')->nullable()->comment('Extensible sensor/KPI readings');
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->index(['asset_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_performance_logs');
    }
}
