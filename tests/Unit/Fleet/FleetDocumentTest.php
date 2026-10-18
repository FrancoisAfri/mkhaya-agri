<?php

namespace Tests\Unit\Fleet;

use App\Modules\Fleet\Models\FleetDocument;
use Database\Factories\Fleet\FleetDocumentFactory;
use Database\Factories\Fleet\FleetVehicleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDocumentTest extends TestCase
{
    use RefreshDatabase;

    // ── isExpired ─────────────────────────────────────────────────────────────

    public function test_document_with_future_expiry_is_not_expired(): void
    {
        $doc = FleetDocumentFactory::new()->create([
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->assertFalse($doc->isExpired());
    }

    public function test_document_with_past_expiry_is_expired(): void
    {
        $doc = FleetDocumentFactory::new()->create([
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertTrue($doc->isExpired());
    }

    public function test_document_with_null_expiry_is_not_expired(): void
    {
        $doc = FleetDocumentFactory::new()->create(['expiry_date' => null]);

        $this->assertFalse($doc->isExpired());
    }

    // ── daysUntilExpiry ───────────────────────────────────────────────────────

    public function test_days_until_expiry_returns_positive_for_future_date(): void
    {
        $doc = FleetDocumentFactory::new()->create([
            'expiry_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertGreaterThan(0, $doc->daysUntilExpiry());
    }

    public function test_days_until_expiry_returns_negative_for_past_date(): void
    {
        $doc = FleetDocumentFactory::new()->create([
            'expiry_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->assertLessThan(0, $doc->daysUntilExpiry());
    }

    public function test_days_until_expiry_returns_null_when_no_expiry_date(): void
    {
        $doc = FleetDocumentFactory::new()->create(['expiry_date' => null]);

        $this->assertNull($doc->daysUntilExpiry());
    }

    // ── expiringSoon scope ────────────────────────────────────────────────────

    public function test_expiring_soon_scope_includes_documents_within_window(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        FleetDocumentFactory::new()->expiringSoon(20)->create(['vehicle_id' => $vehicle->id]);
        FleetDocumentFactory::new()->create([
            'vehicle_id'  => $vehicle->id,
            'expiry_date' => now()->addDays(60)->toDateString(),
        ]);

        $expiring = FleetDocument::expiringSoon(30)->get();

        $this->assertCount(1, $expiring);
    }

    public function test_expiring_soon_scope_excludes_already_expired_documents(): void
    {
        FleetDocumentFactory::new()->expired()->create();
        FleetDocumentFactory::new()->expiringSoon(10)->create();

        // scope: expiry_date >= now, so past expiry should be excluded
        $expiring = FleetDocument::expiringSoon(30)->get();

        $this->assertCount(1, $expiring);
    }
}
