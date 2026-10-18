<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storage limits below the level of the whole company.
 *
 * The company already has one, from its plan, and it is the wrong instrument for the problem it is
 * usually asked to solve. One department putting video into the repository fills the company's
 * allowance, and the first anybody knows about it is that nobody anywhere can upload anything. The
 * limit that would have helped is the one on the department.
 *
 * A limit is a number of bytes and a warning threshold, against a department, a person or a folder.
 * The warning matters as much as the limit: a quota that refuses an upload with no warning
 * beforehand is indistinguishable from the system being broken, and the person it stops is rarely
 * the person who filled it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_quotas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();

            // 'department', 'user' or 'folder'. Kept as a string rather than three tables: they
            // differ only in what the id points at, and the enforcement asks the same question of
            // all three.
            $table->string('scope', 20);
            $table->unsignedBigInteger('scope_id');

            $table->unsignedBigInteger('bytes_limit');
            $table->unsignedSmallInteger('warn_percent')->default(85);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // One limit per thing. Two limits on the same department is a contradiction somebody
            // set by accident, and whichever the code read first would look arbitrary.
            $table->unique(['company_id', 'scope', 'scope_id']);
        });

        DB::statement("ALTER TABLE document_quotas ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_quotas ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_quotas FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_quotas_tenant ON document_quotas USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_quotas');
    }
};
