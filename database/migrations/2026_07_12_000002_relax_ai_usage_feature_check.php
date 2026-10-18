<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ai_usage_log started as a Leave-module table with a CHECK constraint pinning
 * `feature` to seven leave/HR values. Now that AI usage is metered platform-wide
 * for per-company quotas (copilot, CRM, governance, …), drop that constraint so
 * any module can record its usage. Postgres only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_usage_log DROP CONSTRAINT IF EXISTS ai_usage_log_feature_check');
        }
    }

    public function down(): void
    {
        // Intentionally not restored — re-adding the narrow list would reject
        // legitimately-logged rows from other modules.
    }
};
