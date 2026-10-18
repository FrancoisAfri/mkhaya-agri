<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the practice charges VAT at all, and the end of the workbook's 14%.
 *
 * The original spreadsheet rated its disbursement fee block at 14% while everything else used 15%.
 * That was reproduced deliberately and flagged on screen; the client has now confirmed it was a
 * pre-2018 VAT rate nobody had updated. So the default becomes the VAT rate, and a practice states
 * once whether it is VAT registered rather than every invoice carrying an assumption.
 *
 * ── Only the settings and unissued drafts are touched ───────────────────────
 *
 * Every invoice stores its own rates, which is the whole point of storing them: an invoice already
 * issued at 14% was issued at 14%, and rewriting it would falsify a document the client has. The
 * update below is restricted to `draft` — invoices nobody has been sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_invoicing_settings', function (Blueprint $t) {
            // Most South African consulting practices are registered, so the default is on and a
            // practice that is not says so once.
            $t->boolean('vat_registered')->default(true)->after('disbursement_fee_rate');
        });

        // Bring the tenant default into line: the disbursement block follows the VAT rate.
        DB::table('pm_invoicing_settings')
            ->whereColumn('disbursement_fee_rate', '!=', 'vat_rate')
            ->update(['disbursement_fee_rate' => DB::raw('vat_rate')]);

        // And any invoice still being prepared. Issued ones keep what they were issued with.
        DB::table('pm_invoices')
            ->where('status', 'draft')
            ->whereColumn('disbursement_fee_rate', '!=', 'vat_rate')
            ->update(['disbursement_fee_rate' => DB::raw('vat_rate')]);
    }

    public function down(): void
    {
        Schema::table('pm_invoicing_settings', fn (Blueprint $t) => $t->dropColumn('vat_registered'));
    }
};
