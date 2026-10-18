<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('claim_scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('report_type', 50);
            $table->string('frequency', 20);    // daily | weekly | monthly
            $table->string('format', 10)->default('csv'); // csv | pdf
            $table->text('recipients');          // comma-separated emails
            $table->json('filters')->nullable(); // date_from, date_to, category_id, status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_scheduled_reports');
    }
};
