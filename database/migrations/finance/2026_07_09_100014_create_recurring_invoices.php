<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring / subscription invoices (2.2). A template + schedule that a daily
 * job turns into real invoices (posted through the shared engine), advancing
 * the next run date each time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('party_id');
            $table->string('name')->nullable();
            $table->char('currency', 3)->default('ZAR');
            $table->string('frequency', 12)->default('monthly'); // weekly | monthly | quarterly | yearly
            $table->unsignedInteger('interval')->default(1);      // every N periods
            $table->date('start_date');
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->string('status', 12)->default('active');      // active | paused | ended
            $table->boolean('auto_email')->default(false);
            $table->string('description')->nullable();
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_run_date']);
        });

        Schema::create('fin_recurring_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recurring_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->timestamps();

            $table->index('recurring_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_recurring_invoice_lines');
        Schema::dropIfExists('fin_recurring_invoices');
    }
};
