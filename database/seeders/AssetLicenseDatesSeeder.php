<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssetLicenseDatesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('asset_license_dates')->insert([
            [
                'id' => 1,
                'uuid' => '7250c77f-b2e6-4005-b8b3-b7b0b6d3b9f8',
                'purchase_date' => '2022-11-02',
                'expiration_date' => '2023-11-02',
                'renewal_date' => null,
                'license_id' => 4,
                'user_id' => null,
                'status' => 1,
                'created_at' => '2022-11-02 22:02:30',
                'updated_at' => '2022-11-02 22:02:30',
            ],
            [
                'id' => 2,
                'uuid' => 'cdfce88f-6e7b-41b6-a854-406996f1847a',
                'purchase_date' => null,
                'expiration_date' => '2024-11-23',
                'renewal_date' => '2023-11-02',
                'license_id' => 4,
                'user_id' => null,
                'status' => 1,
                'created_at' => '2022-11-02 22:05:38',
                'updated_at' => '2022-11-02 22:05:38',
            ],
        ]);
    }
}
