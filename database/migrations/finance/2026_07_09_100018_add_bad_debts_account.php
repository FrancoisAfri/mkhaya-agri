<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Ensures a Bad Debts expense account exists for write-offs (config
 * finance.bad_debt_account). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The query builder, not the FinAccount model.
         *
         * A migration runs against the schema as it was on this date; an Eloquent model always
         * describes the schema as it is today. When FinAccount later gained `entity_id`, the model
         * started writing that column into this INSERT and the migration failed on any database
         * built from scratch — the column does not exist yet at this point in the timeline.
         */
        $code = (string) config('finance.bad_debt_account', '6800');

        $exists = DB::table('fin_accounts')->where('code', $code)->whereNull('company_id')->exists();

        if (! $exists) {
            DB::table('fin_accounts')->insert([
                'uuid' => Uuid::uuid5(Uuid::NAMESPACE_OID, "fin_account:{$code}")->toString(),
                'company_id' => null, 'code' => $code, 'name' => 'Bad Debts',
                'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
