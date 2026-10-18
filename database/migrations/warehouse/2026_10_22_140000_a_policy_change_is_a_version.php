<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-241: every change to a warehouse's policies is a numbered version -- drafted and checked,
 * activated by name and time, and restorable. Posted transactions never read a version; they were
 * decided under the policy in force when they happened and keep that outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_policy_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->jsonb('policies');
            $t->jsonb('changes');
            $t->string('status', 12); // DRAFT|ACTIVE|SUPERSEDED|DISCARDED
            $t->string('note', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('activated_at')->nullable();
            $t->timestampsTz();
            $t->unique(['warehouse_id', 'version']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wms_policy_versions ADD CONSTRAINT wms_policy_status_chk CHECK (status IN ('DRAFT','ACTIVE','SUPERSEDED','DISCARDED'))");
            DB::statement("CREATE UNIQUE INDEX wms_policy_one_active ON wms_policy_versions (warehouse_id) WHERE status = 'ACTIVE'");
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE wms_policy_versions ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE wms_policy_versions ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE wms_policy_versions FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON wms_policy_versions');
            DB::statement("CREATE POLICY tenant_isolation ON wms_policy_versions USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_policy_versions');
    }
};
