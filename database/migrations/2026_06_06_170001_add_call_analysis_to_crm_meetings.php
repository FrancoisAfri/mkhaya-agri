<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversation-intelligence output for a meeting/call transcript.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_meetings', 'call_score')) {
                $table->unsignedTinyInteger('call_score')->nullable()->after('sentiment');
            }
            if (! Schema::hasColumn('crm_meetings', 'call_analysis')) {
                $table->json('call_analysis')->nullable()->after('call_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_meetings', function (Blueprint $table) {
            $table->dropColumn(['call_score', 'call_analysis']);
        });
    }
};
