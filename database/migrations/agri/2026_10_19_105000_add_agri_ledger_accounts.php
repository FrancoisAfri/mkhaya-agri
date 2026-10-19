<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The accounts Mkhaya Agri posts to, added to the shared (company-less) chart so every
 * organisation has them. Same rows FinanceGlSeeder writes on a fresh install; idempotent.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['4600', 'Produce Sales', 'income', 'credit'],
        ['4610', 'Livestock Sales', 'income', 'credit'],
        ['4620', 'Agricultural Produce Harvested', 'income', 'credit'],
        ['5700', 'Farm Inputs Consumed', 'expense', 'debit'],
        ['5710', 'Animal Health & Feed', 'expense', 'debit'],
        ['5720', 'Farm Direct Expenses', 'expense', 'debit'],
    ];

    public function up(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type, $balance]) {
            $exists = DB::table('fin_accounts')->whereNull('company_id')->where('code', $code)->exists();
            if (! $exists) {
                DB::table('fin_accounts')->insert([
                    'uuid' => Uuid::uuid5(Uuid::NAMESPACE_OID, "fin_account:{$code}")->toString(),
                    'company_id' => null, 'code' => $code, 'name' => $name, 'type' => $type,
                    'normal_balance' => $balance, 'is_postable' => true, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Accounts may carry postings; they are left in place.
    }
};
