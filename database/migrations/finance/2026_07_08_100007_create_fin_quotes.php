<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations (2.2) — a standalone Accounting feature so an accounting-only
 * customer can quote → invoice → statement. Quotes don't touch the GL; on
 * acceptance a quote converts into a receivable invoice (which does post).
 * If the CRM module is active a quote may reference a CRM deal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('party_id');
            $table->string('number', 60);
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->char('currency', 3)->default('ZAR');
            $table->string('status', 12)->default('draft'); // draft|sent|accepted|declined|expired|invoiced
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('crm_deal_id')->nullable(); // optional CRM link
            $table->unsignedBigInteger('invoice_id')->nullable();  // set on conversion
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'party_id']);
        });

        Schema::create('fin_quote_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('account_id'); // income account for conversion
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->string('tax_code', 20)->nullable();
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index('quote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_quote_lines');
        Schema::dropIfExists('fin_quotes');
    }
};
