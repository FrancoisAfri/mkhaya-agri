<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('recruitment_jobs')->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['screening', 'assessment', 'interview', 'offer', 'onboarding']);
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['job_id', 'order_index']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_pipeline_stages');
    }
};
