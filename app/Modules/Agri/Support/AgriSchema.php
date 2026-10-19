<?php

namespace App\Modules\Agri\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * The columns and isolation every Mkhaya Agri table shares, so each migration states only what is
 * particular to it.
 */
final class AgriSchema
{
    /** id, uuid and the owning organisation. */
    public static function owned(Blueprint $table): void
    {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->unsignedBigInteger('company_id');
    }

    /** As owned(), plus the farm the record belongs to — which is what farm access confines. */
    public static function farmOwned(Blueprint $table): void
    {
        self::owned($table);
        $table->foreignId('farm_id')->constrained('farms')->restrictOnDelete();
    }

    /**
     * Row-level security on company_id, the same policy every other module uses: company_id defaults
     * from the session, and a row of another organisation can be neither read nor written.
     */
    public static function tenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
}
