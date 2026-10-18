<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The contract as a document, not just as a record.
 *
 * Signing produced a signing *record* — who, when, from where, and a hash of the wording — but no
 * signed *document*. There was nothing to download, nothing to send to a bank, nothing to produce
 * in a dispute. The contract's own signature block still read "Name: [NAME]" with the blanks empty
 * while the panel beside it said Signed.
 *
 * So a PDF is rendered when the contract goes out for signature, that PDF is what people sign, and
 * the flattened result — signatures burned into the page — is kept as the executed copy.
 *
 * ── Why two documents ───────────────────────────────────────────────────────
 *
 * `document_*` is what was sent: the wording as it stood, rendered once, so every party signs the
 * same bytes rather than a page re-rendered from a template that may have moved underneath them.
 * `signed_document_*` is the executed copy. Keeping both is what lets you show a court the
 * difference between what was offered and what was agreed.
 *
 * Both carry a SHA-256. The one on the signed copy is the answer to "has this been altered since".
 *
 * Stored in the row rather than on a disk because this platform's `local` disk is a database
 * driver anyway (see App\Models — and the CRM import bug this convention caused), and because a
 * legal document that lives in one place cannot be half-deleted by a stray file operation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_contracts', function (Blueprint $t) {
            // What was sent out. Rendered once, at the moment of sending.
            $t->longText('document_data')->nullable();
            $t->string('document_name', 190)->nullable();
            $t->char('document_sha256', 64)->nullable();
            $t->timestamp('document_generated_at')->nullable();

            // The executed copy, with every signature burned into the page.
            $t->longText('signed_document_data')->nullable();
            $t->char('signed_document_sha256', 64)->nullable();
            $t->timestamp('signed_document_at')->nullable();
        });

        // A user's own signature, kept so they do not redraw it on every document.
        //
        // Tender Pursuit already has `tender_user_signatures` and this is deliberately not that
        // table: it belongs to that module's namespace and lifecycle. The shape is copied so the
        // two can be merged into one shared store later without either having to change meaning.
        Schema::create('pm_user_signatures', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->index();

            $t->string('name', 100);
            // 'signature' | 'initial'
            $t->string('kind', 20)->default('signature');
            // A PNG data URI, as the signature pad produces.
            $t->longText('image');

            $t->timestamps();
        });

        $this->applyTenantRls(['pm_user_signatures']);
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_user_signatures');

        Schema::table('pm_contracts', function (Blueprint $t) {
            $t->dropColumn([
                'document_data', 'document_name', 'document_sha256', 'document_generated_at',
                'signed_document_data', 'signed_document_sha256', 'signed_document_at',
            ]);
        });
    }

    /** Copied per-migration; this module has no shared base class. TenantSchemaTest enforces it. */
    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
