<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FleetDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedApprovalSteps();
        $this->seedVehicleMakesAndModels();
        $this->seedVehicleTypes();
        $this->seedFuelTypes();
        $this->seedLicenceTypes();
        $this->seedKeySafes();
        $this->seedFillingStations();
        $this->seedCardTypes();
        $this->seedDocumentSuppliers();
        $this->seedIncidentTypes();
        $this->seedProjects();
    }

    // ── Approval Steps ────────────────────────────────────────────────────────

    private function seedApprovalSteps(): void
    {
        $steps = [
            // ── Workflow state labels (negative, display-only) ──────────────
            ['step_number' => -7, 'step_label' => 'Security Checked', 'approver_type' => 'none',         'role_name' => null,            'check_local_trip' => false, 'is_active' => true],
            ['step_number' => -6, 'step_label' => 'Security In',      'approver_type' => 'none',         'role_name' => null,            'check_local_trip' => false, 'is_active' => true],
            ['step_number' => -5, 'step_label' => 'Security Out',     'approver_type' => 'none',         'role_name' => null,            'check_local_trip' => false, 'is_active' => true],
            ['step_number' => -4, 'step_label' => 'Returned',         'approver_type' => 'none',         'role_name' => null,            'check_local_trip' => false, 'is_active' => true],
            ['step_number' => -3, 'step_label' => 'Collected',        'approver_type' => 'none',         'role_name' => null,            'check_local_trip' => false, 'is_active' => true],
            ['step_number' => -2, 'step_label' => 'Cancelled',        'approver_type' => 'none',         'role_name' => null,            'check_local_trip' => false, 'is_active' => true],
            ['step_number' => -1, 'step_label' => 'Rejected',         'approver_type' => 'none',         'role_name' => null,            'check_local_trip' => false, 'is_active' => true],
            // ── Real approval steps (positive) ──────────────────────────────
            ['step_number' =>  1, 'step_label' => 'Line Manager Approval',    'approver_type' => 'line_manager', 'role_name' => null,            'check_local_trip' => true,  'is_active' => true],
            ['step_number' =>  2, 'step_label' => 'Fleet Manager Approval',   'approver_type' => 'role',         'role_name' => 'fleet_manager', 'check_local_trip' => false, 'is_active' => true],
        ];

        $inserted = 0;
        foreach ($steps as $step) {
            $changed = DB::table('fleet_booking_approval_configs')->updateOrInsert(
                ['step_number' => $step['step_number']],
                array_merge($step, [
                    'employee_id'     => null,
                    'company_unit_id' => null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ])
            );
            if ($changed) $inserted++;
        }

        $this->command->info("Approval steps: {$inserted} upserted.");
    }

    // ── Vehicle Makes & Models ────────────────────────────────────────────────

    private function seedVehicleMakesAndModels(): void
    {
        $makes = [
            'Toyota'     => ['Hilux', 'Fortuner', 'Land Cruiser', 'Quantum', 'Corolla', 'RAV4', 'Prado', 'Hiace'],
            'Ford'       => ['Ranger', 'Everest', 'Transit', 'Tourneo', 'Explorer'],
            'Nissan'     => ['NP300 Hardbody', 'Navara', 'X-Trail', 'Patrol', 'Urvan'],
            'Volkswagen' => ['Amarok', 'Caddy', 'Polo', 'Transporter', 'Crafter', 'Touareg'],
            'Isuzu'      => ['D-Max', 'MU-X', 'F-Series', 'N-Series'],
            'Hyundai'    => ['H-1', 'Tucson', 'H100', 'Santa Fe', 'Staria'],
            'Mercedes'   => ['Sprinter', 'Vito', 'Actros', 'GLE', 'C-Class'],
            'BMW'        => ['3 Series', '5 Series', 'X3', 'X5', '7 Series'],
            'Mitsubishi' => ['Triton', 'Pajero', 'Outlander', 'Eclipse Cross'],
            'Mahindra'   => ['Scorpio', 'Bolero', 'TUV 300', 'XUV 500'],
            'Kia'        => ['Sorento', 'Sportage', 'Stinger', 'Carnival'],
            'Mazda'      => ['BT-50', 'CX-5', 'CX-9', 'Mazda6'],
        ];

        $makeCount  = 0;
        $modelCount = 0;

        foreach ($makes as $makeName => $models) {
            $makeId = DB::table('fleet_vehicle_makes')
                ->where('name', $makeName)
                ->value('id');

            if (!$makeId) {
                $makeId = DB::table('fleet_vehicle_makes')->insertGetId([
                    'name'       => $makeName,
                    'active'     => true,
                    'created_by' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $makeCount++;
            }

            foreach ($models as $modelName) {
                $exists = DB::table('fleet_vehicle_models')
                    ->where('make_id', $makeId)
                    ->where('name', $modelName)
                    ->exists();

                if (!$exists) {
                    DB::table('fleet_vehicle_models')->insert([
                        'make_id'    => $makeId,
                        'name'       => $modelName,
                        'active'     => true,
                        'created_by' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $modelCount++;
                }
            }
        }

        $this->command->info("Vehicle makes: {$makeCount} added. Models: {$modelCount} added.");
    }

    // ── Vehicle Types ─────────────────────────────────────────────────────────

    private function seedVehicleTypes(): void
    {
        $types = ['Sedan', 'Hatchback', 'SUV', 'Bakkie / Pickup', 'Minibus', 'Minivan', 'Truck', 'Van', 'Bus', 'Motorcycle', 'Other'];

        $count = 0;
        foreach ($types as $name) {
            $exists = DB::table('fleet_vehicle_types')->where('name', $name)->exists();
            if (!$exists) {
                DB::table('fleet_vehicle_types')->insert([
                    'name'       => $name,
                    'active'     => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Vehicle types: {$count} added.");
    }

    // ── Fuel Types ────────────────────────────────────────────────────────────

    private function seedFuelTypes(): void
    {
        $types = ['Petrol 93', 'Petrol 95', 'Diesel 50ppm', 'Diesel 500ppm', 'Hybrid', 'Electric', 'LPG', 'CNG'];

        $count = 0;
        foreach ($types as $name) {
            $exists = DB::table('fleet_fuel_types')->where('name', $name)->exists();
            if (!$exists) {
                DB::table('fleet_fuel_types')->insert([
                    'name'       => $name,
                    'active'     => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Fuel types: {$count} added.");
    }

    // ── Licence Types ─────────────────────────────────────────────────────────

    private function seedLicenceTypes(): void
    {
        $types = [
            ['code' => 'A',   'description' => 'Motorcycle — any engine capacity'],
            ['code' => 'A1',  'description' => 'Motorcycle — up to 125cc'],
            ['code' => 'B',   'description' => 'Light motor vehicle — up to 3 500 kg GVM'],
            ['code' => 'C',   'description' => 'Heavy motor vehicle — over 16 000 kg GVM'],
            ['code' => 'C1',  'description' => 'Motor vehicle — 3 500–16 000 kg GVM'],
            ['code' => 'EB',  'description' => 'Light motor vehicle with trailer — up to 3 500 kg GVM'],
            ['code' => 'EC',  'description' => 'Heavy motor vehicle with trailer — over 16 000 kg GVM'],
            ['code' => 'EC1', 'description' => 'Motor vehicle with trailer — 3 500–16 000 kg GVM'],
        ];

        $count = 0;
        foreach ($types as $type) {
            $exists = DB::table('fleet_licence_types')->where('name', $type['code'])->exists();
            if (!$exists) {
                DB::table('fleet_licence_types')->insert([
                    'name'       => $type['code'],
                    'active'     => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Licence types: {$count} added.");
    }

    // ── Key Safes ─────────────────────────────────────────────────────────────

    private function seedKeySafes(): void
    {
        $safes = [
            ['name' => 'Head Office Key Safe',       'location_description' => 'Reception — Ground Floor, Main Building'],
            ['name' => 'Fleet Workshop Safe',         'location_description' => 'Fleet Workshop — Key Cabinet on North Wall'],
            ['name' => 'Security Control Room Safe',  'location_description' => 'Security Gate — Control Room Cabinet'],
            ['name' => 'Branch Office Safe',          'location_description' => 'Branch Reception Desk — Locked Cabinet'],
            ['name' => 'Operations Centre Safe',      'location_description' => 'Operations Centre — Room 101 Key Board'],
        ];

        $count = 0;
        foreach ($safes as $safe) {
            $exists = DB::table('fleet_key_safes')->where('name', $safe['name'])->exists();
            if (!$exists) {
                DB::table('fleet_key_safes')->insert([
                    'name'                 => $safe['name'],
                    'location_description' => $safe['location_description'],
                    'active'               => true,
                    'created_by'           => 1,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Key safes: {$count} added.");
    }

    // ── Filling Stations ──────────────────────────────────────────────────────

    private function seedFillingStations(): void
    {
        $stations = [
            ['name' => 'Engen Johannesburg CBD',      'location' => 'Corner Commissioner & Rissik St, Johannesburg',          'fuel_types' => ['Petrol 93', 'Petrol 95', 'Diesel 50ppm']],
            ['name' => 'BP Sandton City',             'location' => 'Sandton City Shopping Centre, Sandton',                   'fuel_types' => ['Petrol 95', 'Diesel 50ppm']],
            ['name' => 'Shell Pretoria North',         'location' => 'Zambezi Drive, Montana, Pretoria',                       'fuel_types' => ['Petrol 93', 'Petrol 95', 'Diesel 50ppm', 'Diesel 500ppm']],
            ['name' => 'Sasol Cape Town N1',          'location' => 'N1 Freeway Northbound, Bellville, Cape Town',            'fuel_types' => ['Petrol 95', 'Diesel 50ppm']],
            ['name' => 'Caltex Durban Harbour',       'location' => 'Bayhead Road, Bluff, Durban',                            'fuel_types' => ['Petrol 93', 'Diesel 50ppm', 'Diesel 500ppm']],
            ['name' => 'Total Midrand',               'location' => 'New Road, Halfway House, Midrand',                       'fuel_types' => ['Petrol 93', 'Petrol 95', 'Diesel 50ppm']],
            ['name' => 'Engen Bloemfontein HQ',       'location' => 'Nelson Mandela Drive, Bloemfontein',                     'fuel_types' => ['Petrol 93', 'Petrol 95', 'Diesel 50ppm']],
        ];

        $count = 0;
        foreach ($stations as $station) {
            $exists = DB::table('fleet_filling_stations')->where('name', $station['name'])->exists();
            if (!$exists) {
                DB::table('fleet_filling_stations')->insert([
                    'name'           => $station['name'],
                    'location'       => $station['location'],
                    'fuel_types'     => json_encode($station['fuel_types']),
                    'active'         => true,
                    'created_by'     => 1,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Filling stations: {$count} added.");
    }

    // ── Card Types ────────────────────────────────────────────────────────────

    private function seedCardTypes(): void
    {
        $types = ['Fuel Card', 'Toll Card', 'Fleet Credit Card', 'Maintenance Card', 'Multi-purpose Card'];

        $count = 0;
        foreach ($types as $name) {
            $exists = DB::table('fleet_card_types')->where('name', $name)->exists();
            if (!$exists) {
                DB::table('fleet_card_types')->insert([
                    'name'       => $name,
                    'active'     => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Card types: {$count} added.");
    }

    // ── Document Suppliers ────────────────────────────────────────────────────

    private function seedDocumentSuppliers(): void
    {
        $suppliers = [
            'DLTC — Driving Licence Testing Centre',
            'NATIS — National Traffic Information System',
            'RTIA — Road Traffic Infringement Agency',
            'MiWay Insurance',
            'Old Mutual Insure',
            'Santam Commercial',
            'Hollard Fleet Insurance',
            'AA South Africa',
            'VW Financial Services',
            'WesBank Fleet',
            'Nedbank Vehicle Finance',
        ];

        $count = 0;
        foreach ($suppliers as $name) {
            $exists = DB::table('fleet_document_suppliers')->where('name', $name)->exists();
            if (!$exists) {
                DB::table('fleet_document_suppliers')->insert([
                    'name'       => $name,
                    'active'     => true,
                    'created_by' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Document suppliers: {$count} added.");
    }

    // ── Incident Types ────────────────────────────────────────────────────────

    private function seedIncidentTypes(): void
    {
        $types = [
            'Accident — Third Party', 'Accident — Own Damage', 'Breakdown',
            'Tyre Puncture', 'Fuel Shortage', 'Theft — Vehicle',
            'Theft — Contents', 'Vandalism', 'Weather Damage', 'Other',
        ];

        $count = 0;
        foreach ($types as $name) {
            $exists = DB::table('fleet_incident_types')->where('name', $name)->exists();
            if (!$exists) {
                DB::table('fleet_incident_types')->insert([
                    'name'       => $name,
                    'active'     => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Incident types: {$count} added.");
    }

    // ── Projects / Cost Centres ───────────────────────────────────────────────

    private function seedProjects(): void
    {
        $projects = [
            ['name' => 'General Operations',      'description' => 'Day-to-day operational trips not assigned to a specific project'],
            ['name' => 'Executive Travel',         'description' => 'Executive and senior management official travel'],
            ['name' => 'Client Site Visits',       'description' => 'Trips to client sites and external meetings'],
            ['name' => 'Government Liaison',       'description' => 'Engagements with government departments and regulators'],
            ['name' => 'Audit & Compliance',       'description' => 'Internal and external audit visits'],
            ['name' => 'Training & Development',   'description' => 'Staff training, workshops and conferences'],
            ['name' => 'Emergency Response',       'description' => 'Emergency and after-hours trips'],
            ['name' => 'Maintenance & Repairs',    'description' => 'Trips for vehicle or facility maintenance'],
            ['name' => 'Procurement & Delivery',   'description' => 'Supplier visits, procurement runs and deliveries'],
            ['name' => 'HR & Recruitment',         'description' => 'Interview, on-boarding and HR-related travel'],
        ];

        $count = 0;
        foreach ($projects as $project) {
            $exists = DB::table('fleet_projects')->where('name', $project['name'])->exists();
            if (!$exists) {
                DB::table('fleet_projects')->insert([
                    'name'        => $project['name'],
                    'description' => $project['description'],
                    'active'      => true,
                    'created_by'  => 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $count++;
            }
        }

        $this->command->info("Projects: {$count} added.");
    }
}
