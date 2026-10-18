<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an invoice carry a discount, per line.
 *
 * Per line rather than per invoice, because that is where a discount is actually agreed: a
 * percentage off one item, not a lump sum off the bottom of the page. It also keeps the accounting
 * honest without a new ledger account — the line is credited to income net of the discount, so
 * revenue is stated at what was really charged rather than gross with a contra sitting beside it.
 *
 * VAT follows the discounted figure, which is what the VAT Act requires: tax is due on the
 * consideration actually charged, not on a price nobody paid.
 *
 * `discount_amount` is stored as well as the rate. Rates get edited, and a credit note raised two
 * years later has to reproduce the money that was taken off at the time, not what the current
 * percentage would give.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_invoice_lines', function (Blueprint $table) {
            $table->decimal('discount_rate', 6, 3)->default(0)->after('unit_price');
            $table->decimal('discount_amount', 14, 2)->default(0)->after('discount_rate');
        });

        Schema::table('fin_invoices', function (Blueprint $table) {
            // The sum of the line discounts, so a statement or a report can show what was given
            // away without re-deriving it from every line.
            $table->decimal('discount_total', 14, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('fin_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['discount_rate', 'discount_amount']);
        });

        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->dropColumn('discount_total');
        });
    }
};
