<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax Administration (SRS §3.1): each company's own tax settings — tolerance, reminders, chasing,
 * the provisional split and the assessed-loss limit. config/tax.php supplies the defaults; a
 * company that changes one is recorded on the tax audit trail with its reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->unique();
            $t->jsonb('values');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE tax_settings ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE tax_settings ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE tax_settings FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON tax_settings');
            DB::statement("CREATE POLICY tenant_isolation ON tax_settings USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_settings');
    }
};
