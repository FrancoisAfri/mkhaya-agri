<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_vacancies', function (Blueprint $table) {
            $table->string('visibility', 30)->default('external_only')->after('status');
            $table->date('internal_deadline')->nullable()->after('visibility');
            $table->string('application_token', 64)->unique()->nullable()->after('internal_deadline');
            $table->timestamp('published_internally_at')->nullable()->after('application_token');
            $table->timestamp('published_externally_at')->nullable()->after('published_internally_at');
            $table->timestamp('transitioned_to_external_at')->nullable()->after('published_externally_at');

            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_vacancies', function (Blueprint $table) {
            $table->dropIndex(['visibility']);
            $table->dropUnique(['application_token']);
            $table->dropColumn([
                'visibility',
                'internal_deadline',
                'application_token',
                'published_internally_at',
                'published_externally_at',
                'transitioned_to_external_at',
            ]);
        });
    }
};
