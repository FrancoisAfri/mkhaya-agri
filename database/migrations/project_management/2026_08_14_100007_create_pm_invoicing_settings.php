<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The practice's own invoicing details, entered once.
 *
 * Who the invoice is *from* and where to pay does not change between projects, and typing it onto
 * every first invoice is how a bank account number ends up wrong on one of them. These are captured
 * in Setup and copied onto an invoice when it is opened.
 *
 * ── Copied, not referenced ──────────────────────────────────────────────────
 *
 * An invoice that read its banking details from here would silently restate every historic invoice
 * the day the practice changed bank. So this is a source of *defaults*: the values are written onto
 * the invoice at the moment it is opened, and the invoice keeps them for good. Same reasoning as
 * the VAT rate, and the same reasoning that puts the client's details on the invoice rather than
 * joining to the client record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_invoicing_settings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            // The consultant — who the invoice is from.
            $t->string('consultant_name', 190)->nullable();
            $t->text('consultant_address')->nullable();
            $t->string('consultant_reference', 60)->nullable();
            $t->string('consultant_vat_number', 40)->nullable();
            $t->string('vendor_number', 60)->nullable();

            // Where to pay.
            $t->string('banking_name', 120)->nullable();
            $t->string('banking_account_name', 190)->nullable();
            $t->string('banking_account_number', 40)->nullable();
            $t->string('banking_branch_code', 20)->nullable();

            // Defaults for a new invoice. Both are stated per invoice as well, so changing them
            // here affects the next invoice and never one already issued.
            $t->decimal('vat_rate', 9, 6)->default(0.15);
            $t->decimal('disbursement_fee_rate', 9, 6)->default(0.14);

            // The standard wording for the account description, e.g.
            // "Professional Fee and disbursements account".
            $t->string('account_description', 190)->nullable();

            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            // One row per tenant.
            DB::statement('CREATE UNIQUE INDEX pm_invoicing_settings_company_unique ON pm_invoicing_settings (company_id)');
        }

        $this->applyTenantRls(['pm_invoicing_settings']);
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_invoicing_settings');
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
