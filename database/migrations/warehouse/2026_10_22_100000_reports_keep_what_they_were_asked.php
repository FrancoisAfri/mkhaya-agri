<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-185: every export is a run that keeps who asked, with which filters, in which format, when it
 * was generated and how many rows it held; a large one is built in the background and its file kept
 * for download. Downloads are counted on the run and audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_report_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('report', 40);
            $t->string('title', 160);
            $t->jsonb('parameters');
            $t->string('format', 4); // CSV|XLSX|PDF
            $t->boolean('with_values')->default(false);
            $t->boolean('sensitive')->default(false);
            $t->string('status', 10); // QUEUED|RUNNING|COMPLETE|FAILED
            $t->unsignedInteger('rows')->nullable();
            $t->string('file_path', 300)->nullable();
            $t->string('failure_reason', 500)->nullable();
            $t->unsignedInteger('downloads')->default(0);
            $t->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $t->timestampTz('generated_at')->nullable();
            $t->timestampsTz();
            $t->index(['company_id', 'requested_by', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wms_report_runs ADD CONSTRAINT wms_report_format_chk CHECK (format IN ('CSV','XLSX','PDF'))");
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE wms_report_runs ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE wms_report_runs ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE wms_report_runs FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON wms_report_runs');
            DB::statement("CREATE POLICY tenant_isolation ON wms_report_runs USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_report_runs');
    }
};
