<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-backed file storage. Every uploaded document/image/file is stored here
 * as bytes (contents) instead of on disk, keyed by (company_id, disk, path) so the
 * existing Storage:: API keeps working via a custom "database" filesystem driver.
 * company_id is tenant-scoped (RLS + session-var default) so a tenant only ever
 * sees its own files, and per-tenant storage usage = SUM(size) for the company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_files')) {
            Schema::create('tenant_files', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->string('disk', 40)->default('local');
                $t->string('path', 1024);
                $t->string('name')->nullable();
                $t->string('mime', 191)->nullable();
                $t->unsignedBigInteger('size')->default(0);          // true byte size (for quota)
                $t->string('visibility', 12)->default('private');
                $t->longText('contents');                            // file bytes, base64-encoded
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'disk']);
            });
            // A file is unique per (company, disk, path). Hash the path to stay within
            // btree index limits for long paths.
            DB::statement('CREATE UNIQUE INDEX tenant_files_company_disk_path_uq ON tenant_files (company_id, disk, md5(path))');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tenant_files ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
            DB::statement('ALTER TABLE tenant_files ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE tenant_files FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON tenant_files');
            DB::statement("CREATE POLICY tenant_isolation ON tenant_files USING (NULLIF(current_setting('app.company_id', true), '') IS NULL OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint) WITH CHECK (NULLIF(current_setting('app.company_id', true), '') IS NULL OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint)");
        }

        // Per-company storage top-ups (bought/requested extra GB), mirroring
        // company_monthly_credits for AI/SMS but PERMANENT (storage is cumulative).
        if (! Schema::hasTable('company_storage_credits')) {
            Schema::create('company_storage_credits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('bytes');                     // extra bytes granted
                $t->string('source', 20)->default('purchase');       // purchase | request | admin
                $t->string('note')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('tenant_files')) {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON tenant_files');
        }
        Schema::dropIfExists('company_storage_credits');
        Schema::dropIfExists('tenant_files');
    }
};
