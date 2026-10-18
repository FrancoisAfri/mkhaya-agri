<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lost-deal analysis: a structured category and AI coaching on why a deal was
 * lost and how to do better next time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_deals', 'lost_category')) {
                $table->string('lost_category')->nullable()->after('lost_reason');
            }
            if (! Schema::hasColumn('crm_deals', 'lost_ai_advice')) {
                $table->text('lost_ai_advice')->nullable()->after('lost_category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_deals', function (Blueprint $table) {
            $table->dropColumn(['lost_category', 'lost_ai_advice']);
        });
    }
};
