<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applications', function (Blueprint $table) {
            // Make job_id nullable so vacancy-based applications don't need a Job record.
            $table->foreignId('job_id')->nullable()->change();

            // Link vacancy-sourced applications directly to a vacancy.
            $table->foreignId('vacancy_id')
                ->nullable()
                ->after('job_id')
                ->constrained('recruitment_vacancies')
                ->nullOnDelete();

            // Drop the unique constraint on (candidate_id, job_id) — it won't work
            // when job_id is null. Replace with a partial unique index per FK type.
            $table->dropUnique(['candidate_id', 'job_id']);
        });

        // Unique per vacancy (partial — only when vacancy_id is set)
        DB::statement('
            CREATE UNIQUE INDEX recruitment_applications_candidate_vacancy_unique
            ON recruitment_applications (candidate_id, vacancy_id)
            WHERE vacancy_id IS NOT NULL
        ');

        // Unique per job (partial — only when job_id is set)
        DB::statement('
            CREATE UNIQUE INDEX recruitment_applications_candidate_job_unique
            ON recruitment_applications (candidate_id, job_id)
            WHERE job_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recruitment_applications_candidate_vacancy_unique');
        DB::statement('DROP INDEX IF EXISTS recruitment_applications_candidate_job_unique');

        Schema::table('recruitment_applications', function (Blueprint $table) {
            $table->dropForeign(['vacancy_id']);
            $table->dropColumn('vacancy_id');
        });

        // Remove vacancy-only applications (null job_id) before restoring NOT NULL constraint.
        DB::statement('DELETE FROM recruitment_applications WHERE job_id IS NULL');

        Schema::table('recruitment_applications', function (Blueprint $table) {
            $table->foreignId('job_id')->nullable(false)->change();
            $table->unique(['candidate_id', 'job_id']);
        });
    }
};
