<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ensures the Staff Loans Receivable control account exists (Phase 5.6). */
return new class extends Migration
{
    public function up(): void
    {
        // The query builder, not the model: see the note in add_bad_debts_account. `normal_balance`
        // is spelled out here because that default lives on the model, which is no longer involved.
        $exists = DB::table('fin_accounts')->where('code', '1270')->whereNull('company_id')->exists();

        if (! $exists) {
            DB::table('fin_accounts')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => null, 'code' => '1270', 'name' => 'Staff Loans Receivable',
                'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
