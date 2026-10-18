<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier payment batches (2.3): select open bills into a batch, approve it
 * (maker-checker — a different user), release it to post payments to the GL, and
 * export a bank payment file (PAIN.001 ISO 20022 / CSV).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('reference', 60);
            $table->string('bank_code', 40); // our GL bank account code (debtor account)
            $table->string('status', 12)->default('draft'); // draft | approved | paid | rejected
            $table->decimal('total', 18, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();  // maker
            $table->unsignedBigInteger('approved_by')->nullable(); // checker
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('fin_payment_batch_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('invoice_id'); // the bill
            $table->unsignedBigInteger('party_id');
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('payment_id')->nullable(); // set when released
            $table->timestamps();

            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_payment_batch_lines');
        Schema::dropIfExists('fin_payment_batches');
    }
};
