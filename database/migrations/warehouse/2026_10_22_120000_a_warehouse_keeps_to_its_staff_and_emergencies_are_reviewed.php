<?php

use Database\Seeders\WarehouseModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-200 and WMS-201: role templates and the new permissions (through the module seeder), and the
 * emergency override -- break glass, time-boxed, stated reason, every use recorded on the decision
 * it allowed, and reviewed afterwards by somebody else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 1000);
            $t->jsonb('subject_types');
            $t->timestampTz('starts_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('ended_at')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('reviewed_at')->nullable();
            $t->string('review_note', 1000)->nullable();
            $t->timestampsTz();
        });
        Schema::table('wms_approval_steps', function (Blueprint $t) {
            $t->foreignId('override_id')->nullable()->constrained('wms_overrides')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wms_overrides ADD CONSTRAINT wms_override_window_chk CHECK (expires_at > starts_at AND expires_at <= starts_at + interval \'4 hours\')');
            DB::statement('ALTER TABLE wms_overrides ADD CONSTRAINT wms_override_review_chk CHECK (reviewed_by IS NULL OR reviewed_by <> user_id)');
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE wms_overrides ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE wms_overrides ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE wms_overrides FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON wms_overrides');
            DB::statement("CREATE POLICY tenant_isolation ON wms_overrides USING ({$policy}) WITH CHECK ({$policy})");
        }

        (new WarehouseModuleSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('wms_approval_steps', fn (Blueprint $t) => $t->dropConstrainedForeignId('override_id'));
        Schema::dropIfExists('wms_overrides');
    }
};
