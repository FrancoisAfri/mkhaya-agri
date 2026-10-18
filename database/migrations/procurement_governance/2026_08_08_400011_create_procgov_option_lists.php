<?php

use Database\Seeders\ProcurementGovernanceModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The small tenant-configurable pick lists — procurement categories (§2.1, and a filter dimension
 * throughout §3.1) and committee types (§1.2).
 *
 * Both were free text, which meant an empty box on a new tenant and, worse, "ICT", "I.C.T." and
 * "ict" arriving as three different categories that then never group correctly in a spend report.
 * A category people type is a category nobody can report on.
 *
 * One table with a `list_key` rather than a table per list: they are identical in shape, they are
 * managed on the same settings screen, and a third list (say procurement methods per framework)
 * should not need a migration.
 *
 * Values are seeded per tenant so the dropdowns are useful immediately, and every one of them is
 * editable — the seeded set is a starting point, not a taxonomy this module is imposing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procgov_option_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // procurement_category | committee_type
            $t->string('list_key', 40);
            $t->string('code', 60);
            $t->string('label', 190);
            $t->text('description')->nullable();

            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);

            $t->timestamps();

            $t->unique(['company_id', 'list_key', 'code'], 'procgov_option_unique');
            $t->index(['company_id', 'list_key', 'is_active'], 'procgov_option_lookup_idx');
        });

        $this->applyTenantRls(['procgov_option_lists']);

        // Backfill existing tenants, including any categories they have already typed by hand —
        // dropping those on the floor would orphan records that are already filed under them.
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $this->seedFor((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_option_lists');
    }

    private function seedFor(int $companyId): void
    {
        $now = now();
        $rows = [];

        foreach (ProcurementGovernanceModuleSeeder::starterOptionLists() as $listKey => $options) {
            foreach (array_values($options) as $i => [$code, $label]) {
                $rows[] = [
                    'company_id' => $companyId,
                    'list_key' => $listKey,
                    'code' => $code,
                    'label' => $label,
                    'sort_order' => $i,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Categories already in use on this tenant's own records, so nothing already filed becomes
        // unselectable the moment the list exists.
        $existing = collect();

        foreach (['procgov_records' => 'category', 'procgov_plan_items' => 'category'] as $table => $column) {
            if (Schema::hasTable($table)) {
                $existing = $existing->merge(
                    DB::table($table)->where('company_id', $companyId)->whereNotNull($column)->distinct()->pluck($column)
                );
            }
        }

        $seededLabels = array_column($rows, 'label');

        foreach ($existing->filter()->unique() as $i => $label) {
            if (in_array($label, $seededLabels, true)) {
                continue;
            }

            $rows[] = [
                'company_id' => $companyId,
                'list_key' => 'procurement_category',
                'code' => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', substr((string) $label, 0, 50))),
                'label' => $label,
                'sort_order' => 900 + $i,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('procgov_option_lists')->insertOrIgnore($chunk);
        }
    }

    private function applyTenantRls(array $tables): void
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
};
