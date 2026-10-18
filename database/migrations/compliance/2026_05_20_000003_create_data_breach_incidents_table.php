<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_breach_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('medium'); // low|medium|high|critical
            $table->string('status', 30)->default('open');     // open|notified|contained|closed
            $table->timestamp('detected_at');
            $table->timestamp('notified_regulator_at')->nullable();
            $table->timestamp('notified_subjects_at')->nullable();
            $table->timestamp('contained_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->integer('affected_record_count')->nullable();
            $table->json('affected_subject_types')->nullable();
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->text('remediation_notes')->nullable();
            $table->string('regulator_reference')->nullable();
            $table->timestamps();

            $table->foreign('reported_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
            $table->index('severity');
            $table->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_breach_incidents');
    }
};
