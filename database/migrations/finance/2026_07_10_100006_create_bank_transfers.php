<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transfers between the company's own bank / cash accounts (Phase 3.4). Each
 * transfer posts a single balanced journal (Dr destination bank, Cr source bank,
 * plus an optional bank charge) and is reconcilable on both sides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_transfers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('from_bank_id');
            $t->unsignedBigInteger('to_bank_id');
            $t->decimal('amount', 18, 2);
            $t->decimal('fee', 18, 2)->default(0);
            $t->date('transfer_date');
            $t->string('reference', 80)->nullable();
            $t->string('description', 255)->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['from_bank_id', 'to_bank_id']);
            $t->index('transfer_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_transfers');
    }
};
