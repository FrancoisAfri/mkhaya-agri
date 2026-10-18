<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_utilization_snapshots', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('report_id');
            $table->unsignedInteger('asset_id');
            $table->decimal('total_operating_hours', 10, 2)->default(0);
            $table->unsignedInteger('total_downtime_minutes')->default(0);
            $table->unsignedInteger('idle_time_minutes')->default(0);
            $table->decimal('utilization_rate', 5, 2)->default(0);
            $table->unsignedInteger('log_entry_count')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();

            $table->foreign('report_id')->references('id')->on('asset_utilization_reports')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->unique(['report_id', 'asset_id']);
            $table->index(['report_id', 'utilization_rate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_utilization_snapshots');
    }
};
