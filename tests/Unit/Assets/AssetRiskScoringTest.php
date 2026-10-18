<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\AssetRiskProfile;
use App\Modules\Assets\Models\Assets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetRiskScoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_asset_in_good_condition_has_low_risk(): void
    {
        $asset = Assets::factory()->create([
            'condition'     => 'new',
            'purchase_date' => now()->toDateString(),
        ]);

        $profile = AssetRiskProfile::create([
            'asset_id'           => $asset->id,
            'risk_score'         => 0,
            'risk_level'         => 'low',
            'last_calculated_at' => now(),
        ]);

        $profile->setRelation('asset', $asset);
        $profile->recalculate();

        $this->assertLessThan(30, $profile->risk_score);
        $this->assertSame('low', $profile->risk_level);
    }

    public function test_old_asset_with_unresolved_incidents_and_missed_maintenance_is_high_risk(): void
    {
        $asset = Assets::factory()->create([
            'condition'     => 'poor',
            'purchase_date' => now()->subYears(10)->toDateString(),
        ]);

        // Many unresolved incidents
        for ($i = 0; $i < 4; $i++) {
            AssetIncident::factory()->create([
                'asset_id'    => $asset->id,
                'is_resolved' => false,
                'severity'    => 'critical',
            ]);
        }

        // Overdue maintenance
        for ($i = 0; $i < 3; $i++) {
            AssetMaintenanceSchedule::create([
                'asset_id'   => $asset->id,
                'title'      => "Overdue {$i}",
                'status'     => 'scheduled',
                'due_date'   => now()->subDays(10)->toDateString(),
                'maintenance_type' => 'preventive',
            ]);
        }

        $profile = AssetRiskProfile::create([
            'asset_id'           => $asset->id,
            'risk_score'         => 0,
            'risk_level'         => 'low',
            'last_calculated_at' => now(),
        ]);

        $profile->setRelation('asset', $asset);
        $profile->recalculate();

        $this->assertGreaterThanOrEqual(60, $profile->risk_score, 'Expected high-risk shape to score >= 60');
        $this->assertContains($profile->risk_level, ['high', 'critical']);
    }

    public function test_risk_level_critical_at_score_80_or_above(): void
    {
        $asset = Assets::factory()->create([
            'condition'     => 'poor',
            'purchase_date' => now()->subYears(15)->toDateString(),
        ]);

        // Maximum signal
        for ($i = 0; $i < 6; $i++) {
            AssetIncident::factory()->create([
                'asset_id'    => $asset->id,
                'is_resolved' => false,
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            AssetMaintenanceSchedule::create([
                'asset_id'         => $asset->id,
                'title'            => "Late {$i}",
                'status'           => 'scheduled',
                'maintenance_type' => 'preventive',
                'due_date'         => now()->subDays(20)->toDateString(),
            ]);
        }

        $profile = AssetRiskProfile::create([
            'asset_id'           => $asset->id,
            'risk_score'         => 0,
            'risk_level'         => 'low',
            'last_calculated_at' => now(),
        ]);
        $profile->setRelation('asset', $asset);
        $profile->recalculate();

        $this->assertGreaterThanOrEqual(80, $profile->risk_score);
        $this->assertSame('critical', $profile->risk_level);
    }

    public function test_needs_immediate_action_helper_triggers_above_eighty(): void
    {
        $asset = Assets::factory()->create();

        $profile = AssetRiskProfile::create([
            'asset_id'           => $asset->id,
            'risk_score'         => 85,
            'risk_level'         => 'critical',
            'last_calculated_at' => now(),
        ]);

        $this->assertTrue($profile->needsImmediateAction());
        $this->assertTrue($profile->isCritical());
    }

    public function test_helpers_are_false_for_low_risk_profile(): void
    {
        $asset = Assets::factory()->create();

        $profile = AssetRiskProfile::create([
            'asset_id'           => $asset->id,
            'risk_score'         => 10,
            'risk_level'         => 'low',
            'last_calculated_at' => now(),
        ]);

        $this->assertFalse($profile->needsImmediateAction());
        $this->assertFalse($profile->isCritical());
    }
}
