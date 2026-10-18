<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled reports shared by the Contract & Supplier modules. A daily/weekly/
 * monthly schedule generates a report (PDF/Excel) and emails it to recipients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('module');        // contracts | suppliers
            $table->string('name');
            $table->string('report_key');
            $table->string('cadence')->default('daily'); // daily | weekly | monthly
            $table->string('run_time', 5)->default('07:00');
            $table->unsignedTinyInteger('day_of_week')->nullable();  // 1=Mon..7=Sun
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1..31
            $table->string('format')->default('pdf'); // pdf | excel | both
            $table->text('recipients');               // comma-separated emails
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['module', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_scheduled_reports');
    }
};
