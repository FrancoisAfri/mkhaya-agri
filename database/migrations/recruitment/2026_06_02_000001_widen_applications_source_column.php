<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the old restrictive check constraint and replace with a string column
        // that accepts all CandidateSource enum values.
        DB::statement('ALTER TABLE recruitment_applications DROP CONSTRAINT IF EXISTS recruitment_applications_source_check');

        DB::statement("
            ALTER TABLE recruitment_applications
            ALTER COLUMN source TYPE VARCHAR(50)
        ");

        DB::statement("
            ALTER TABLE recruitment_applications
            ADD CONSTRAINT recruitment_applications_source_check
            CHECK (source IN (
                'internal', 'external', 'linkedin', 'indeed',
                'referral', 'career_portal', 'agency', 'direct',
                'career_fair', 'social_media', 'job_board'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recruitment_applications DROP CONSTRAINT IF EXISTS recruitment_applications_source_check');

        DB::statement("
            ALTER TABLE recruitment_applications
            ADD CONSTRAINT recruitment_applications_source_check
            CHECK (source IN ('direct', 'referral', 'linkedin', 'job_board', 'agency', 'internal'))
        ");
    }
};
