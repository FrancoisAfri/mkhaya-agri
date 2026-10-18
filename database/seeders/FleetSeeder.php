<?php

namespace Database\Seeders;

use App\Core\User\Models\User;
use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetCard;
use App\Modules\Fleet\Models\FleetDocument;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetFuelLog;
use App\Modules\Fleet\Models\FleetMaintenance;
use App\Modules\Fleet\Models\FleetVehicle;
use Database\Factories\Fleet\FleetBookingFactory;
use Database\Factories\Fleet\FleetCardFactory;
use Database\Factories\Fleet\FleetDocumentFactory;
use Database\Factories\Fleet\FleetDriverFactory;
use Database\Factories\Fleet\FleetFuelLogFactory;
use Database\Factories\Fleet\FleetMaintenanceFactory;
use Database\Factories\Fleet\FleetVehicleFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FleetSeeder extends Seeder
{
    public function run(): void
    {
        // ── Vehicles ──────────────────────────────────────────────────────────
        $vehicles = collect([
            FleetVehicleFactory::new()->diesel()->create([
                'registration_number' => 'FT-AA-001-GP',
                'fleet_number'        => 'FLT-0001',
                'make'                => 'Toyota',
                'model'               => 'Hilux',
                'year'                => 2022,
                'status'              => 'available',
            ]),
            FleetVehicleFactory::new()->petrol()->create([
                'registration_number' => 'FT-BB-002-GP',
                'fleet_number'        => 'FLT-0002',
                'make'                => 'Ford',
                'model'               => 'Ranger',
                'year'                => 2021,
                'status'              => 'available',
            ]),
            FleetVehicleFactory::new()->create([
                'registration_number' => 'FT-CC-003-GP',
                'fleet_number'        => 'FLT-0003',
                'make'                => 'Volkswagen',
                'model'               => 'Polo',
                'year'                => 2020,
                'status'              => 'maintenance',
            ]),
        ]);

        $randomVehicles = FleetVehicleFactory::new()->available()->count(5)->create();
        FleetVehicleFactory::new()->disposed()->count(2)->create();

        $allActiveVehicles = $vehicles->merge($randomVehicles);

        // ── Drivers ───────────────────────────────────────────────────────────
        $drivers = collect();
        User::take(3)->each(function ($user) use (&$drivers) {
            $driver = FleetDriverFactory::new()->create(['user_id' => $user->id]);
            $drivers->push($driver);
        });

        if ($drivers->count() < 3) {
            $extra = FleetDriverFactory::new()->count(3 - $drivers->count())->create();
            $drivers = $drivers->merge($extra);
        }

        // ── Documents ─────────────────────────────────────────────────────────
        foreach ($vehicles as $vehicle) {
            FleetDocumentFactory::new()->insurance()->create(['vehicle_id' => $vehicle->id]);
            FleetDocumentFactory::new()->licenceDisk()->create(['vehicle_id' => $vehicle->id]);
            FleetDocumentFactory::new()->expiringSoon(20)->create(['vehicle_id' => $vehicle->id]);
        }

        // ── Maintenance ───────────────────────────────────────────────────────
        FleetMaintenanceFactory::new()->completed()->count(3)->create(['vehicle_id' => $vehicles->first()->id]);
        FleetMaintenanceFactory::new()->overdue()->count(2)->create(['vehicle_id' => $vehicles->get(1)->id]);
        FleetMaintenanceFactory::new()->emergency()->count(1)->create(['vehicle_id' => $vehicles->get(2)->id]);

        // ── Fleet Cards ───────────────────────────────────────────────────────
        $cards = collect();
        foreach ($vehicles->take(2) as $vehicle) {
            $card = FleetCardFactory::new()->fuelCard()->create(['vehicle_id' => $vehicle->id]);
            $cards->push($card);
        }

        // ── Fuel Logs (all active vehicles, ~12 fills over 6 months) ─────────
        $fuelTypes    = ['petrol', 'diesel'];
        $stations     = ['Shell', 'BP', 'Sasol', 'Engen', 'Total', 'Astron'];
        $stationCities = ['Johannesburg', 'Pretoria', 'Sandton', 'Midrand', 'Centurion', 'Cape Town', 'Durban'];

        foreach ($allActiveVehicles as $vehicle) {
            $odometer = fake()->numberBetween(20000, 60000);
            for ($i = 12; $i >= 1; $i--) {
                $litres       = fake()->randomFloat(2, 30, 75);
                $costPerLitre = fake()->randomFloat(4, 21.50, 26.00);
                $kmSinceLast  = fake()->numberBetween(300, 900);
                $odometer    += $kmSinceLast;

                DB::table('fleet_fuel_logs')->insert([
                    'vehicle_id'            => $vehicle->id,
                    'driver_id'             => $drivers->random()->id,
                    'fleet_card_id'         => null,
                    'filled_at'             => now()->subWeeks($i * 2)->subDays(fake()->numberBetween(0, 3)),
                    'fuel_type'             => $vehicle->fuel_type ?? fake()->randomElement($fuelTypes),
                    'litres'                => $litres,
                    'cost_per_litre'        => $costPerLitre,
                    'total_cost'            => round($litres * $costPerLitre, 2),
                    'odometer_reading'      => $odometer,
                    'station_name'          => fake()->randomElement($stations),
                    'station_city'          => fake()->randomElement($stationCities),
                    'full_tank'             => true,
                    'consumption_per_100km' => fake()->randomFloat(2, 7.5, 14.0),
                    'notes'                 => null,
                    'created_by'            => null,
                    'created_at'            => now()->subWeeks($i * 2),
                    'updated_at'            => now()->subWeeks($i * 2),
                ]);
            }
        }

        // ── Bookings ──────────────────────────────────────────────────────────
        $returnedBookings = FleetBookingFactory::new()->returned()->count(8)->create([
            'vehicle_id' => $vehicles->first()->id,
            'driver_id'  => $drivers->first()?->id,
        ]);

        FleetBookingFactory::new()->returned()->count(4)->create([
            'vehicle_id' => $vehicles->get(1)->id,
            'driver_id'  => $drivers->get(1)?->id,
        ]);

        FleetBookingFactory::new()->approved()->count(2)->create([
            'vehicle_id' => $vehicles->get(1)->id,
        ]);

        FleetBookingFactory::new()->pending()->count(3)->create();

        // ── Fines ─────────────────────────────────────────────────────────────
        $authorities  = ['JMPD', 'CTMPD', 'SAPS', 'RTIA', 'EMPD', 'TSHWANE'];
        $offenceCodes = ['SP-001', 'SP-002', 'TR-010', 'TR-015', 'RR-003', 'RL-007', 'PK-001', 'MW-005'];
        $offenceDescs = [
            'Exceeding speed limit by more than 30km/h in an urban area',
            'Exceeding speed limit by 10–30km/h in a school zone',
            'Failing to stop at a red traffic signal',
            'Crossing a solid barrier line',
            'Driving without a valid roadworthy certificate',
            'Driving with defective brake lights',
            'Parking in a no-parking zone',
            'Using a mobile device while driving',
        ];
        $locations    = [
            'N1 Highway, Johannesburg', 'William Nicol Dr, Sandton', 'M1 South, Johannesburg',
            'R21 Pretoria', 'Soweto Highway', 'N2 Cape Town', 'M4 Durban North', 'Solomon Mahlangu Dr',
        ];
        $statuses = ['outstanding', 'outstanding', 'outstanding', 'paid', 'paid', 'disputed', 'written_off'];

        foreach ($allActiveVehicles as $vehicle) {
            $fineCount = fake()->numberBetween(2, 5);
            for ($i = 0; $i < $fineCount; $i++) {
                $amount      = fake()->randomElement([250, 500, 750, 1000, 1500, 2000, 3000]);
                $status      = fake()->randomElement($statuses);
                $offenceDate = now()->subDays(fake()->numberBetween(7, 365));
                $dueDate     = $offenceDate->copy()->addDays(32);

                DB::table('fleet_fines')->insert([
                    'vehicle_id'           => $vehicle->id,
                    'driver_id'            => $drivers->random()->id,
                    'booking_id'           => null,
                    'provider'             => 'manual',
                    'provider_fine_id'     => null,
                    'fine_number'          => 'F' . strtoupper(fake()->bothify('##??####')),
                    'offence_code'         => fake()->randomElement($offenceCodes),
                    'offence_description'  => fake()->randomElement($offenceDescs),
                    'offence_date'         => $offenceDate,
                    'location'             => fake()->randomElement($locations),
                    'amount'               => $amount,
                    'outstanding_amount'   => $status === 'paid' ? 0 : $amount,
                    'issuing_authority'    => fake()->randomElement($authorities),
                    'status'              => $status,
                    'dispute_reason'       => $status === 'disputed' ? 'Driver disputes the offence — dashcam footage submitted.' : null,
                    'dispute_date'         => $status === 'disputed' ? $offenceDate->copy()->addDays(10) : null,
                    'paid_at'              => $status === 'paid' ? $dueDate->copy()->subDays(fake()->numberBetween(1, 20)) : null,
                    'payment_reference'    => $status === 'paid' ? 'PAY-' . strtoupper(fake()->bothify('########')) : null,
                    'due_date'             => $dueDate->toDateString(),
                    'aarto_points'         => fake()->randomElement([0, 1, 2, 3, 4, 6]),
                    'notes'               => null,
                    'created_by'           => null,
                    'created_at'           => $offenceDate,
                    'updated_at'           => $offenceDate,
                ]);
            }
        }

        // ── Incidents (on returned bookings) ──────────────────────────────────
        $incidentTypes = ['accident', 'breakdown', 'damage', 'theft', 'other'];
        $severities    = ['low', 'low', 'medium', 'medium', 'high', 'critical'];
        $incidentDescs = [
            'accident'  => 'Minor rear-end collision at a traffic light. No injuries. Third-party vehicle involved.',
            'breakdown' => 'Engine overheated on the highway. Vehicle towed to nearest service centre.',
            'damage'    => 'Parking lot scrape on the front bumper and left door panel.',
            'theft'     => 'Vehicle window smashed and laptop bag stolen from the backseat while parked.',
            'other'     => 'Tyre blowout on the N1. Replaced with spare — spare tyre also worn.',
        ];

        $allBookings = $returnedBookings;
        $incidentBookings = $allBookings->random(min(5, $allBookings->count()));

        foreach ($incidentBookings as $booking) {
            $type = fake()->randomElement($incidentTypes);
            DB::table('fleet_booking_incidents')->insert([
                'booking_id'         => $booking->id,
                'vehicle_id'         => $booking->vehicle_id,
                'incident_type'      => $type,
                'severity'           => fake()->randomElement($severities),
                'description'        => $incidentDescs[$type],
                'incident_at'        => fake()->dateTimeBetween('-6 months', '-1 day'),
                'location'           => fake()->randomElement($locations),
                'police_case_number' => in_array($type, ['accident', 'theft']) ? 'CAS ' . fake()->numerify('######/##/####') : null,
                'evidence_paths'     => '[]',
                'created_by'         => null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

        }

        // ── Tollgates (on returned bookings) ──────────────────────────────────
        $tollLocations = [
            'Huguenot Toll Plaza (N1)', 'Petroria/Witbank (N4)', 'Modimolle Toll (N1 North)',
            'Grasmere Toll (N1 South)', 'Vaal Toll Plaza (N1)', 'N3 Mariannhill Toll',
        ];

        foreach ($allBookings->take(6) as $booking) {
            $tollCount = fake()->numberBetween(1, 3);
            for ($t = 0; $t < $tollCount; $t++) {
                DB::table('fleet_booking_tollgates')->insert([
                    'booking_id'   => $booking->id,
                    'vehicle_id'   => $booking->vehicle_id,
                    'amount'       => fake()->randomElement([17.00, 24.00, 30.50, 45.00, 52.00, 68.00]),
                    'toll_location'=> fake()->randomElement($tollLocations),
                    'toll_at'      => fake()->dateTimeBetween('-6 months', '-1 day'),
                    'receipt_path' => null,
                    'created_by'   => null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

        $fuelCount     = DB::table('fleet_fuel_logs')->count();
        $finesCount    = DB::table('fleet_fines')->count();
        $incidentCount = DB::table('fleet_booking_incidents')->count();
        $tollCount     = DB::table('fleet_booking_tollgates')->count();

        $this->command->info("Fleet seeded — {$fuelCount} fuel logs, {$finesCount} fines, {$incidentCount} incidents, {$tollCount} tollgates.");
    }
}
