<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks where each how-to entry came from so enrichment can safely target only the
 * auto-generated baseline rows and never overwrite hand-curated or AI-enriched content.
 *  - baseline: generated from ribbon metadata (HowToGenerator)
 *  - curated : hand-written (HowToSeeder / admin UI)
 *  - ai      : AI-enriched descriptive content (howto:enrich)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('module_how_tos', 'source')) {
            Schema::table('module_how_tos', function (Blueprint $table) {
                $table->string('source')->default('curated')->index();
            });
        }

        // Re-label the auto-generated baseline rows (HowToGenerator) so enrichment can
        // safely target them without touching hand-curated content. Matched by the
        // generator's distinctive answer phrasing.
        \Illuminate\Support\Facades\DB::table('module_how_tos')
            ->where(function ($q) {
                $q->where('answer', 'ilike', 'Open the % module from the main menu, then choose%')
                  ->orWhere('answer', 'ilike', '% lives inside the % module. Open %')
                  ->orWhere('answer', 'ilike', 'The % screen lets you view and manage %');
            })
            ->update(['source' => 'baseline']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('module_how_tos', 'source')) {
            Schema::table('module_how_tos', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
