<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the audit trail (spatie activity_log) per company. Adds company_id,
 * backfills it from each entry's causer (user), so a company only sees its own
 * audit log while platform admins see everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_log') && ! Schema::hasColumn('activity_log', 'company_id')) {
            Schema::table('activity_log', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->index()->after('id');
            });
            DB::statement('UPDATE activity_log SET company_id = (SELECT company_id FROM users WHERE users.id = activity_log.causer_id) WHERE company_id IS NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activity_log', 'company_id')) {
            Schema::table('activity_log', fn (Blueprint $t) => $t->dropColumn('company_id'));
        }
    }
};
