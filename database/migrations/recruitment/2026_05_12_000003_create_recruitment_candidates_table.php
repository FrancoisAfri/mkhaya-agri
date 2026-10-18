<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidates', function (Blueprint $table) {
            $table->id();
            // UUID for candidate-portal routes — prevents IDOR via sequential IDs
            $table->uuid('uuid')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('id_number')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->enum('gender', ['male', 'female', 'non_binary', 'prefer_not_to_say'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->string('linkedin_url')->nullable();

            // CV — stored in private S3; path is internal only
            $table->string('cv_path')->nullable();
            $table->string('cv_mime_type', 100)->nullable();
            $table->timestamp('cv_verified_at')->nullable();

            // AI — server-set only, never fillable from user input
            $table->text('ai_summary')->nullable();
            $table->timestamp('fit_score_calculated_at')->nullable();

            // POPIA consent — must be captured before AI processing runs
            $table->timestamp('ai_consent_at')->nullable();
            $table->string('ai_consent_ip', 45)->nullable();

            // Duplicate detection — links to the canonical candidate record
            $table->foreignId('is_duplicate_of')->nullable()->constrained('recruitment_candidates')->nullOnDelete();

            // Data retention — scheduled command purges after window expires
            $table->timestamp('purge_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Duplicate detection indexes
            $table->unique('email');
            $table->index('phone');
            $table->index('purge_at');
        });

        // Full-text search across name + ai_summary (PostgreSQL GIN)
        DB::statement("
            CREATE INDEX idx_candidates_fts
            ON recruitment_candidates
            USING GIN (to_tsvector('english', first_name || ' ' || last_name || ' ' || coalesce(ai_summary, '')))
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_candidates_fts');
        Schema::dropIfExists('recruitment_candidates');
    }
};
