<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('report_key', 60);
            $table->string('cadence', 20)->default('weekly');     // daily|weekly|monthly
            $table->string('run_time', 5)->default('07:00');      // HH:MM
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 1=Mon…7=Sun
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('format', 10)->default('pdf');          // pdf|excel|both
            $table->text('recipients');                            // comma-separated emails
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_scheduled_reports');
    }
};
