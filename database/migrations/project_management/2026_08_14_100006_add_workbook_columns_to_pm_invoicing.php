<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the client's workbook has that the first cut of this module did not.
 *
 * Found by running the client's own 37 acceptance scenarios rather than by reading the spec again:
 * six of them failed, and five failed on a field that simply was not there. Each is on a specific
 * sheet of the workbook and none is inferable from the others.
 *
 *   cc              Annexure 2's engine-capacity column. Travel is claimed per kilometre at a
 *                   tariff that depends on the vehicle, so the cc is part of the claim's evidence
 *                   even though it does not enter the arithmetic.
 *
 *   kimopax_fee     A separate fee column on Annexures 6 (OHS) and 13 (Student Training), named
 *                   after the sub-consultant. It sits beside the claim rather than inside it.
 *
 *   projections     The monthly planning figures on Annexures 4, 5, 6, 7 and 13 — the workbook
 *                   writes them as `= 10500 / 6` across six cells. Stored as JSON keyed by month
 *                   rather than as rows, because they are a note about the appointment rather than
 *                   claims, and nothing sums across annexures.
 *
 *   banking_*       The Tax Invoice's banking block. A tax invoice that does not say where to pay
 *                   is not one a client can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_invoice_annexure_items', function (Blueprint $t) {
            // Engine capacity, as the claim sheet records it — free text because "1600" and
            // "1.6" and "1600cc" all appear on real claim sheets.
            $t->string('cc', 20)->nullable()->after('post_level');
            $t->decimal('kimopax_fee', 18, 2)->nullable()->after('appointment_amount');
        });

        Schema::table('pm_invoice_annexures', function (Blueprint $t) {
            $t->json('projections')->nullable()->after('surcharge_rate');
        });

        Schema::table('pm_invoices', function (Blueprint $t) {
            // Copied onto the account, not read from company settings: a tax invoice states where
            // to pay *as at its date*, and changing the company's bank must not restate one that
            // has already been issued.
            $t->string('banking_name', 120)->nullable()->after('consultant_vat_number');
            $t->string('banking_account_name', 190)->nullable()->after('banking_name');
            $t->string('banking_account_number', 40)->nullable()->after('banking_account_name');
            $t->string('banking_branch_code', 20)->nullable()->after('banking_account_number');
            $t->string('banking_reference', 60)->nullable()->after('banking_branch_code');
        });
    }

    public function down(): void
    {
        Schema::table('pm_invoice_annexure_items', function (Blueprint $t) {
            $t->dropColumn(['cc', 'kimopax_fee']);
        });

        Schema::table('pm_invoice_annexures', function (Blueprint $t) {
            $t->dropColumn('projections');
        });

        Schema::table('pm_invoices', function (Blueprint $t) {
            $t->dropColumn([
                'banking_name', 'banking_account_name', 'banking_account_number',
                'banking_branch_code', 'banking_reference',
            ]);
        });
    }
};
