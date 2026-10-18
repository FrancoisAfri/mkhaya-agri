<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_ai_profiles', function (Blueprint $table) {
            $table->text('cv_text')->nullable()->after('keywords');
            $table->json('parsed_skills')->nullable()->after('cv_text');
            $table->json('parsed_experience')->nullable()->after('parsed_skills');
            $table->json('parsed_qualifications')->nullable()->after('parsed_experience');
            $table->decimal('confidence_score', 3, 2)->nullable()->after('parsed_qualifications');
            $table->string('parse_status', 20)->nullable()->default('pending')->after('confidence_score');
            $table->text('parse_error')->nullable()->after('parse_status');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_ai_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'cv_text',
                'parsed_skills',
                'parsed_experience',
                'parsed_qualifications',
                'confidence_score',
                'parse_status',
                'parse_error',
            ]);
        });
    }
};
