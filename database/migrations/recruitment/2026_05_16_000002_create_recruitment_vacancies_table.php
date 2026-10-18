<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_vacancies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('vacancy_number', 30)->unique();

            $table->foreignId('requisition_id')
                ->nullable()
                ->constrained('recruitment_requisitions')
                ->nullOnDelete();

            $table->string('title', 200);
            $table->string('department', 100)->nullable();

            // Position — without hard FK, see requisitions migration note.
            $table->unsignedBigInteger('position_id')->nullable();

            $table->string('job_type', 30); // VacancyType enum value
            $table->unsignedSmallInteger('headcount')->default(1);

            $table->text('job_description');
            $table->text('required_qualifications')->nullable();
            $table->json('required_skills')->nullable();

            $table->decimal('salary_band_min', 12, 2)->nullable();
            $table->decimal('salary_band_max', 12, 2)->nullable();

            $table->string('status', 30)->default('draft');

            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_vacancies');
    }
};
