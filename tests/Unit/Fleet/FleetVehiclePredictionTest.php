<?php

namespace Tests\Unit\Fleet;

use App\Modules\Fleet\Models\FleetVehiclePrediction;
use Database\Factories\Fleet\FleetVehicleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetVehiclePredictionTest extends TestCase
{
    use RefreshDatabase;

    // ── Risk level classification ─────────────────────────────────────────────

    public function test_risk_score_below_40_is_low_risk(): void
    {
        $prediction = $this->makePrediction(25.0);

        $this->assertFalse($prediction->isHighRisk());
        $this->assertFalse($prediction->isMediumRisk());
        $this->assertSame('low', $prediction->riskLevel());
    }

    public function test_risk_score_between_40_and_69_is_medium_risk(): void
    {
        $prediction = $this->makePrediction(55.0);

        $this->assertFalse($prediction->isHighRisk());
        $this->assertTrue($prediction->isMediumRisk());
        $this->assertSame('medium', $prediction->riskLevel());
    }

    public function test_risk_score_of_70_or_above_is_high_risk(): void
    {
        $prediction = $this->makePrediction(70.0);

        $this->assertTrue($prediction->isHighRisk());
        $this->assertFalse($prediction->isMediumRisk());
        $this->assertSame('high', $prediction->riskLevel());
    }

    public function test_risk_score_at_boundary_39_is_still_low(): void
    {
        $prediction = $this->makePrediction(39.99);

        $this->assertSame('low', $prediction->riskLevel());
    }

    public function test_risk_score_at_boundary_40_is_medium(): void
    {
        $prediction = $this->makePrediction(40.0);

        $this->assertSame('medium', $prediction->riskLevel());
    }

    public function test_risk_score_at_100_is_high(): void
    {
        $prediction = $this->makePrediction(100.0);

        $this->assertTrue($prediction->isHighRisk());
        $this->assertSame('high', $prediction->riskLevel());
    }

    // ── DB persistence ────────────────────────────────────────────────────────

    public function test_top_risk_factors_are_cast_to_array(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $prediction = FleetVehiclePrediction::create([
            'vehicle_id'        => $vehicle->id,
            'risk_score'        => 65.00,
            'top_risk_factors'  => ['high_mileage', 'overdue_service'],
            'prediction_method' => 'rule_based',
            'predicted_at'      => now(),
        ]);

        $fresh = FleetVehiclePrediction::find($prediction->id);

        $this->assertIsArray($fresh->top_risk_factors);
        $this->assertContains('high_mileage', $fresh->top_risk_factors);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function makePrediction(float $score): FleetVehiclePrediction
    {
        $vehicle = FleetVehicleFactory::new()->create();

        return FleetVehiclePrediction::create([
            'vehicle_id'        => $vehicle->id,
            'risk_score'        => $score,
            'prediction_method' => 'rule_based',
            'predicted_at'      => now(),
        ]);
    }
}
