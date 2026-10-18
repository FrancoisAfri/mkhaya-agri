<?php

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Models\FleetDocument;
use Database\Factories\Fleet\FleetDocumentFactory;
use Database\Factories\Fleet\FleetVehicleFactory;

class FleetDocumentTest extends FleetTestCase
{
    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function test_manager_can_upload_a_document(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.documents.store'), [
                'vehicle_id'       => $vehicle->id,
                'document_type'    => 'insurance',
                'document_number'  => 'POL-2026-12345',
                'issued_date'      => now()->subMonth()->toDateString(),
                'expiry_date'      => now()->addYear()->toDateString(),
                'issuing_authority'=> 'Old Mutual',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_documents', [
            'vehicle_id'    => $vehicle->id,
            'document_type' => 'insurance',
            'status'        => 'valid',
        ]);
    }

    public function test_document_status_can_be_updated_to_expired(): void
    {
        $doc = FleetDocumentFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.documents.update', $doc), [
                'document_type' => $doc->document_type,
                'status'        => 'expired',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_documents', [
            'id'     => $doc->id,
            'status' => 'expired',
        ]);
    }

    public function test_manager_can_delete_document(): void
    {
        $doc = FleetDocumentFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->delete(route('fleet.documents.destroy', $doc))
            ->assertRedirect();

        $this->assertSoftDeleted('fleet_documents', ['id' => $doc->id]);
    }

    // ── Expiry logic ──────────────────────────────────────────────────────────

    /**
     * A document that has already expired can still be recorded.
     *
     * This once asserted the opposite, and refusing a past date would be the wrong rule: a
     * roadworthy certificate that lapsed last month is exactly the thing a fleet office needs on
     * file, because that is what makes the vehicle show up as non-compliant. The module is built
     * for it — `status` carries an `expired` value and the expiry report exists to list them.
     */
    public function test_a_document_that_has_already_expired_can_be_recorded(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.documents.store'), [
                'vehicle_id'    => $vehicle->id,
                'document_type' => 'roadworthy',
                'expiry_date'   => now()->subDay()->toDateString(),
                'status'        => 'expired',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fleet_documents', [
            'vehicle_id'    => $vehicle->id,
            'document_type' => 'roadworthy',
            'status'        => 'expired',
        ]);
    }

    public function test_expiring_within_30_days_is_flagged(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        FleetDocumentFactory::new()->expiringSoon(20)->create(['vehicle_id' => $vehicle->id]);
        FleetDocumentFactory::new()->expiringSoon(50)->create(['vehicle_id' => $vehicle->id]);

        $expiring = FleetDocument::expiringSoon(30)->get();

        $this->assertCount(1, $expiring);
        $this->assertTrue($expiring->first()->expiry_date->lt(now()->addDays(31)));
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_viewer_cannot_upload_document(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $this->actingAs($this->fleetViewer)
            ->post(route('fleet.documents.store'), [
                'vehicle_id'    => $vehicle->id,
                'document_type' => 'insurance',
                'expiry_date'   => now()->addYear()->toDateString(),
            ])
            ->assertForbidden();
    }
}
