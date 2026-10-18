<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * fin_accounts uses a DETERMINISTIC uuid (uuid5 of the account code), so two
 * companies with the same account code produce the same uuid — which collides on
 * a global unique. Make the uuid unique PER company instead (company_id, uuid),
 * matching the rest of the tenant model. Postgres-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE fin_accounts DROP CONSTRAINT IF EXISTS fin_accounts_uuid_unique');
        DB::statement('DROP INDEX IF EXISTS fin_accounts_uuid_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS fin_accounts_company_uuid_unique ON fin_accounts (company_id, uuid)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS fin_accounts_company_uuid_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS fin_accounts_uuid_unique ON fin_accounts (uuid)');
    }
};
