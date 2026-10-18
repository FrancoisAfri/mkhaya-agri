<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One vocabulary for pay frequency across the whole payroll stack: monthly | fortnightly | weekly.
 *
 * The in-house settings table was created as an enum of monthly|biweekly|weekly, the request
 * validator accepted monthly|bi-weekly|weekly, and the tax engine keys off monthly|fortnightly|weekly.
 * Three spellings of the same idea meant the middle option could not be saved at all (the UI sent
 * "biweekly", which the validator rejected), and had it saved it would have been unknown to the tax
 * engine, which silently annualised it as monthly — the wrong PAYE on every fortnightly payslip.
 *
 * "fortnightly" wins because it is the spelling the SARS-facing tax engine and the whole bureau side
 * already use; changing one settings column is far cheaper than re-keying the engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_settings')) {
            return;
        }

        // The column was declared as an enum, which on PostgreSQL is a varchar plus a CHECK
        // constraint. The constraint has to go before the value can be rewritten, and Doctrine
        // isn't available to do it for us — so drop it by name and widen the column to a plain
        // string. Validation is enforced in the request, which is where the message belongs.
        if (DB::getDriverName() === 'pgsql') {
            $constraint = DB::selectOne("
                SELECT con.conname
                FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                WHERE rel.relname = 'payroll_settings'
                  AND con.contype = 'c'
                  AND pg_get_constraintdef(con.oid) ILIKE '%pay_frequency%'
                LIMIT 1
            ");

            if ($constraint) {
                DB::statement('ALTER TABLE payroll_settings DROP CONSTRAINT ' . $constraint->conname);
            }

            DB::statement('ALTER TABLE payroll_settings ALTER COLUMN pay_frequency TYPE VARCHAR(20)');
            DB::statement("ALTER TABLE payroll_settings ALTER COLUMN pay_frequency SET DEFAULT 'monthly'");
        }

        // Any row that did manage to store one of the old spellings becomes the canonical one.
        // In practice there should be none — the UI value never passed validation — but a row
        // seeded or imported directly would otherwise keep mis-taxing silently.
        DB::table('payroll_settings')
            ->whereIn('pay_frequency', ['biweekly', 'bi-weekly', 'bi_weekly'])
            ->update(['pay_frequency' => 'fortnightly']);

        DB::table('payroll_settings')
            ->whereNotIn('pay_frequency', ['monthly', 'fortnightly', 'weekly'])
            ->update(['pay_frequency' => 'monthly']);

        // The bureau side already speaks the right vocabulary; normalise anything odd for safety.
        if (Schema::hasTable('bureau_companies')) {
            DB::table('bureau_companies')
                ->whereIn('pay_frequency', ['biweekly', 'bi-weekly', 'bi_weekly'])
                ->update(['pay_frequency' => 'fortnightly']);
        }

        if (Schema::hasTable('bureau_employees')) {
            DB::table('bureau_employees')
                ->whereIn('pay_frequency', ['biweekly', 'bi-weekly', 'bi_weekly'])
                ->update(['pay_frequency' => 'fortnightly']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_settings')) {
            return;
        }

        DB::table('payroll_settings')
            ->where('pay_frequency', 'fortnightly')
            ->update(['pay_frequency' => 'biweekly']);
    }
};
