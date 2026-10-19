<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first cut of the agri accounts took 4150–4170 and 5100–5120, and 5100 is the code Project
 * Management maps project material costs to. Installs that already ran it have their shared agri
 * accounts moved to 46xx / 57xx; postings follow because journal lines point at the account id.
 * A fresh install never had the old codes, so this finds nothing to do.
 */
return new class extends Migration
{
    private const MOVES = [
        ['4150', '4600', 'Produce Sales'], ['4160', '4610', 'Livestock Sales'], ['4170', '4620', 'Agricultural Produce Harvested'],
        ['5100', '5700', 'Farm Inputs Consumed'], ['5110', '5710', 'Animal Health & Feed'], ['5120', '5720', 'Farm Direct Expenses'],
    ];

    public function up(): void
    {
        foreach (self::MOVES as [$old, $new, $name]) {
            $row = DB::table('fin_accounts')->whereNull('company_id')->where('code', $old)->where('name', $name)->first();
            if (! $row) {
                continue;
            }
            if (DB::table('fin_accounts')->whereNull('company_id')->where('code', $new)->exists()) {
                // The new code was installed alongside: move the postings onto it and retire the old row.
                $target = DB::table('fin_accounts')->whereNull('company_id')->where('code', $new)->value('id');
                DB::table('fin_journal_lines')->where('account_id', $row->id)->update(['account_id' => $target]);
                DB::table('fin_accounts')->where('id', $row->id)->update(['is_active' => false, 'name' => $name.' (moved to '.$new.')']);
            } else {
                DB::table('fin_accounts')->where('id', $row->id)->update(['code' => $new, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // One-way: the old codes collided with Project Management.
    }
};
