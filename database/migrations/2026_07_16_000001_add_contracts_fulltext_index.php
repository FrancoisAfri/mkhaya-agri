<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full-text search for contracts — a GIN index over a tsvector of the searchable
 * metadata (title, number, description, governing law, jurisdiction). The 2-arg
 * to_tsvector('english', …) form is IMMUTABLE, so it's indexable. The search scope
 * queries the exact same expression so this index is used. Postgres-only (no-op else).
 */
return new class extends Migration
{
    private const EXPR = "to_tsvector('english', coalesce(title,'') || ' ' || coalesce(contract_number,'') || ' ' || coalesce(description,'') || ' ' || coalesce(governing_law,'') || ' ' || coalesce(jurisdiction,''))";

    public function up(): void
    {
        if (! Schema::hasTable('contracts') || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS contracts_fts_idx ON contracts USING gin (' . self::EXPR . ')');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS contracts_fts_idx');
        }
    }
};
