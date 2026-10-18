<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('report_key', 60);
            $table->enum('cadence', ['daily', 'weekly', 'monthly'])->default('weekly');
            $table->string('run_time', 5)->default('07:00');
            $table->tinyInteger('day_of_week')->nullable();
            $table->tinyInteger('day_of_month')->nullable();
            $table->enum('format', ['pdf', 'excel', 'both'])->default('excel');
            $table->string('recipients', 500);
            $table->boolean('active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_scheduled_reports');
    }
};
