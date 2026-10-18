<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Core HR lookup data: marital statuses and ethnicities.
 *
 * Replaces the legacy CountrySeeders class, which seeded these lookups but also
 * re-inserted South Africa + provinces (now owned by CountriesTableSeeder and
 * Provinces_Seeder). Idempotent — safe to re-run.
 */
class HrLookupSeeder extends Seeder
{
    public function run(): void
    {
        $maritalStatuses = ['Single', 'Married', 'Divorced', 'Widower'];
        foreach ($maritalStatuses as $value) {
            DB::table('marital_statuses')->updateOrInsert(
                ['value' => $value],
                ['status' => 1]
            );
        }

        $ethnicities = ['African', 'Asian', 'Caucasian', 'Coloured', 'Indian', 'White'];
        foreach ($ethnicities as $value) {
            DB::table('ethnicities')->updateOrInsert(
                ['value' => $value],
                ['status' => 1]
            );
        }
    }
}
