<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make four previously-global settings tables tenant-aware.
 *
 * - public_holidays & app_settings: NULLable company_id — a NULL row is a shared
 *   platform default visible to every tenant; a non-NULL row is that tenant's own
 *   entry/override. Existing rows stay NULL so current behaviour is preserved.
 * - sms_configurations & offline_policies: per-tenant. Existing rows backfill to
 *   company 1 (the platform/master company that owns the current data).
 */
return new class extends Migration
{
    public function up(): void
    {
        // public_holidays — shared defaults stay NULL, tenants add their own.
        if (Schema::hasTable('public_holidays') && ! Schema::hasColumn('public_holidays', 'company_id')) {
            Schema::table('public_holidays', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->index()->after('id');
            });
        }

        // sms_configurations — per-tenant gateway config.
        if (Schema::hasTable('sms_configurations') && ! Schema::hasColumn('sms_configurations', 'company_id')) {
            Schema::table('sms_configurations', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->index()->after('id');
            });
            DB::table('sms_configurations')->whereNull('company_id')->update(['company_id' => 1]);
        }

        // offline_policies — per-tenant policy (existing single row → company 1).
        if (Schema::hasTable('offline_policies') && ! Schema::hasColumn('offline_policies', 'company_id')) {
            Schema::table('offline_policies', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->index()->after('id');
            });
            DB::table('offline_policies')->whereNull('company_id')->update(['company_id' => 1]);
        }

        // app_settings — NULL = global default; swap the unique(key) constraint for a
        // per-company one plus a partial unique for the global (NULL-company) rows.
        if (Schema::hasTable('app_settings') && ! Schema::hasColumn('app_settings', 'company_id')) {
            Schema::table('app_settings', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->index()->after('id');
            });
            DB::statement('ALTER TABLE app_settings DROP CONSTRAINT IF EXISTS app_settings_key_unique');
            DB::statement('DROP INDEX IF EXISTS app_settings_key_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS app_settings_company_key_unique ON app_settings (company_id, key)');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS app_settings_global_key_unique ON app_settings (key) WHERE company_id IS NULL');
        }
    }

    public function down(): void
    {
        foreach (['public_holidays', 'sms_configurations', 'offline_policies', 'app_settings'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('company_id'));
            }
        }
    }
};
