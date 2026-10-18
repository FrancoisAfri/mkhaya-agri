<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Accounts Receivable & Payable, symmetric by design. Customers and
 * suppliers share fin_parties; sales invoices and supplier bills share
 * fin_invoices (direction receivable|payable); receipts and payments share
 * fin_payments. Every posting flows through the shared Ledger — no direct GL writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_parties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('kind', 20)->default('customer'); // customer | supplier | both
            $table->string('code', 40);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('vat_number', 40)->nullable();
            $table->string('registration_number', 40)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('bank_account', 40)->nullable();
            $table->unsignedInteger('credit_terms_days')->default(30);
            $table->decimal('credit_limit', 18, 2)->nullable();
            $table->boolean('on_hold')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['kind', 'is_active']);
        });

        Schema::create('fin_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('party_id');
            $table->string('direction', 12);              // receivable | payable
            $table->string('number', 60);
            $table->string('party_ref', 80)->nullable();  // supplier's own invoice no (AP)
            $table->date('invoice_date');
            $table->date('due_date');
            $table->char('currency', 3)->default('ZAR');
            $table->string('status', 12)->default('open'); // open | part_paid | paid | void
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['direction', 'status']);
            $table->index('party_id');
            $table->index('due_date');
        });

        Schema::create('fin_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('account_id'); // income (AR) or expense (AP)
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->string('tax_code', 20)->nullable();
            $table->decimal('tax_rate', 6, 3)->default(0); // percent
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });

        Schema::create('fin_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('party_id');
            $table->string('direction', 12); // receipt | payment
            $table->string('number', 60);
            $table->date('payment_date');
            $table->char('currency', 3)->default('ZAR');
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('bank_account_id'); // GL cash/bank account
            $table->string('reference', 120)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('direction');
            $table->index('party_id');
        });

        Schema::create('fin_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index('payment_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_payment_allocations');
        Schema::dropIfExists('fin_payments');
        Schema::dropIfExists('fin_invoice_lines');
        Schema::dropIfExists('fin_invoices');
        Schema::dropIfExists('fin_parties');
    }
};
