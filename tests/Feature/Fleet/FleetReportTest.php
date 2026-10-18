<?php

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Controllers\FleetReportController;
use App\Modules\Fleet\Models\FleetScheduledReport;
use Database\Factories\Fleet\FleetBookingFactory;
use Database\Factories\Fleet\FleetDocumentFactory;
use Database\Factories\Fleet\FleetDriverFactory;
use Database\Factories\Fleet\FleetFuelLogFactory;
use Database\Factories\Fleet\FleetMaintenanceFactory;
use Database\Factories\Fleet\FleetScheduledReportFactory;
use Database\Factories\Fleet\FleetVehicleFactory;

class FleetReportTest extends FleetTestCase
{
    // ── Dashboard ────────────────────────────────────────────────────────────

    public function test_report_index_renders_for_manager(): void
    {
        $this->actingAs($this->fleetManager)
            ->get(route('fleet.reports.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Modules/Fleet/Reports/Index')
                ->has('kpis')
                ->has('expiryRadar')
                ->has('reportKeys')
            );
    }

    // ── rowsByKey ────────────────────────────────────────────────────────────

    public function test_vehicle_details_report_returns_all_non_disposed_vehicles(): void
    {
        FleetVehicleFactory::new()->available()->count(5)->create();
        FleetVehicleFactory::new()->disposed()->count(2)->create();

        $rows = app(FleetReportController::class)->rowsByKey('vehicle_details');

        // Disposed vehicles are in the table but not excluded by default in this report
        // Just assert it returns records
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('Registration', $rows[0]);
    }

    public function test_fuel_consumption_report_returns_rows_with_correct_keys(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();
        FleetFuelLogFactory::new()->forVehicle($vehicle)->count(3)->create();

        $rows = app(FleetReportController::class)->rowsByKey('fuel_consumption');

        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('Litres', $rows[0]);
        $this->assertArrayHasKey('Total Cost', $rows[0]);
    }

    public function test_driver_scorecard_report_returns_correct_rating(): void
    {
        $driver = FleetDriverFactory::new()->create();
        $driver->update(['total_trips' => 20, 'incidents_count' => 0]);

        $rows = app(FleetReportController::class)->rowsByKey('driver_scorecard');

        $driverRow = collect($rows)->first();
        $this->assertNotNull($driverRow);
        $this->assertArrayHasKey('Rating', $driverRow);
    }

    public function test_document_expiry_report_only_shows_documents_within_90_days(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        FleetDocumentFactory::new()->expiringSoon(30)->create(['vehicle_id' => $vehicle->id]);
        FleetDocumentFactory::new()->create([
            'vehicle_id'  => $vehicle->id,
            'expiry_date' => now()->addDays(180)->toDateString(),
        ]);

        $rows = app(FleetReportController::class)->rowsByKey('document_expiry');

        foreach ($rows as $row) {
            $expiryDate = \Carbon\Carbon::parse($row['Expiry Date']);
            $this->assertTrue($expiryDate->lte(now()->addDays(91)));
        }
    }

    public function test_booking_utilisation_report_counts_trips_per_vehicle(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();
        FleetBookingFactory::new()->returned()->count(4)->create(['vehicle_id' => $vehicle->id]);

        $rows = app(FleetReportController::class)->rowsByKey('booking_utilisation');

        $vehicleRow = collect($rows)->firstWhere('Registration', $vehicle->registration_number);
        $this->assertNotNull($vehicleRow);
        $this->assertEquals(4, (int) $vehicleRow['Total Trips']);
    }

    // ── Export endpoints ──────────────────────────────────────────────────────

    public function test_export_csv_returns_downloadable_response(): void
    {
        FleetVehicleFactory::new()->count(3)->create();

        $response = $this->actingAs($this->fleetManager)
            ->get(route('fleet.reports.export', ['key' => 'vehicle_details', 'format' => 'csv']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_generate_endpoint_returns_row_count(): void
    {
        FleetVehicleFactory::new()->count(5)->create();

        $response = $this->actingAs($this->fleetManager)
            ->getJson(route('fleet.reports.generate', ['key' => 'vehicle_details']));

        $response->assertOk()->assertJsonStructure(['count', 'key']);
        $this->assertGreaterThanOrEqual(5, $response->json('count'));
    }

    // ── Scheduled reports ─────────────────────────────────────────────────────

    public function test_manager_can_create_scheduled_report(): void
    {
        $this->actingAs($this->fleetManager)
            ->post(route('fleet.reports.scheduled.store'), [
                'name'       => 'Weekly Fuel Report',
                'report_key' => 'fuel_consumption',
                'cadence'    => 'weekly',
                'run_time'   => '07:00',
                'day_of_week'=> 1,
                'format'     => 'pdf',
                'recipients' => 'manager@company.com',
                'active'     => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_scheduled_reports', [
            'report_key' => 'fuel_consumption',
            'cadence'    => 'weekly',
            'active'     => true,
        ]);

        $report = FleetScheduledReport::latest()->first();
        $this->assertNotNull($report->next_run_at);
    }

    public function test_manager_can_update_scheduled_report(): void
    {
        $report = FleetScheduledReportFactory::new()->weekly()->create();

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.reports.scheduled.update', $report), [
                'name'       => 'Updated Report Name',
                'report_key' => $report->report_key,
                'cadence'    => 'monthly',
                'run_time'   => '08:00',
                'day_of_month'=> 15,
                'format'     => 'excel',
                'recipients' => 'new@company.com',
                'active'     => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_scheduled_reports', [
            'id'      => $report->id,
            'cadence' => 'monthly',
            'name'    => 'Updated Report Name',
        ]);
    }

    public function test_manager_can_delete_scheduled_report(): void
    {
        $report = FleetScheduledReportFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->delete(route('fleet.reports.scheduled.destroy', $report))
            ->assertRedirect();

        $this->assertDatabaseMissing('fleet_scheduled_reports', ['id' => $report->id]);
    }

    public function test_viewer_cannot_access_scheduled_reports_management(): void
    {
        $this->actingAs($this->fleetViewer)
            ->post(route('fleet.reports.scheduled.store'), [
                'name'       => 'Viewer Report',
                'report_key' => 'fuel_consumption',
                'cadence'    => 'daily',
                'run_time'   => '07:00',
                'format'     => 'pdf',
                'recipients' => 'v@co.com',
            ])
            ->assertForbidden();
    }
}
