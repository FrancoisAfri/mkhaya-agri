<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FleetDemoSeeder extends Seeder
{
    private array $vehicleIds   = [];
    private array $driverIds    = [];
    private array $bookingIds   = [];
    private array $cardIds      = [];
    private ?int  $adminId      = null;

    public function run(): void
    {
        // Resolved rather than assumed. User 1 exists on a database that grew from the original
        // seed, but not on one built by `migrate:fresh --seed`, where the sequence starts wherever
        // the user seeder left it — and fleet_fines.created_by is a real foreign key, so the whole
        // seed died on the last step with a constraint violation. Null is acceptable: the column is
        // nullable and ON DELETE SET NULL, so demo data without an author is still valid demo data.
        $this->adminId = DB::table('users')->min('id');

        // Order matters: vehicles → drivers → cards → bookings (toll/incidents inside) → fuel → maintenance → fines
        $this->seedVehicles();
        $this->seedDrivers();
        $this->seedCards();
        $this->seedBookings();
        $this->seedFuelLogs();
        $this->seedMaintenance();
        $this->seedFines();

        $this->command->info('Fleet demo data seeded successfully.');
    }

    // ── Vehicles ──────────────────────────────────────────────────────────────

    private function seedVehicles(): void
    {
        $fleet = [
            ['make'=>'Toyota',   'model'=>'Hilux GD6',    'year'=>2022,'type'=>'truck',  'fuel'=>'diesel',  'reg'=>'GP12ABCD','colour'=>'White',  'seats'=>5,'status'=>'available'],
            ['make'=>'Toyota',   'model'=>'Fortuner 2.8', 'year'=>2021,'type'=>'suv',    'fuel'=>'diesel',  'reg'=>'GP34EFGH','colour'=>'Silver', 'seats'=>7,'status'=>'available'],
            ['make'=>'Toyota',   'model'=>'Quantum 2.5',  'year'=>2020,'type'=>'van',    'fuel'=>'diesel',  'reg'=>'GP56IJKL','colour'=>'White',  'seats'=>14,'status'=>'booked'],
            ['make'=>'Ford',     'model'=>'Ranger XLT',   'year'=>2023,'type'=>'truck',  'fuel'=>'diesel',  'reg'=>'WC11MNOP','colour'=>'Blue',   'seats'=>5,'status'=>'available'],
            ['make'=>'Ford',     'model'=>'Everest Sport', 'year'=>2022,'type'=>'suv',   'fuel'=>'diesel',  'reg'=>'WC22QRST','colour'=>'Black',  'seats'=>7,'status'=>'maintenance'],
            ['make'=>'VW',       'model'=>'Polo Vivo',    'year'=>2021,'type'=>'sedan',  'fuel'=>'petrol',  'reg'=>'GP78UVWX','colour'=>'Red',    'seats'=>5,'status'=>'available'],
            ['make'=>'VW',       'model'=>'Caddy 2.0 TDI','year'=>2022,'type'=>'van',   'fuel'=>'diesel',  'reg'=>'GP90YYZZ','colour'=>'White',  'seats'=>5,'status'=>'available'],
            ['make'=>'Nissan',   'model'=>'NP300 Hardbody','year'=>2020,'type'=>'truck', 'fuel'=>'diesel',  'reg'=>'NW33AABB','colour'=>'White',  'seats'=>3,'status'=>'available'],
            ['make'=>'Nissan',   'model'=>'X-Trail 2.5',  'year'=>2021,'type'=>'suv',   'fuel'=>'petrol',  'reg'=>'GT44CCDD','colour'=>'Grey',   'seats'=>5,'status'=>'booked'],
            ['make'=>'Hyundai',  'model'=>'H-1 2.5 CRDi', 'year'=>2019,'type'=>'van',   'fuel'=>'diesel',  'reg'=>'FS55EEFF','colour'=>'White',  'seats'=>9,'status'=>'available'],
            ['make'=>'Mercedes', 'model'=>'Sprinter 311', 'year'=>2020,'type'=>'van',    'fuel'=>'diesel',  'reg'=>'GP66GGHH','colour'=>'White',  'seats'=>15,'status'=>'offroad'],
            ['make'=>'BMW',      'model'=>'3 Series 320i', 'year'=>2022,'type'=>'sedan', 'fuel'=>'petrol',  'reg'=>'GP77IIJJ','colour'=>'Black',  'seats'=>5,'status'=>'available'],
            ['make'=>'Toyota',   'model'=>'Corolla Quest', 'year'=>2021,'type'=>'sedan', 'fuel'=>'petrol',  'reg'=>'EC88KKLL','colour'=>'Silver', 'seats'=>5,'status'=>'available'],
            ['make'=>'Ford',     'model'=>'Transit 2.2',  'year'=>2019,'type'=>'van',    'fuel'=>'diesel',  'reg'=>'KZN99MMNN','colour'=>'White', 'seats'=>9,'status'=>'available'],
            ['make'=>'Isuzu',    'model'=>'D-Max 3.0',    'year'=>2023,'type'=>'truck',  'fuel'=>'diesel',  'reg'=>'LP10OOPP','colour'=>'Blue',   'seats'=>5,'status'=>'available'],
        ];

        foreach ($fleet as $v) {
            $id = DB::table('fleet_vehicles')->insertGetId([
                'uuid'                => Str::uuid(),
                'registration_number' => $v['reg'],
                'make'                => $v['make'],
                'model'               => $v['model'],
                'year'                => $v['year'],
                'vehicle_type'        => $v['type'],
                'fuel_type'           => $v['fuel'],
                'colour'              => $v['colour'],
                'seating_capacity'    => $v['seats'],
                'status'              => $v['status'],
                'odometer_reading'    => rand(15000, 120000),
                'purchase_date'       => now()->subYears(rand(1, 5))->toDateString(),
                'purchase_price'      => rand(250000, 800000),
                'current_value'       => rand(150000, 600000),
                'location'            => collect(['Johannesburg','Cape Town','Durban','Pretoria','Sandton'])->random(),
                'approval_status'     => 'approved',
                'created_by'          => $this->adminId,
                'updated_by'          => $this->adminId,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
            $this->vehicleIds[] = $id;
        }

        $this->command->info('Seeded ' . count($this->vehicleIds) . ' vehicles.');
    }

    // ── Drivers (use existing users) ──────────────────────────────────────────

    private function seedDrivers(): void
    {
        $userIds = DB::table('users')->pluck('id')->toArray();
        $licenceTypes = ['B','C','C1','EB'];

        foreach ($userIds as $userId) {
            // skip if already a driver
            if (DB::table('fleet_drivers')->where('user_id', $userId)->exists()) continue;

            $id = DB::table('fleet_drivers')->insertGetId([
                'uuid'           => Str::uuid(),
                'user_id'        => $userId,
                'licence_number' => strtoupper(Str::random(2)) . rand(100000, 999999),
                'licence_type'   => collect($licenceTypes)->random(),
                'licence_expiry' => now()->addYears(rand(1, 5))->toDateString(),
                'status'         => 'active',
                'total_trips'    => 0,
                'total_km'       => 0,
                'incidents_count'=> 0,
                'created_by'     => $this->adminId,
                'updated_by'     => $this->adminId,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            $this->driverIds[] = $id;
        }

        $this->command->info('Seeded ' . count($this->driverIds) . ' drivers.');
    }

    // ── Fleet cards ───────────────────────────────────────────────────────────

    private function seedCards(): void
    {
        foreach (array_slice($this->vehicleIds, 0, 10) as $vId) {
            $id = DB::table('fleet_cards')->insertGetId([
                'uuid'                => Str::uuid(),
                'vehicle_id'          => $vId,
                'card_number_last4'   => str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
                'card_token'          => Str::random(32),
                'card_type'           => 'fuel',
                'provider'            => collect(['FleetCard','WEX','Engen'])->random(),
                'expiry_month'        => rand(1, 12),
                'expiry_year'         => rand(2025, 2028),
                'daily_limit'         => rand(1000, 3000),
                'monthly_limit'       => rand(5000, 15000),
                'status'              => 'active',
                'created_by'          => $this->adminId,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
            $this->cardIds[] = $id;
        }
    }

    // ── Bookings (12 months, returned + some cancelled/pending) ──────────────

    private function seedBookings(): void
    {
        $destinations = [
            'Johannesburg CBD','Pretoria North','Sandton Convention Centre',
            'Cape Town Airport','Durban Harbour','Midrand Office Park',
            'Polokwane Admin','Nelspruit Client Site','East London Branch',
            'Port Elizabeth Depot','Bloemfontein HQ','Kimberley Field',
        ];
        $purposes = [
            'Client meeting','Site inspection','Delivery run','Training session',
            'Government liaison','Maintenance collection','Staff transport',
            'Emergency response','Project audit','Supplier visit',
        ];
        $states = ['returned','returned','returned','returned','cancelled','pending','security_out'];

        $created = 0;
        for ($monthOffset = 11; $monthOffset >= 0; $monthOffset--) {
            $monthStart = now()->subMonths($monthOffset)->startOfMonth();
            $bookingsThisMonth = rand(12, 25);

            for ($i = 0; $i < $bookingsThisMonth; $i++) {
                $vehicleId = collect($this->vehicleIds)->random();
                $driverId  = collect($this->driverIds)->random();
                $userId    = DB::table('fleet_drivers')->where('id', $driverId)->value('user_id');
                $departure = $monthStart->copy()->addDays(rand(0, 27))->setHour(rand(6,15));
                $state     = collect($states)->random();
                $kmTravel  = $state === 'returned' ? rand(30, 800) : null;
                $duration  = rand(1, 6); // hours
                $returnAt  = $departure->copy()->addHours($duration + rand(0, 4));

                $bookingId = DB::table('fleet_bookings')->insertGetId([
                    'uuid'              => Str::uuid(),
                    'reference'         => 'BK-' . strtoupper(Str::random(8)),
                    'vehicle_id'        => $vehicleId,
                    'driver_id'         => $driverId,
                    'requester_id'      => $userId,
                    'purpose'           => collect($purposes)->random(),
                    'destination'       => collect($destinations)->random(),
                    'departure_at'      => $departure,
                    'expected_return_at'=> $returnAt,
                    'actual_return_at'  => $state === 'returned' ? $returnAt->copy()->addMinutes(rand(-30, 60)) : null,
                    'start_odometer'    => $startOdo = rand(20000, 150000),
                    'end_odometer'      => $kmTravel ? $startOdo + $kmTravel : null,
                    'km_travelled'      => $kmTravel,
                    'workflow_state'    => $state,
                    'approved_by'       => $this->adminId,
                    'approved_at'       => $departure->copy()->subHours(2),
                    'created_by'        => $userId,
                    'updated_by'        => $userId,
                    'created_at'        => $departure->copy()->subDays(rand(1, 5)),
                    'updated_at'        => $departure,
                ]);

                $this->bookingIds[] = [
                    'id'         => $bookingId,
                    'vehicle_id' => $vehicleId,
                    'driver_id'  => $driverId,
                    'state'      => $state,
                    'departure'  => $departure,
                    'km'         => $kmTravel,
                ];

                // Tollgates on ~30% of returned bookings
                if ($state === 'returned' && rand(1, 10) <= 3) {
                    $tolls = rand(1, 3);
                    for ($t = 0; $t < $tolls; $t++) {
                        DB::table('fleet_booking_tollgates')->insert([
                            'booking_id'   => $bookingId,
                            'vehicle_id'   => $vehicleId,
                            'amount'       => round(rand(15, 120) + (rand(0,99)/100), 2),
                            'toll_location'=> collect(['N1 Bela-Bela','N3 Heidelberg','N4 Bakwena','N14 Krugersdorp'])->random(),
                            'toll_at'      => $departure->copy()->addHours(rand(1, 3)),
                            'created_by'   => $userId ?? $this->adminId,
                            'created_at'   => $departure,
                            'updated_at'   => $departure,
                        ]);
                    }
                }

                // Incidents on ~5% of returned bookings
                if ($state === 'returned' && rand(1, 20) === 1) {
                    DB::table('fleet_booking_incidents')->insert([
                        'booking_id'   => $bookingId,
                        'vehicle_id'   => $vehicleId,
                        'incident_type'=> collect(['accident','breakdown','damage','other'])->random(),
                        'severity'     => collect(['low','medium','high'])->random(),
                        'description'  => 'Demo incident during trip to ' . collect($destinations)->random(),
                        'incident_at'  => $departure->copy()->addHours(rand(1, 4)),
                        'location'     => collect($destinations)->random(),
                        'evidence_paths'=> '[]',
                        'created_by'   => $userId ?? $this->adminId,
                        'created_at'   => $departure,
                        'updated_at'   => $departure,
                    ]);
                }

                $created++;
            }
        }

        $this->command->info("Seeded {$created} bookings.");
    }

    // ── Fuel logs (monthly fill-ups per vehicle) ──────────────────────────────

    private function seedFuelLogs(): void
    {
        $fuelPriceByType = ['diesel' => 22.50, 'petrol' => 23.80, 'hybrid' => 18.00];
        $consumptionByType = [
            'truck'  => [10.5, 14.0],
            'suv'    => [8.5,  12.5],
            'van'    => [9.0,  13.0],
            'sedan'  => [6.5,  9.5],
            'other'  => [8.0,  12.0],
        ];

        $count = 0;
        foreach ($this->vehicleIds as $vId) {
            $vehicle = DB::table('fleet_vehicles')->where('id', $vId)->first();
            $range   = $consumptionByType[$vehicle->vehicle_type] ?? [8.0, 12.0];
            $pricePerL = $fuelPriceByType[$vehicle->fuel_type] ?? 22.50;

            for ($monthOffset = 11; $monthOffset >= 0; $monthOffset--) {
                $fillsThisMonth = rand(2, 5);
                $monthStart = now()->subMonths($monthOffset)->startOfMonth();

                for ($f = 0; $f < $fillsThisMonth; $f++) {
                    $litres      = rand(30, 70) + rand(0, 99) / 100;
                    $consumption = round($range[0] + (mt_rand() / mt_getrandmax()) * ($range[1] - $range[0]), 2);
                    $price       = round($pricePerL + (rand(-100, 100) / 100), 4); // price variation
                    $driverId    = count($this->driverIds) ? collect($this->driverIds)->random() : null;

                    DB::table('fleet_fuel_logs')->insert([
                        'vehicle_id'            => $vId,
                        'driver_id'             => $driverId,
                        'filled_at'             => $monthStart->copy()->addDays(rand(0, 27))->setHour(rand(7, 18)),
                        'fuel_type'             => $vehicle->fuel_type,
                        'litres'                => round($litres, 3),
                        'cost_per_litre'        => $price,
                        'total_cost'            => round($litres * $price, 2),
                        'odometer_reading'      => rand(20000, 180000),
                        'station_name'          => collect(['Engen','BP','Shell','Sasol','Caltex'])->random(),
                        'station_city'          => collect(['Johannesburg','Pretoria','Sandton','Cape Town','Durban'])->random(),
                        'full_tank'             => true,
                        'consumption_per_100km' => $consumption,
                        'created_by'            => $this->adminId,
                        'updated_by'            => $this->adminId,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]);
                    $count++;
                }
            }
        }

        $this->command->info("Seeded {$count} fuel log entries.");
    }

    // ── Maintenance records ───────────────────────────────────────────────────

    private function seedMaintenance(): void
    {
        $categories = ['oil_change','tyres','brakes','electrical','bodywork','other'];
        $types      = ['scheduled','unscheduled','emergency','inspection'];
        $statuses   = ['completed','completed','completed','scheduled','in_progress','cancelled'];
        $providers  = ['AutoZone Workshop','Midas Service Centre','Dealer Workshop','QuickFix Auto','Total Auto Services'];

        $count = 0;
        foreach ($this->vehicleIds as $vId) {
            $records = rand(2, 8);
            for ($r = 0; $r < $records; $r++) {
                $cat        = collect($categories)->random();
                $type       = collect($types)->random();
                $status     = collect($statuses)->random();
                $date       = now()->subMonths(rand(0, 11))->subDays(rand(0, 25));
                $cost       = rand(500, 15000) + rand(0, 99) / 100;
                $labour     = $cost * 0.4;
                $parts      = $cost * 0.6;

                DB::table('fleet_maintenance')->insert([
                    'uuid'               => Str::uuid(),
                    'vehicle_id'         => $vId,
                    'maintenance_type'   => $type,
                    'category'           => $cat,
                    'description'        => ucfirst($cat) . ' service — ' . $type,
                    'scheduled_date'     => $date->toDateString(),
                    'completed_date'     => $status === 'completed' ? $date->copy()->addDays(rand(0, 3))->toDateString() : null,
                    'odometer_at_service'=> rand(15000, 180000),
                    'next_service_km'    => rand(10000, 20000),
                    'next_service_date'  => $date->copy()->addMonths(6)->toDateString(),
                    'service_provider'   => collect($providers)->random(),
                    'cost'               => round($cost, 2),
                    'labour_cost'        => round($labour, 2),
                    'parts_cost'         => round($parts, 2),
                    'invoice_number'     => 'INV-' . rand(10000, 99999),
                    'status'             => $status,
                    'priority'           => collect(['low','normal','high'])->random(),
                    'created_by'         => $this->adminId,
                    'updated_by'         => $this->adminId,
                    'created_at'         => $date,
                    'updated_at'         => $date,
                ]);
                $count++;
            }
        }

        $this->command->info("Seeded {$count} maintenance records.");
    }

    // ── Traffic fines ─────────────────────────────────────────────────────────

    private function seedFines(): void
    {
        $authorities = ['JMPD','CTMPD','SAPS Traffic','RTIA/AARTO','eThekwini Metro','Tshwane Metro'];
        $offences    = [
            ['code'=>'S56(1)(a)', 'desc'=>'Exceeding speed limit by 1-30 km/h'],
            ['code'=>'S56(1)(b)', 'desc'=>'Exceeding speed limit by 31-50 km/h'],
            ['code'=>'S56(1)(c)', 'desc'=>'Exceeding speed limit by more than 50 km/h'],
            ['code'=>'S63(1)',    'desc'=>'Failure to stop at red traffic signal'],
            ['code'=>'S35(1)',    'desc'=>'Failure to wear seatbelt'],
            ['code'=>'S58(1)',    'desc'=>'Using mobile phone while driving'],
            ['code'=>'S61(1)',    'desc'=>'Reckless or negligent driving'],
            ['code'=>'S50(1)',    'desc'=>'Driving an unroadworthy vehicle'],
        ];
        $statuses    = ['outstanding','outstanding','outstanding','paid','disputed','written_off'];
        $providers   = ['intellicheck','digitraffic','paab','manual'];
        $locations   = ['N1 Highway Northbound','M1 De Villiers Graaff','William Nicol Drive','Hans Strijdom','N3 Johannesburg Bypass'];

        $count = 0;
        foreach ($this->vehicleIds as $vId) {
            $fines = rand(0, 5);
            for ($f = 0; $f < $fines; $f++) {
                $offence    = collect($offences)->random();
                $authority  = collect($authorities)->random();
                $status     = collect($statuses)->random();
                $amount     = rand(250, 5000) + rand(0, 99) / 100;
                $offenceAt  = now()->subMonths(rand(0, 10))->subDays(rand(0, 28));
                $provider   = collect($providers)->random();
                $driverId   = count($this->driverIds) ? collect($this->driverIds)->random() : null;

                // try to link a booking at that date
                $booking = null;
                if ($this->bookingIds) {
                    foreach ($this->bookingIds as $b) {
                        if ($b['vehicle_id'] == $vId && $b['state'] === 'returned') {
                            $dep = $b['departure'];
                            if (abs($dep->diffInHours($offenceAt)) < 12) {
                                $booking = $b;
                                $driverId = $b['driver_id'];
                                break;
                            }
                        }
                    }
                }

                DB::table('fleet_fines')->insert([
                    'vehicle_id'          => $vId,
                    'driver_id'           => $driverId,
                    'booking_id'          => $booking['id'] ?? null,
                    'provider'            => $provider,
                    'provider_fine_id'    => strtoupper(Str::random(12)),
                    'fine_number'         => 'FN-' . rand(1000000, 9999999),
                    'offence_code'        => $offence['code'],
                    'offence_description' => $offence['desc'],
                    'offence_date'        => $offenceAt,
                    'location'            => collect($locations)->random(),
                    'amount'              => round($amount, 2),
                    'outstanding_amount'  => in_array($status, ['outstanding','disputed']) ? round($amount, 2) : 0,
                    'issuing_authority'   => $authority,
                    'status'              => $status,
                    'due_date'            => $offenceAt->copy()->addDays(32)->toDateString(),
                    'aarto_points'        => rand(0, 6),
                    'paid_at'             => $status === 'paid' ? $offenceAt->copy()->addDays(rand(10, 30)) : null,
                    'created_by'          => $this->adminId,
                    'updated_by'          => $this->adminId,
                    'created_at'          => $offenceAt,
                    'updated_at'          => $offenceAt,
                ]);
                $count++;
            }
        }

        $this->command->info("Seeded {$count} traffic fines.");
    }
}
