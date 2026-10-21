<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Phase 2 §6.2: grants and subsidies as farm income, and financing costs as a farm expense, in the shared chart. */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['4630', 'Farm Grants & Subsidies', 'income', 'credit'],
        ['5730', 'Farm Finance Costs', 'expense', 'debit'],
    ];

    public function up(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type, $balance]) {
            if (! DB::table('fin_accounts')->whereNull('company_id')->where('code', $code)->exists()) {
                DB::table('fin_accounts')->insert(['uuid' => Uuid::uuid5(Uuid::NAMESPACE_OID, "fin_account:{$code}")->toString(), 'company_id' => null,
                    'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $balance, 'is_postable' => true, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Accounts may carry postings; they are left in place.
    }
};
