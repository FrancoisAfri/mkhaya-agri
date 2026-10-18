<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-predicted likelihood that a deal will close (win probability), distinct
 * from the stage's static probability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_deals', 'ai_win_probability')) {
                $table->unsignedTinyInteger('ai_win_probability')->nullable()->after('probability');
            }
            if (! Schema::hasColumn('crm_deals', 'ai_win_reason')) {
                $table->string('ai_win_reason')->nullable()->after('ai_win_probability');
            }
            if (! Schema::hasColumn('crm_deals', 'ai_win_at')) {
                $table->timestamp('ai_win_at')->nullable()->after('ai_win_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            $table->dropColumn(['ai_win_probability', 'ai_win_reason', 'ai_win_at']);
        });
    }
};
