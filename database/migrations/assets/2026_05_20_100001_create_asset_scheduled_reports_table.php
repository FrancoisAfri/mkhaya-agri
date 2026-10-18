<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('report_key', 60);
            $table->string('cadence', 20)->default('daily'); // daily|weekly|monthly
            $table->string('run_time', 5)->default('07:00'); // HH:MM
            $table->tinyInteger('day_of_week')->nullable();  // 1=Mon … 7=Sun
            $table->tinyInteger('day_of_month')->nullable(); // 1–28
            $table->string('format', 10)->default('pdf');    // pdf|excel|both
            $table->text('recipients');                       // comma-sep emails
            $table->boolean('active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('active');
            $table->index('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_scheduled_reports');
    }
};
