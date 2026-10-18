<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes (2.2). A credit note is a fin_invoices row with doc_type
 * 'credit_note', applied against an invoice — it reverses income/VAT and credits
 * the AR control, and reduces the target invoice's balance. Ageing/statements
 * treat it as a credit, so the subledger still ties to the GL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->string('doc_type', 20)->default('invoice')->after('direction'); // invoice | credit_note
            $table->unsignedBigInteger('applies_to_invoice_id')->nullable()->after('doc_type');
            $table->index('doc_type');
        });
    }

    public function down(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->dropColumn(['doc_type', 'applies_to_invoice_id']);
        });
    }
};
