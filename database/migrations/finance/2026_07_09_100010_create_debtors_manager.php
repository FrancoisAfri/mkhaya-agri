<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Debtors Manager (AR collections): a per-invoice activity log (notes, promises
 * to pay, reminders sent, non-payment reasons + next action) and a small
 * registry of non-payment reason codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_collection_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('party_id');
            $table->string('type', 20); // note | promise | reminder | reason
            $table->date('promised_date')->nullable();
            $table->decimal('promised_amount', 18, 2)->nullable();
            $table->string('reason_code', 40)->nullable();
            $table->date('next_action_date')->nullable();
            $table->text('body')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('party_id');
        });

        Schema::create('fin_nonpayment_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('fin_nonpayment_reasons')->insert([
            ['code' => 'DISPUTE',     'label' => 'Invoice disputed',           'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CASHFLOW',    'label' => 'Customer cash-flow',          'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'QUERY',       'label' => 'Query on invoice',            'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AWAIT_PO',    'label' => 'Awaiting purchase order',     'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PART_AGREED', 'label' => 'Partial payment agreed',      'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NO_RESPONSE', 'label' => 'No response from customer',   'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_collection_activities');
        Schema::dropIfExists('fin_nonpayment_reasons');
    }
};
