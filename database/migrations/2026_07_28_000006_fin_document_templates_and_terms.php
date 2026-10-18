<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customizable invoice/quote templates + terms & conditions.
 *
 * fin_document_templates: a tenant can keep several templates (accent colour, footer note,
 * default T&C, logo/bank toggles) and mark one default. Invoices and quotes get a
 * template_id (which template to render with) and a terms snapshot (the T&C printed on that
 * document). Tenant-scoped with company_id + FORCE RLS like every finance table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_document_templates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name');
            $t->string('doc_type', 10)->default('both'); // both | invoice | quote
            $t->string('accent_color', 20)->nullable();
            $t->text('footer_note')->nullable();
            $t->text('terms')->nullable();
            $t->boolean('show_logo')->default(true);
            $t->boolean('show_bank_details')->default(true);
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE fin_document_templates ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE fin_document_templates ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE fin_document_templates FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON fin_document_templates');
            DB::statement("CREATE POLICY tenant_isolation ON fin_document_templates USING ({$policy}) WITH CHECK ({$policy})");
        }

        Schema::table('fin_quotes', function (Blueprint $t) {
            $t->text('terms')->nullable()->after('description');
            $t->unsignedBigInteger('template_id')->nullable()->after('terms');
        });
        Schema::table('fin_invoices', function (Blueprint $t) {
            $t->text('terms')->nullable()->after('description');
            $t->unsignedBigInteger('template_id')->nullable()->after('terms');
        });

        // A tenant-wide default T&C to pre-fill new documents (lives with the doc settings).
        Schema::table('fin_settings', function (Blueprint $t) {
            $t->text('default_terms')->nullable()->after('default_message');
        });
    }

    public function down(): void
    {
        Schema::table('fin_quotes', fn (Blueprint $t) => $t->dropColumn(['terms', 'template_id']));
        Schema::table('fin_invoices', fn (Blueprint $t) => $t->dropColumn(['terms', 'template_id']));
        Schema::table('fin_settings', fn (Blueprint $t) => $t->dropColumn('default_terms'));
        Schema::dropIfExists('fin_document_templates');
    }
};
