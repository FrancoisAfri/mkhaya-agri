<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('report_key');
            $table->enum('cadence', ['daily', 'weekly', 'monthly'])->default('weekly');
            $table->string('run_time', 5)->default('07:00');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->enum('format', ['pdf', 'excel', 'both'])->default('pdf');
            $table->text('recipients');
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_scheduled_reports');
    }
};
