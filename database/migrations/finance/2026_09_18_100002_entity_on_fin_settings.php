<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Finance settings become one row per trading entity instead of one per tenant.
 *
 * `fin_settings` is already the company-file header — trading name, address, VAT and registration
 * numbers, logo, banking details, financial year start. Pointing it at an entity is what lets
 * Mkhaya Move invoice under its own letterhead while Mkhaya MK keeps its own.
 *
 * ── The backfill ─────────────────────────────────────────────────────────────
 *
 * Every existing settings row becomes the default entity of its tenant, named from the trading
 * name already on it. Nothing a tenant sees changes: the same letterhead, on the same documents,
 * now reached through an entity that was created around it.
 *
 * ── Why the column stays nullable ────────────────────────────────────────────
 *
 * Phase 1 is meant to be reversible. While `entity_id` is nullable and nothing reads it as
 * required, the whole change can be rolled back by dropping a column and a table, with the data
 * untouched. The NOT NULL constraint belongs at the end of Phase 2, once every finance table is
 * carrying an entity and the scope has proven itself in production — not at the start, when it
 * would turn a bad afternoon into a restore from backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_settings', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->after('company_id')
                ->constrained('fin_entities')->nullOnDelete();
            $table->index('entity_id');
        });

        /*
         * The uniqueness moves from the tenant to the tenant's entity.
         *
         * `fin_settings.company_id` was unique — one letterhead per tenant, which is precisely what
         * a second trading name needs to stop being true. The constraint is not dropped so much as
         * re-aimed: a tenant may now hold one settings row per entity, and still only one each.
         *
         * Postgres treats NULLs as distinct in a unique index, so the rows that carry no entity —
         * the shared standalone row, and anything written before this runs — are unaffected.
         */
        Schema::table('fin_settings', function (Blueprint $table) {
            $table->dropUnique('fin_settings_company_id_unique');
            $table->unique(['company_id', 'entity_id'], 'fin_settings_company_entity_unique');
        });

        foreach (DB::table('fin_settings')->get() as $settings) {
            /*
             * The entity is named from what the tenant already calls itself.
             *
             * trading_name first — it is what customers see on the document — then the registered
             * company_name, then the tenant's own name, and only then a placeholder. A tenant
             * opening the switcher should recognise the name without having to rename anything.
             */
            $name = $settings->trading_name
                ?: ($settings->company_name
                    ?: (DB::table('companies')->where('id', $settings->company_id)->value('name')
                        ?: 'Default'));

            $entityId = DB::table('fin_entities')->insertGetId([
                'uuid'       => (string) Str::uuid(),
                'company_id' => $settings->company_id,
                'name'       => Str::limit($name, 150, ''),
                'slug'       => Str::slug(Str::limit($name, 50, '')) ?: 'default',
                'is_default' => true,
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('fin_settings')->where('id', $settings->id)->update(['entity_id' => $entityId]);
        }
    }

    public function down(): void
    {
        /*
         * Restoring the single-letterhead rule means there must be one letterhead again: any
         * entity beyond the first is folded away before the constraint that forbids it returns.
         */
        $keep = DB::table('fin_settings')
            ->whereNotNull('company_id')
            ->selectRaw('min(id) as id, company_id')
            ->groupBy('company_id')->pluck('id');

        DB::table('fin_settings')->whereNotNull('company_id')->whereNotIn('id', $keep)->delete();

        Schema::table('fin_settings', function (Blueprint $table) {
            $table->dropUnique('fin_settings_company_entity_unique');
            $table->dropForeign(['entity_id']);
            $table->dropIndex(['entity_id']);
            $table->dropColumn('entity_id');
            $table->unique('company_id', 'fin_settings_company_id_unique');
        });
    }
};
