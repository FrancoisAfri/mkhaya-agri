<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetCategorySeeder extends Seeder
{
    public function run(): void
    {
        // SARS s11(e) standard wear & tear rates (annual)
        $parents = [
            ['name' => 'IT Equipment',     'sars_wear_rate' => 0.3333, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => 3],
            ['name' => 'Vehicles',          'sars_wear_rate' => 0.2000, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'reducing_balance', 'default_useful_life_years' => 5],
            ['name' => 'Office Furniture',  'sars_wear_rate' => 0.1667, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => 6],
            ['name' => 'Plant & Machinery', 'sars_wear_rate' => 0.1000, 'sars_allowance_section' => 's12c', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => 10],
            ['name' => 'Land & Buildings',  'sars_wear_rate' => 0.0000, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => null],
            ['name' => 'Software',          'sars_wear_rate' => 0.3333, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => 3],
        ];

        foreach ($parents as $parent) {
            DB::table('asset_categories')->updateOrInsert(
                ['name' => $parent['name'], 'parent_id' => null],
                array_merge($parent, ['uuid' => (string) Str::uuid(), 'status' => 1, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        $itId        = DB::table('asset_categories')->where('name', 'IT Equipment')->value('id');
        $vehicleId   = DB::table('asset_categories')->where('name', 'Vehicles')->value('id');
        $furnitureId = DB::table('asset_categories')->where('name', 'Office Furniture')->value('id');

        $subcategories = [
            ['parent_id' => $itId,        'name' => 'Laptops',         'sars_wear_rate' => 0.3333, 'default_useful_life_years' => 3],
            ['parent_id' => $itId,        'name' => 'Desktops',        'sars_wear_rate' => 0.3333, 'default_useful_life_years' => 3],
            ['parent_id' => $itId,        'name' => 'Servers',         'sars_wear_rate' => 0.3333, 'default_useful_life_years' => 3],
            ['parent_id' => $itId,        'name' => 'Printers',        'sars_wear_rate' => 0.3333, 'default_useful_life_years' => 3],
            ['parent_id' => $itId,        'name' => 'Networking',      'sars_wear_rate' => 0.3333, 'default_useful_life_years' => 3],
            ['parent_id' => $itId,        'name' => 'Mobile Devices',  'sars_wear_rate' => 0.3333, 'default_useful_life_years' => 3],
            ['parent_id' => $vehicleId,   'name' => 'Passenger Cars',  'sars_wear_rate' => 0.2000, 'default_useful_life_years' => 5],
            ['parent_id' => $vehicleId,   'name' => 'Trucks',          'sars_wear_rate' => 0.2000, 'default_useful_life_years' => 5],
            ['parent_id' => $vehicleId,   'name' => 'Motorcycles',     'sars_wear_rate' => 0.2000, 'default_useful_life_years' => 5],
            ['parent_id' => $furnitureId, 'name' => 'Desks & Chairs',  'sars_wear_rate' => 0.1667, 'default_useful_life_years' => 6],
            ['parent_id' => $furnitureId, 'name' => 'Cabinets & Shelving', 'sars_wear_rate' => 0.1667, 'default_useful_life_years' => 6],
        ];

        foreach ($subcategories as $sub) {
            DB::table('asset_categories')->updateOrInsert(
                ['name' => $sub['name'], 'parent_id' => $sub['parent_id']],
                array_merge($sub, [
                    'uuid'                        => (string) Str::uuid(),
                    'sars_allowance_section'      => 's11e',
                    'default_depreciation_method' => 'straight_line',
                    'status'                      => 1,
                    'created_at'                  => now(),
                    'updated_at'                  => now(),
                ])
            );
        }
    }
}
