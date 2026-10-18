<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax Management — historical data import (SRS §23).
 *
 * An import is a batch: uploaded, validated row by row and previewed with its totals, then signed off
 * by someone other than the person who uploaded it before anything is written. Every record it
 * creates carries the batch id, so a migrated figure can always be told apart from one the module
 * produced, and a historical return without SARS evidence is marked unverified rather than filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_import_batches', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('kind', 30);                      // registrations | liabilities | returns | carry_forwards
            $t->string('source_system', 120);
            $t->string('file_name', 255);
            $t->string('file_hash', 64);
            $t->string('status', 20)->default('previewed'); // previewed | committed | rejected
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedInteger('valid_count')->default(0);
            $t->unsignedInteger('error_count')->default(0);
            $t->decimal('amount_total', 18, 2)->default(0);
            $t->jsonb('rows');                           // parsed rows with their row numbers and errors
            $t->jsonb('summary')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->unsignedBigInteger('signed_off_by')->nullable();
            $t->timestampTz('signed_off_at')->nullable();
            $t->text('sign_off_comment')->nullable();
            $t->timestampsTz();
        });

        foreach (['tax_registrations', 'tax_liabilities', 'tax_returns', 'tax_carry_forwards'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('import_batch_id')->nullable()->index());
        }

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE tax_import_batches ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE tax_import_batches ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE tax_import_batches FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON tax_import_batches');
            DB::statement("CREATE POLICY tenant_isolation ON tax_import_batches USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        foreach (['tax_registrations', 'tax_liabilities', 'tax_returns', 'tax_carry_forwards'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('import_batch_id'));
        }
        Schema::dropIfExists('tax_import_batches');
    }
};
