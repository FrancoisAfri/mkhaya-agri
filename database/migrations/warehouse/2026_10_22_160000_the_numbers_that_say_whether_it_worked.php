<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §33: KPI figures per month, kept with the definition version they were computed under, and the
 * month a company chose as its baseline -- so "before and after" compares like with like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_kpi_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->char('period', 7); // YYYY-MM
            $t->string('kpi', 40);
            $t->unsignedSmallInteger('definition_version');
            $t->decimal('value', 18, 4)->nullable();
            $t->decimal('numerator', 18, 4)->nullable();
            $t->decimal('denominator', 18, 4)->nullable();
            $t->string('note', 300)->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->unique(['company_id', 'period', 'kpi', 'definition_version'], 'wms_kpi_once');
        });
        Schema::create('wms_kpi_baselines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->unique()->constrained('companies')->cascadeOnDelete();
            $t->char('period', 7);
            $t->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (['wms_kpi_snapshots', 'wms_kpi_baselines'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_kpi_baselines');
        Schema::dropIfExists('wms_kpi_snapshots');
    }
};
