<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/**
 * Contracts for progress billing + retention (2.2). A contract has a value and a
 * retention %; progress claims bill against it (income recognised, retention
 * held back to a retention-receivable account), released later. Also ensures the
 * retention receivable account exists and links invoices to their contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('party_id');
            $table->unsignedBigInteger('income_account_id');
            $table->string('name');
            $table->decimal('contract_value', 18, 2)->default(0);
            $table->decimal('retention_percent', 6, 3)->default(0);
            $table->decimal('billed_net', 18, 2)->default(0);
            $table->decimal('retention_held', 18, 2)->default(0);
            $table->string('status', 12)->default('active'); // active | completed
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->after('applies_to_invoice_id');
        });

        // The query builder, not the model: see the note in add_bad_debts_account.
        $code = (string) config('finance.retention_account', '1250');

        $exists = DB::table('fin_accounts')->where('code', $code)->whereNull('company_id')->exists();

        if (! $exists) {
            DB::table('fin_accounts')->insert([
                'uuid' => Uuid::uuid5(Uuid::NAMESPACE_OID, "fin_account:{$code}")->toString(),
                'company_id' => null, 'code' => $code, 'name' => 'Retention Receivable',
                'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('fin_invoices', fn (Blueprint $table) => $table->dropColumn('contract_id'));
        Schema::dropIfExists('fin_contracts');
    }
};
