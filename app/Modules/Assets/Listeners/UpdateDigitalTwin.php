<?php

namespace App\Modules\Assets\Listeners;

use App\Modules\Assets\Events\AssetIncidentReported;
use App\Modules\Assets\Events\AssetMaintenanceCompleted;
use App\Modules\Assets\Events\AssetMoved;
use App\Modules\Assets\Models\AssetDigitalTwin;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * M2 — Digital Twin Synchronization
 * 
 * Listens to all asset events and updates the digital twin in real-time.
 * Aggregates movements, incidents, maintenance into a single queryable profile.
 */
class UpdateDigitalTwin implements ShouldQueue
{
    public function handle($event): void
    {
        $asset = $this->extractAsset($event);
        
        if (!$asset) {
            return;
        }

        $twin = AssetDigitalTwin::firstOrCreate(
            ['asset_id' => $asset->id],
            ['uuid' => \Illuminate\Support\Str::uuid()]
        );

        match (true) {
            $event instanceof AssetMoved                  => $this->handleMovement($twin, $event),
            $event instanceof AssetIncidentReported       => $this->handleIncident($twin, $event),
            $event instanceof AssetMaintenanceCompleted   => $this->handleMaintenance($twin, $event),
            default                                       => null,
        };

        $this->recalculateHealthScore($twin);
    }

    private function extractAsset($event)
    {
        return match (true) {
            $event instanceof AssetMoved                => $event->movement->asset,
            $event instanceof AssetIncidentReported     => $event->incident->asset,
            $event instanceof AssetMaintenanceCompleted => $event->maintenance->asset,
            default                                     => null,
        };
    }

    private function handleMovement(AssetDigitalTwin $twin, AssetMoved $event): void
    {
        $twin->increment('total_movements');
        $twin->update(['last_movement_at' => $event->movement->movement_date]);
    }

    private function handleIncident(AssetDigitalTwin $twin, AssetIncidentReported $event): void
    {
        $twin->increment('total_incidents');
        $twin->update(['last_incident_at' => $event->incident->incident_date]);
        
        if ($event->incident->downtime_hours) {
            $twin->increment('total_downtime_hours', $event->incident->downtime_hours);
        }
    }

    private function handleMaintenance(AssetDigitalTwin $twin, AssetMaintenanceCompleted $event): void
    {
        $twin->update(['last_maintenance_at' => $event->maintenance->completed_date]);
        
        if ($event->maintenance->downtime_hours) {
            $twin->increment('total_downtime_hours', $event->maintenance->downtime_hours);
        }
    }

    private function recalculateHealthScore(AssetDigitalTwin $twin): void
    {
        $asset = $twin->asset;
        
        // Simple health scoring algorithm
        $score = 100;
        
        // Age penalty (max -20)
        if ($asset->purchase_date) {
            $ageYears = $asset->purchase_date->diffInYears(now());
            $score -= min(20, $ageYears * 2);
        }
        
        // Incident penalty (max -30)
        $score -= min(30, $twin->total_incidents * 5);
        
        // Downtime penalty (max -20)
        $score -= min(20, $twin->total_downtime_hours / 10);
        
        // Maintenance bonus (up to +10 if recent)
        if ($twin->last_maintenance_at && $twin->last_maintenance_at->isAfter(now()->subMonths(3))) {
            $score += 10;
        }
        
        $score = max(0, min(100, $score));
        
        $riskLevel = match (true) {
            $score >= 70 => 'low',
            $score >= 50 => 'medium',
            $score >= 30 => 'high',
            default      => 'critical',
        };
        
        $twin->update([
            'health_score' => $score,
            'risk_level'   => $riskLevel,
        ]);
    }
}
