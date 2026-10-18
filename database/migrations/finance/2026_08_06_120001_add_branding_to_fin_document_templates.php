<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document templates: logo placement, and statements as a template-able document type.
 *
 * Until now a template could only switch the logo on or off, and statements ignored templates
 * entirely — so a tenant who chose a layout still got a fixed statement. Both are what people
 * expect from an invoice/statement branding screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_document_templates', function (Blueprint $t) {
            // 'none' is kept distinct from show_logo=false: show_logo is the master switch, this
            // is where it sits when shown. Existing rows keep today's behaviour (top-left).
            $t->string('logo_position', 10)->default('left')->after('show_logo');
            $t->unsignedSmallInteger('logo_height')->default(60)->after('logo_position');
        });

        // doc_type gains 'statement'. The column is varchar(10) and the value fits, so this is a
        // vocabulary change only — no existing row's meaning changes.
        DB::statement("COMMENT ON COLUMN fin_document_templates.doc_type IS 'both|invoice|quote|statement'");
    }

    public function down(): void
    {
        Schema::table('fin_document_templates', function (Blueprint $t) {
            $t->dropColumn(['logo_position', 'logo_height']);
        });
    }
};
