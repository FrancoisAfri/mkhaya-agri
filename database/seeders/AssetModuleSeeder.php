<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\AssetSetting;

class AssetModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();
        $this->seedLocations();

        $this->call(AssetCategorySeeder::class);
    }

    private function seedSettings(): void
    {
        $defaults = [
            ['key' => 'asset_number_prefix',          'value' => 'AST',  'cast' => 'string'],
            ['key' => 'asset_number_sequence',         'value' => '0',    'cast' => 'integer'],
            ['key' => 'approval_threshold_l1',         'value' => '50000','cast' => 'float'],   // R50k triggers single approval
            ['key' => 'approval_threshold_l2',         'value' => '200000','cast' => 'float'],  // R200k triggers 2-step
            ['key' => 'enable_dual_depreciation',      'value' => 'false','cast' => 'boolean'],
            ['key' => 'enable_impairment',             'value' => 'false','cast' => 'boolean'],
            ['key' => 'enable_lease_rou',              'value' => 'false','cast' => 'boolean'],
            ['key' => 'depreciation_run',              'value' => 'annual','cast' => 'string'],
            ['key' => 'retention_years',               'value' => '7',    'cast' => 'integer'],
            ['key' => 'approval_approvers_assignment', 'value' => '[]',   'cast' => 'json'],
        ];

        foreach ($defaults as $setting) {
            AssetSetting::updateOrCreate(
                ['key' => $setting['key']],
                ['uuid' => (string) Str::uuid(), 'value' => $setting['value'], 'cast' => $setting['cast']]
            );
        }
    }

    private function seedLocations(): void
    {
        $hq = AssetLocation::firstOrCreate(
            ['name' => 'Head Office', 'parent_id' => null],
            ['uuid' => (string) Str::uuid(), 'code' => 'HQ', 'status' => 1]
        );

        $floors = ['Ground Floor', 'First Floor', 'Second Floor'];
        foreach ($floors as $floorName) {
            AssetLocation::firstOrCreate(
                ['name' => $floorName, 'parent_id' => $hq->id],
                ['uuid' => (string) Str::uuid(), 'code' => $hq->code . '-' . substr($floorName, 0, 2), 'status' => 1]
            );
        }

        $storeroom = AssetLocation::firstOrCreate(
            ['name' => 'Storeroom', 'parent_id' => $hq->id],
            ['uuid' => (string) Str::uuid(), 'code' => 'HQ-STORE', 'status' => 1]
        );
    }
}
