<?php

namespace Database\Seeders;

use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\AssetMake;
use App\Modules\Assets\Models\AssetModel;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\AssetSetting;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AssetTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();
        $locations  = $this->seedLocations();
        $categories = $this->seedCategories();
        $makes      = $this->seedMakes();
        $assets     = $this->seedAssets($categories, $locations, $makes);
        $this->seedAssignments($assets, $locations);
        $this->seedMaintenanceSchedules($assets);
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    private function seedSettings(): void
    {
        $defaults = [
            ['key' => 'asset_number_prefix',          'value' => 'AST',   'cast' => 'string'],
            ['key' => 'asset_number_sequence',         'value' => '0',     'cast' => 'integer'],
            ['key' => 'approval_threshold_l1',         'value' => '50000', 'cast' => 'float'],
            ['key' => 'approval_threshold_l2',         'value' => '200000','cast' => 'float'],
            ['key' => 'enable_dual_depreciation',      'value' => 'false', 'cast' => 'boolean'],
            ['key' => 'enable_impairment',             'value' => 'false', 'cast' => 'boolean'],
            ['key' => 'enable_lease_rou',              'value' => 'false', 'cast' => 'boolean'],
            ['key' => 'depreciation_run',              'value' => 'annual','cast' => 'string'],
            ['key' => 'retention_years',               'value' => '7',     'cast' => 'integer'],
            ['key' => 'approval_approvers_assignment', 'value' => '[]',    'cast' => 'json'],
        ];

        foreach ($defaults as $row) {
            AssetSetting::updateOrCreate(
                ['key' => $row['key']],
                ['uuid' => (string) Str::uuid(), 'value' => $row['value'], 'cast' => $row['cast']]
            );
        }
    }

    // ── Locations ─────────────────────────────────────────────────────────────

    private function seedLocations(): array
    {
        $hq = AssetLocation::firstOrCreate(
            ['name' => 'Head Office', 'parent_id' => null],
            ['uuid' => Str::uuid(), 'code' => 'HQ', 'status' => 1]
        );

        $locations = ['hq' => $hq];

        $floors = [
            'ground' => 'Ground Floor',
            'first'  => 'First Floor',
            'second' => 'Second Floor',
        ];

        foreach ($floors as $key => $name) {
            $locations[$key] = AssetLocation::firstOrCreate(
                ['name' => $name, 'parent_id' => $hq->id],
                ['uuid' => Str::uuid(), 'code' => 'HQ-' . strtoupper(substr($name, 0, 2)), 'status' => 1]
            );
        }

        $locations['store'] = AssetLocation::firstOrCreate(
            ['name' => 'Storeroom', 'parent_id' => $hq->id],
            ['uuid' => Str::uuid(), 'code' => 'HQ-STORE', 'status' => 1]
        );

        $locations['warehouse'] = AssetLocation::firstOrCreate(
            ['name' => 'Warehouse', 'parent_id' => null],
            ['uuid' => Str::uuid(), 'code' => 'WH', 'status' => 1]
        );

        $locations['datacenter'] = AssetLocation::firstOrCreate(
            ['name' => 'Data Centre', 'parent_id' => $hq->id],
            ['uuid' => Str::uuid(), 'code' => 'HQ-DC', 'status' => 1]
        );

        return $locations;
    }

    // ── Categories ────────────────────────────────────────────────────────────

    private function seedCategories(): array
    {
        $parents = [
            'it'        => ['name' => 'IT Equipment',     'sars_wear_rate' => 0.3333, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => 3],
            'vehicles'  => ['name' => 'Vehicles',          'sars_wear_rate' => 0.2000, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'reducing_balance', 'default_useful_life_years' => 5],
            'furniture' => ['name' => 'Office Furniture',  'sars_wear_rate' => 0.1667, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => 6],
            'plant'     => ['name' => 'Plant & Machinery', 'sars_wear_rate' => 0.1000, 'sars_allowance_section' => 's12c', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => 10],
            'software'  => ['name' => 'Software',          'sars_wear_rate' => 0.3333, 'sars_allowance_section' => 's11e', 'default_depreciation_method' => 'straight_line',    'default_useful_life_years' => 3],
        ];

        $result = [];

        foreach ($parents as $key => $data) {
            $result[$key] = AssetCategory::firstOrCreate(
                ['name' => $data['name'], 'parent_id' => null],
                array_merge($data, ['uuid' => Str::uuid(), 'status' => 1])
            );
        }

        $subs = [
            'laptops'   => ['parent' => 'it',        'name' => 'Laptops',              'useful_life' => 3],
            'desktops'  => ['parent' => 'it',        'name' => 'Desktops',             'useful_life' => 3],
            'servers'   => ['parent' => 'it',        'name' => 'Servers',              'useful_life' => 3],
            'printers'  => ['parent' => 'it',        'name' => 'Printers',             'useful_life' => 3],
            'network'   => ['parent' => 'it',        'name' => 'Networking',           'useful_life' => 3],
            'mobile'    => ['parent' => 'it',        'name' => 'Mobile Devices',       'useful_life' => 3],
            'cars'      => ['parent' => 'vehicles',  'name' => 'Passenger Cars',       'useful_life' => 5],
            'trucks'    => ['parent' => 'vehicles',  'name' => 'Trucks',               'useful_life' => 5],
            'desks'     => ['parent' => 'furniture', 'name' => 'Desks & Chairs',       'useful_life' => 6],
            'cabinets'  => ['parent' => 'furniture', 'name' => 'Cabinets & Shelving',  'useful_life' => 6],
        ];

        foreach ($subs as $key => $sub) {
            $parent = $result[$sub['parent']];
            $result[$key] = AssetCategory::firstOrCreate(
                ['name' => $sub['name'], 'parent_id' => $parent->id],
                [
                    'uuid'                        => Str::uuid(),
                    'sars_wear_rate'              => $parent->sars_wear_rate,
                    'sars_allowance_section'      => 's11e',
                    'default_depreciation_method' => 'straight_line',
                    'default_useful_life_years'   => $sub['useful_life'],
                    'status'                      => 1,
                ]
            );
        }

        return $result;
    }

    // ── Makes & Models ────────────────────────────────────────────────────────

    private function seedMakes(): array
    {
        $makeData = [
            'dell'    => ['name' => 'Dell',    'models' => ['Latitude 5540', 'OptiPlex 7090', 'PowerEdge R750', 'Inspiron 15']],
            'apple'   => ['name' => 'Apple',   'models' => ['MacBook Pro 14"', 'MacBook Air M2', 'iPhone 15 Pro', 'iPad Pro']],
            'hp'      => ['name' => 'HP',      'models' => ['EliteBook 840', 'ProBook 450', 'LaserJet Pro M404', 'OfficeJet Pro']],
            'lenovo'  => ['name' => 'Lenovo',  'models' => ['ThinkPad T14', 'ThinkPad L15', 'ThinkCentre M70q', 'Tab M10']],
            'cisco'   => ['name' => 'Cisco',   'models' => ['Catalyst 9200', 'RV340 Router', 'Meraki MX', 'IP Phone 7821']],
            'samsung' => ['name' => 'Samsung', 'models' => ['Galaxy S24', 'Galaxy Tab S9', 'SyncMaster 24"']],
            'toyota'  => ['name' => 'Toyota',  'models' => ['Hilux 2.4 GD-6', 'Fortuner 2.8 GD-6', 'Land Cruiser 200']],
            'ford'    => ['name' => 'Ford',    'models' => ['Ranger 2.0 BiT', 'Transit Van', 'Everest 2.0T']],
            'steelcase'=> ['name' => 'Steelcase', 'models' => ['Leap V2 Chair', 'Flex Standing Desk', 'Series 1 Chair']],
            'microsoft'=> ['name' => 'Microsoft', 'models' => ['Surface Pro 10', 'Surface Laptop 5', 'Surface Hub 2']],
        ];

        $makes = [];
        foreach ($makeData as $key => $data) {
            $make = AssetMake::firstOrCreate(
                ['name' => $data['name']],
                ['uuid' => Str::uuid(), 'status' => true]
            );

            $makes[$key] = ['make' => $make, 'models' => []];

            foreach ($data['models'] as $modelName) {
                $makes[$key]['models'][$modelName] = AssetModel::firstOrCreate(
                    ['make_id' => $make->id, 'name' => $modelName],
                    ['uuid' => Str::uuid(), 'status' => true]
                );
            }
        }

        return $makes;
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    private function seedAssets(array $cats, array $locs, array $makes): array
    {
        $assets = [];

        $definitions = [
            // IT — Laptops
            ['name' => 'Dell Latitude 5540 — Finance',   'cat' => 'laptops',  'make' => 'dell',    'model' => 'Latitude 5540',     'cost' => 18500,  'loc' => 'first',     'status' => 'active',        'condition' => 'good'],
            ['name' => 'MacBook Pro 14" — CEO',           'cat' => 'laptops',  'make' => 'apple',   'model' => 'MacBook Pro 14"',   'cost' => 42000,  'loc' => 'second',    'status' => 'active',        'condition' => 'new'],
            ['name' => 'HP EliteBook 840 — HR',           'cat' => 'laptops',  'make' => 'hp',      'model' => 'EliteBook 840',     'cost' => 16800,  'loc' => 'first',     'status' => 'active',        'condition' => 'good'],
            ['name' => 'Lenovo ThinkPad T14 — IT Dept',  'cat' => 'laptops',  'make' => 'lenovo',  'model' => 'ThinkPad T14',      'cost' => 15200,  'loc' => 'ground',    'status' => 'active',        'condition' => 'good'],
            ['name' => 'Dell Latitude 5540 — Operations', 'cat' => 'laptops',  'make' => 'dell',    'model' => 'Latitude 5540',     'cost' => 18500,  'loc' => 'ground',    'status' => 'active',        'condition' => 'good'],
            ['name' => 'MacBook Air M2 — Design',         'cat' => 'laptops',  'make' => 'apple',   'model' => 'MacBook Air M2',    'cost' => 26000,  'loc' => 'second',    'status' => 'active',        'condition' => 'new'],
            ['name' => 'HP ProBook 450 — Spare',          'cat' => 'laptops',  'make' => 'hp',      'model' => 'ProBook 450',       'cost' => 12500,  'loc' => 'store',     'status' => 'unallocated',   'condition' => 'good'],

            // IT — Desktops
            ['name' => 'Dell OptiPlex 7090 — Reception',  'cat' => 'desktops', 'make' => 'dell',    'model' => 'OptiPlex 7090',     'cost' => 11200,  'loc' => 'ground',    'status' => 'active',        'condition' => 'good'],
            ['name' => 'Lenovo ThinkCentre — Accounts',   'cat' => 'desktops', 'make' => 'lenovo',  'model' => 'ThinkCentre M70q',  'cost' => 9800,   'loc' => 'first',     'status' => 'active',        'condition' => 'good'],
            ['name' => 'Dell OptiPlex 7090 — Boardroom',  'cat' => 'desktops', 'make' => 'dell',    'model' => 'OptiPlex 7090',     'cost' => 11200,  'loc' => 'second',    'status' => 'active',        'condition' => 'fair'],

            // IT — Servers
            ['name' => 'Dell PowerEdge R750 — Primary',   'cat' => 'servers',  'make' => 'dell',    'model' => 'PowerEdge R750',    'cost' => 185000, 'loc' => 'datacenter','status' => 'active',        'condition' => 'good'],
            ['name' => 'Dell PowerEdge R750 — Backup',    'cat' => 'servers',  'make' => 'dell',    'model' => 'PowerEdge R750',    'cost' => 185000, 'loc' => 'datacenter','status' => 'active',        'condition' => 'good'],

            // IT — Printers
            ['name' => 'HP LaserJet Pro M404 — Finance',  'cat' => 'printers', 'make' => 'hp',      'model' => 'LaserJet Pro M404', 'cost' => 6200,   'loc' => 'first',     'status' => 'active',        'condition' => 'good'],
            ['name' => 'HP OfficeJet Pro — Reception',    'cat' => 'printers', 'make' => 'hp',      'model' => 'OfficeJet Pro',     'cost' => 3800,   'loc' => 'ground',    'status' => 'active',        'condition' => 'fair'],

            // IT — Networking
            ['name' => 'Cisco Catalyst 9200 — Core Switch','cat' => 'network', 'make' => 'cisco',   'model' => 'Catalyst 9200',    'cost' => 52000,  'loc' => 'datacenter','status' => 'active',        'condition' => 'new'],
            ['name' => 'Cisco RV340 Router — Main',        'cat' => 'network', 'make' => 'cisco',   'model' => 'RV340 Router',     'cost' => 8500,   'loc' => 'datacenter','status' => 'active',        'condition' => 'good'],

            // IT — Mobile Devices
            ['name' => 'iPhone 15 Pro — Sales Rep 1',      'cat' => 'mobile',  'make' => 'apple',   'model' => 'iPhone 15 Pro',     'cost' => 24000,  'loc' => 'first',     'status' => 'active',        'condition' => 'new'],
            ['name' => 'Samsung Galaxy S24 — MD',           'cat' => 'mobile',  'make' => 'samsung', 'model' => 'Galaxy S24',        'cost' => 21000,  'loc' => 'second',    'status' => 'active',        'condition' => 'new'],
            ['name' => 'Samsung Galaxy Tab S9 — Boardroom', 'cat' => 'mobile',  'make' => 'samsung', 'model' => 'Galaxy Tab S9',     'cost' => 18500,  'loc' => 'second',    'status' => 'active',        'condition' => 'good'],
            ['name' => 'iPad Pro — Design Team',            'cat' => 'mobile',  'make' => 'apple',   'model' => 'iPad Pro',          'cost' => 22000,  'loc' => 'second',    'status' => 'active',        'condition' => 'good'],

            // Vehicles
            ['name' => 'Toyota Hilux — Fleet 001',          'cat' => 'cars',    'make' => 'toyota',  'model' => 'Hilux 2.4 GD-6',   'cost' => 420000, 'loc' => 'warehouse', 'status' => 'active',        'condition' => 'good'],
            ['name' => 'Toyota Fortuner — MD Vehicle',      'cat' => 'cars',    'make' => 'toyota',  'model' => 'Fortuner 2.8 GD-6','cost' => 760000, 'loc' => 'hq',        'status' => 'active',        'condition' => 'new'],
            ['name' => 'Ford Ranger — Operations',           'cat' => 'trucks',  'make' => 'ford',    'model' => 'Ranger 2.0 BiT',   'cost' => 510000, 'loc' => 'warehouse', 'status' => 'active',        'condition' => 'good'],
            ['name' => 'Ford Transit Van — Deliveries',      'cat' => 'trucks',  'make' => 'ford',    'model' => 'Transit Van',       'cost' => 380000, 'loc' => 'warehouse', 'status' => 'under_maintenance', 'condition' => 'fair'],

            // Furniture
            ['name' => 'Steelcase Leap V2 — CEO Office',    'cat' => 'desks',   'make' => 'steelcase','model' => 'Leap V2 Chair',    'cost' => 12500,  'loc' => 'second',    'status' => 'active',        'condition' => 'new'],
            ['name' => 'Steelcase Flex Desk — Open Plan',    'cat' => 'desks',   'make' => 'steelcase','model' => 'Flex Standing Desk','cost' => 9800,  'loc' => 'first',     'status' => 'active',        'condition' => 'good'],
            ['name' => 'Filing Cabinets x4 — Finance',       'cat' => 'cabinets','make' => 'steelcase','model' => 'Series 1 Chair',  'cost' => 8200,   'loc' => 'first',     'status' => 'active',        'condition' => 'good'],

            // Software
            ['name' => 'Microsoft 365 Business — 25 Seats', 'cat' => 'software','make' => 'microsoft','model' => 'Surface Pro 10',  'cost' => 45000,  'loc' => 'datacenter','status' => 'active',        'condition' => 'new'],
            ['name' => 'Surface Pro 10 — Boardroom',         'cat' => 'laptops', 'make' => 'microsoft','model' => 'Surface Pro 10',  'cost' => 32000,  'loc' => 'second',    'status' => 'active',        'condition' => 'new'],

            // Disposed / write-off examples
            ['name' => 'HP LaserJet 1200 — Disposed',        'cat' => 'printers','make' => 'hp',      'model' => 'LaserJet Pro M404','cost' => 2800,  'loc' => 'store',     'status' => 'disposed',      'condition' => 'poor'],
            ['name' => 'Lenovo ThinkPad L15 — Written Off',  'cat' => 'laptops', 'make' => 'lenovo',  'model' => 'ThinkPad L15',     'cost' => 10500,  'loc' => 'store',     'status' => 'disposed',      'condition' => 'poor'],
        ];

        foreach ($definitions as $def) {
            $cat     = $cats[$def['cat']];
            $loc     = $locs[$def['loc']];
            $makeObj = $makes[$def['make']]['make'];
            $model   = $makes[$def['make']]['models'][$def['model']] ?? null;

            $purchaseDate = now()->subMonths(rand(3, 36))->toDateString();

            $asset = Assets::firstOrCreate(
                ['name' => $def['name']],
                [
                    'uuid'                    => (string) Str::uuid(),
                    'asset_number'            => AssetSetting::nextAssetNumber(),
                    'asset_tag'               => 'TAG-' . strtoupper(Str::random(6)),
                    'serial_number'           => 'SN-' . strtoupper(Str::random(10)),
                    'asset_category_id'       => $cat->id,
                    'location_id'             => $loc->id,
                    'asset_make_id'           => $makeObj->id,
                    'asset_model_id'          => $model?->id,
                    'make_number'             => $makeObj->name,
                    'model_number'            => $def['model'],
                    'purchase_cost'           => $def['cost'],
                    'price'                   => $def['cost'],
                    'purchase_date'           => $purchaseDate,
                    'warranty_expiry'         => now()->addYears(2)->toDateString(),
                    'condition'               => $def['condition'],
                    'asset_status'            => $def['status'],
                    'status'                  => 1,
                    'retention_until'         => now()->addYears(7)->toDateString(),
                    'next_impairment_review_at'=> now()->addYear()->toDateString(),
                    'user_id'                 => 1,
                ]
            );

            $assets[] = $asset;
        }

        return $assets;
    }

    // ── Assignments ───────────────────────────────────────────────────────────

    private function seedAssignments(array $assets, array $locs): void
    {
        // Map assets by name fragment → user for realistic assignments
        $userMap = [
            'Finance'    => 1,
            'CEO'        => 1,
            'HR'         => 4,
            'IT Dept'    => 5,
            'Operations' => 5,
            'Design'     => 6,
            'Reception'  => 4,
            'Accounts'   => 6,
            'MD'         => 1,
            'Sales Rep'  => 5,
            'Boardroom'  => null, // shared — no personal assignment
        ];

        foreach ($assets as $asset) {
            if (! in_array($asset->asset_status, ['active', 'under_maintenance'])) {
                continue;
            }

            $assignedTo = null;
            foreach ($userMap as $keyword => $userId) {
                if (str_contains($asset->name, $keyword)) {
                    $assignedTo = $userId;
                    break;
                }
            }

            if (! $assignedTo) {
                continue;
            }

            $exists = AssetAssignment::where('asset_id', $asset->id)->where('is_current', true)->exists();
            if ($exists) {
                continue;
            }

            AssetAssignment::create([
                'uuid'            => Str::uuid(),
                'asset_id'        => $asset->id,
                'assigned_to'     => $assignedTo,
                'assigned_by'     => 1,
                'location_id'     => $asset->location_id,
                'assignment_type' => 'permanent',
                'effective_date'  => now()->subMonths(rand(1, 18))->toDateString(),
                'is_current'      => true,
                'notes'           => 'Initial assignment — seeded data.',
            ]);
        }
    }

    // ── Maintenance Schedules ─────────────────────────────────────────────────

    private function seedMaintenanceSchedules(array $assets): void
    {
        $maintenanceTargets = [
            'Server'   => ['title' => 'Quarterly Server Health Check',    'type' => 'preventive', 'cost' => 3500,  'days' => 90],
            'Vehicle'  => ['title' => 'Annual Service & Safety Check',    'type' => 'preventive', 'cost' => 8500,  'days' => 365],
            'Hilux'    => ['title' => '10 000 km Service',                'type' => 'preventive', 'cost' => 4200,  'days' => 180],
            'Transit'  => ['title' => 'Brake Inspection & Repair',        'type' => 'corrective', 'cost' => 6800,  'days' => null],
            'Printer'  => ['title' => 'Printer Drum & Toner Replacement', 'type' => 'preventive', 'cost' => 1200,  'days' => 180],
            'Switch'   => ['title' => 'Network Equipment Firmware Update','type' => 'preventive', 'cost' => 0,     'days' => 365],
        ];

        foreach ($assets as $asset) {
            foreach ($maintenanceTargets as $keyword => $schedule) {
                if (! str_contains($asset->name, $keyword)) {
                    continue;
                }

                $exists = AssetMaintenanceSchedule::where('asset_id', $asset->id)
                    ->where('title', $schedule['title'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                $status  = $asset->asset_status === 'under_maintenance' ? 'in_progress' : 'scheduled';
                $dueDate = $asset->asset_status === 'under_maintenance'
                    ? now()->subDays(3)->toDateString()
                    : now()->addDays(rand(14, 90))->toDateString();

                AssetMaintenanceSchedule::create([
                    'uuid'             => Str::uuid(),
                    'asset_id'         => $asset->id,
                    'title'            => $schedule['title'],
                    'maintenance_type' => $schedule['type'],
                    'status'           => $status,
                    'estimated_cost'   => $schedule['cost'],
                    'due_date'         => $dueDate,
                    'recurrence_days'  => $schedule['days'],
                    'assigned_to'      => 5,
                    'notes'            => 'Created by test data seeder.',
                ]);
            }
        }
    }
}
