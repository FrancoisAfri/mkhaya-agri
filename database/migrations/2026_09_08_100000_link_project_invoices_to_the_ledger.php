<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The receivable an issued project invoice became.
 *
 * A project invoice used to be a document and nothing more — it went to the client, and the books
 * never heard of it. Now it can become a receivable in Accounting, and the two records have to be
 * able to find each other: PM's screens need to show what has been paid, and anybody looking at a
 * debtor needs to get back to the project it came from.
 *
 * Two columns rather than one. `ledger_invoice_id` is the link; `ledger_reference` is the invoice
 * *number* Accounting gave it, which is what a person quotes on the telephone and what survives if
 * the row is ever archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('ledger_invoice_id')->nullable()->after('status');
            $t->string('ledger_reference', 60)->nullable()->after('ledger_invoice_id');

            // No foreign key: Project Management never hard-links into a module that may not be
            // installed. The same rule `pm_external_refs` exists to honour.
            $t->index('ledger_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('pm_invoices', function (Blueprint $t) {
            $t->dropIndex(['ledger_invoice_id']);
            $t->dropColumn(['ledger_invoice_id', 'ledger_reference']);
        });
    }
};
