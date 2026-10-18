<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssetLicenseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('asset_license')->insert([
            [
                'id' => 3,
                'uuid' => '0270ba7f-5248-429c-9edd-49f5f6b1d72d',
                'name' => 'Test',
                'details' => 'more test',
                'serial' => '3hh333',
                'purchase_date' => '2022-11-02',
                'purchase_cost' => 2343.00,
                'order_number' => '3e4ee3',
                'total' => 234,
                'notes' => null,
                'user_id' => null,
                'asset_type_id' => 1,
                'expiration_date' => '2023-11-02',
                'licence_image' => null,
                'status' => 1,
                'licence_status' => 'un_allocated',
                'created_at' => '2022-11-02 21:56:57',
                'updated_at' => '2022-11-02 21:56:57',
                'deleted_at' => null,
            ],
            [
                'id' => 1,
                'uuid' => 'b4ec4381-89a7-499c-bab5-f376450279e5',
                'name' => 'test',
                'details' => 'tt',
                'serial' => '4f5',
                'purchase_date' => '2022-11-02',
                'purchase_cost' => 44.00,
                'order_number' => '555t',
                'total' => 122,
                'notes' => null,
                'user_id' => null,
                'asset_type_id' => 1,
                'expiration_date' => '2023-11-01',
                'licence_image' => null,
                'status' => 1,
                'licence_status' => 'allocated',
                'created_at' => '2022-11-02 16:34:52',
                'updated_at' => '2022-11-03 17:31:00',
                'deleted_at' => null,
            ],
            [
                'id' => 4,
                'uuid' => '660c694f-098b-4e85-b8fc-e1927e796b3e',
                'name' => 'Drive',
                'details' => 'Google drive',
                'serial' => '345er',
                'purchase_date' => '2022-11-02',
                'purchase_cost' => 12343.00,
                'order_number' => '23453',
                'total' => 120,
                'notes' => null,
                'user_id' => null,
                'asset_type_id' => 1,
                'expiration_date' => '2023-11-02',
                'licence_image' => null,
                'status' => 1,
                'licence_status' => 'allocated',
                'created_at' => '2022-11-02 22:02:30',
                'updated_at' => '2022-11-04 18:03:02',
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'uuid' => '71128602-227d-4af9-aa15-b7c5d037fa84',
                'name' => 'Test',
                'details' => 'more test',
                'serial' => '3hh333',
                'purchase_date' => '2022-11-02',
                'purchase_cost' => 2343.00,
                'order_number' => '3e4ee3',
                'total' => 232,
                'notes' => null,
                'user_id' => null,
                'asset_type_id' => 1,
                'expiration_date' => '2023-11-02',
                'licence_image' => null,
                'status' => 1,
                'licence_status' => 'allocated',
                'created_at' => '2022-11-02 21:56:48',
                'updated_at' => '2022-11-07 12:58:41',
                'deleted_at' => null,
            ],
        ]);
    }
}
