<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The variant follows its asset's tenancy exactly.
 *
 * `content_assets.company_id` is nullable — an asset generated outside a tenant context, from a
 * console command or a seeder, has none. The variants table was created NOT NULL, so storing the
 * renderings of such an asset failed at the last step and took the whole generation with it.
 *
 * Nullable here is not a hole: the row-level policy still applies, and a variant is only ever
 * reachable through its parent asset, which is scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_asset_variants')) {
            return;
        }

        DB::statement('ALTER TABLE "content_asset_variants" ALTER COLUMN company_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_asset_variants')) {
            return;
        }

        DB::statement('DELETE FROM "content_asset_variants" WHERE company_id IS NULL');
        DB::statement('ALTER TABLE "content_asset_variants" ALTER COLUMN company_id SET NOT NULL');
    }
};
