<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a document type say what it is, how long it lasts, and where to get one.
 *
 * A supplier document type was a name and a compulsory flag. That is enough to ask for a file and
 * nothing else — so the system could insist on a Letter of Good Standing and had no idea that one
 * lasts twelve months, no way to tell somebody where to obtain it, and no stable way to refer to it
 * once a tenant had renamed it.
 *
 * This matters most for the statutory documents, because for those there is no API to check against:
 * the Department of Employment and Labour issues a Letter of Good Standing through a portal, and
 * verification is the document and its expiry date or it is nothing. The expiry sweep already chases
 * dates — what it could not do was know that a type should have one.
 *
 *   code                     a stable identifier the installer matches on, so re-running does not
 *                            duplicate a type somebody has renamed
 *   authority                who issues it, which is the first question anybody asks
 *   default_validity_months  how long one is good for, so the upload form can offer the date rather
 *                            than leaving somebody to work it out from the certificate
 *   guidance                 where to get it — the useful half when nothing can be verified
 *                            automatically
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_document_types', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_document_types', 'code')) {
                // Nullable: the types a tenant invented have no library code and never will.
                $table->string('code', 60)->nullable()->after('uuid');
            }
            if (! Schema::hasColumn('supplier_document_types', 'authority')) {
                $table->string('authority', 160)->nullable()->after('document_type_name');
            }
            if (! Schema::hasColumn('supplier_document_types', 'default_validity_months')) {
                $table->unsignedSmallInteger('default_validity_months')->nullable()->after('authority');
            }
            if (! Schema::hasColumn('supplier_document_types', 'guidance')) {
                $table->text('guidance')->nullable()->after('default_validity_months');
            }
        });

        /*
         * One library code per tenant, and only where there is a code.
         *
         * A plain unique over (company_id, code) would not do: Postgres never collides NULLs, so
         * every tenant-invented type with a null code would still satisfy it while the constraint
         * did nothing to stop a second copy of a library type.
         */
        if (Schema::hasColumn('supplier_document_types', 'code')) {
            \Illuminate\Support\Facades\DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS supplier_document_types_company_code_unique
                 ON supplier_document_types (company_id, code)
                 WHERE code IS NOT NULL AND deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS supplier_document_types_company_code_unique');

        Schema::table('supplier_document_types', function (Blueprint $table) {
            foreach (['code', 'authority', 'default_validity_months', 'guidance'] as $column) {
                if (Schema::hasColumn('supplier_document_types', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
