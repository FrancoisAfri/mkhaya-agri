<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['violation', 'compliance', 'exception', 'executive', 'behaviour']);
            $table->enum('frequency', ['daily', 'weekly', 'monthly']);
            $table->json('filters')->nullable();             // department, policy, date range
            $table->json('recipients');                      // user IDs or email addresses
            $table->enum('format', ['pdf', 'excel', 'both'])->default('pdf');
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedBigInteger('created_by')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
