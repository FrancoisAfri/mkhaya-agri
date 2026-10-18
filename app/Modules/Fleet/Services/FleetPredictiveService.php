<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetMaintenance;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Models\FleetVehiclePrediction;
use App\Modules\Fleet\Notifications\FleetHighRiskVehicleNotification;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FleetPredictiveService
{
    // ── Weights must sum to 1.0 ────────────────────────────────────────────────
    private const WEIGHTS = [
        'service_overdue' => 0.35,
        'km_intensity'    => 0.30,
        'incident_rate'   => 0.20,
        'age_factor'      => 0.10,
        'efficiency_drop' => 0.05,
    ];

    private const SERVICE_INTERVAL_KM  = 15_000;
    private const SERVICE_INTERVAL_DAYS = 365;
    private const OVERDUE_GRACE_DAYS    = 90;

    // ── Run for all active vehicles ────────────────────────────────────────────

    public function runForAllVehicles(): array
    {
        $vehicles = FleetVehicle::whereNotIn('status', ['disposed', 'out_of_service'])->get();
        $results  = ['processed' => 0, 'alerts' => 0, 'jobs_created' => 0];

        foreach ($vehicles as $vehicle) {
            try {
                $prediction = $this->predict($vehicle);

                if ($prediction->isHighRisk() && !$prediction->alert_sent) {
                    $this->sendAlert($vehicle, $prediction);
                    $prediction->update(['alert_sent' => true]);
                    $results['alerts']++;
                }

                if ($prediction->isHighRisk() && !$prediction->maintenance_job_id) {
                    $jobId = $this->createJobCard($vehicle, $prediction);
                    if ($jobId) {
                        $prediction->update(['maintenance_job_id' => $jobId]);
                        $results['jobs_created']++;
                    }
                }

                $results['processed']++;
            } catch (\Throwable $e) {
                Log::error("Fleet prediction failed for vehicle {$vehicle->id}: {$e->getMessage()}");
            }
        }

        return $results;
    }

    // ── Predict for a single vehicle ───────────────────────────────────────────

    public function predict(FleetVehicle $vehicle): FleetVehiclePrediction
    {
        $features   = $this->extractFeatures($vehicle);
        $components = $this->computeComponents($features);
        $riskScore  = round(array_sum($components) * 100, 2);

        $topFactors   = $this->buildTopFactors($features, $components);
        $serviceDate  = $this->estimateServiceDate($vehicle, $features);

        $prediction = FleetVehiclePrediction::updateOrCreate(
            ['vehicle_id' => $vehicle->id],
            [
                'risk_score'             => $riskScore,
                'predicted_service_date' => $serviceDate,
                'top_risk_factors'       => $topFactors,
                'feature_values'         => $features,
                'prediction_method'      => 'rule_based',
                'predicted_at'           => now(),
                'alert_sent'             => false,
                'maintenance_job_id'     => null,
            ]
        );

        return $prediction;
    }

    // ── Feature extraction ─────────────────────────────────────────────────────

    private function extractFeatures(FleetVehicle $vehicle): array
    {
        // Days & km since last service
        $lastMaintenance = FleetMaintenance::where('vehicle_id', $vehicle->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_date')
            ->first();

        $daysSinceService = $lastMaintenance
            ? now()->diffInDays($lastMaintenance->completed_date)
            : 730;

        $lastServiceOdometer = $lastMaintenance?->end_odometer ?? 0;
        $kmSinceService      = max(0, ($vehicle->odometer_reading ?? 0) - $lastServiceOdometer);

        // Incident count (last 90 days)
        $incidents90d = DB::table('fleet_booking_incidents')
            ->join('fleet_bookings', 'fleet_booking_incidents.booking_id', '=', 'fleet_bookings.id')
            ->where('fleet_bookings.vehicle_id', $vehicle->id)
            ->where('fleet_booking_incidents.created_at', '>=', now()->subDays(90))
            ->count();

        // Vehicle age (years)
        $purchaseDate = $vehicle->purchase_date ?? null;
        $ageYears     = $purchaseDate ? now()->diffInYears($purchaseDate) : 5;

        // Fuel efficiency trend (last 60 days vs previous 60 days)
        $efficiencyDeclinePct = $this->computeEfficiencyDecline($vehicle->id);

        return [
            'days_since_service'      => (int) $daysSinceService,
            'km_since_service'        => (int) $kmSinceService,
            'incidents_90d'           => (int) $incidents90d,
            'age_years'               => (float) $ageYears,
            'efficiency_decline_pct'  => (float) $efficiencyDeclinePct,
            'odometer_reading'        => (int) ($vehicle->odometer_reading ?? 0),
        ];
    }

    private function computeEfficiencyDecline(int $vehicleId): float
    {
        $fuelLogs = DB::table('fleet_fuel_logs')
            ->where('vehicle_id', $vehicleId)
            ->where('filled_at', '>=', now()->subDays(120))
            ->orderBy('filled_at')
            ->get(['filled_at', 'litres', 'odometer_at_fill']);

        if ($fuelLogs->count() < 4) return 0.0;

        $mid     = $fuelLogs->count() / 2;
        $recent  = $fuelLogs->slice((int) $mid)->values();
        $earlier = $fuelLogs->take((int) $mid)->values();

        $recentEff  = $this->avgEfficiency($recent);
        $earlierEff = $this->avgEfficiency($earlier);

        if ($earlierEff <= 0) return 0.0;

        return round((($recentEff - $earlierEff) / $earlierEff) * 100, 2);
    }

    private function avgEfficiency($logs): float
    {
        $total = 0; $count = 0;
        for ($i = 1; $i < $logs->count(); $i++) {
            $kmDiff = ($logs[$i]->odometer_at_fill ?? 0) - ($logs[$i - 1]->odometer_at_fill ?? 0);
            if ($kmDiff > 0 && $logs[$i]->litres > 0) {
                $total += ($logs[$i]->litres / $kmDiff) * 100;
                $count++;
            }
        }
        return $count > 0 ? $total / $count : 0.0;
    }

    // ── Component scoring ──────────────────────────────────────────────────────

    private function computeComponents(array $f): array
    {
        $overduedays = max(0, $f['days_since_service'] - self::OVERDUE_GRACE_DAYS);

        return [
            'service_overdue' => min(1, $overduedays / (self::SERVICE_INTERVAL_DAYS - self::OVERDUE_GRACE_DAYS)) * self::WEIGHTS['service_overdue'],
            'km_intensity'    => min(1, $f['km_since_service'] / self::SERVICE_INTERVAL_KM) * self::WEIGHTS['km_intensity'],
            'incident_rate'   => min(1, $f['incidents_90d'] / 5) * self::WEIGHTS['incident_rate'],
            'age_factor'      => min(1, $f['age_years'] / 15) * self::WEIGHTS['age_factor'],
            'efficiency_drop' => max(0, min(1, $f['efficiency_decline_pct'] / 50)) * self::WEIGHTS['efficiency_drop'],
        ];
    }

    private function buildTopFactors(array $features, array $components): array
    {
        $labels = [
            'service_overdue' => "{$features['days_since_service']} days since last service",
            'km_intensity'    => number_format($features['km_since_service']) . " km since last service",
            'incident_rate'   => "{$features['incidents_90d']} incident(s) in past 90 days",
            'age_factor'      => round($features['age_years'], 1) . " years old",
            'efficiency_drop' => $features['efficiency_decline_pct'] . "% fuel efficiency decline",
        ];

        $factorsWithScore = collect($components)
            ->map(fn($val, $key) => [
                'factor'       => $key,
                'label'        => $labels[$key],
                'contribution' => round($val * 100, 1),
            ])
            ->sortByDesc('contribution')
            ->values()
            ->take(3)
            ->toArray();

        return $factorsWithScore;
    }

    private function estimateServiceDate(FleetVehicle $vehicle, array $features): ?string
    {
        $odometer = $vehicle->odometer_reading ?? 0;
        $kmLeft   = max(0, self::SERVICE_INTERVAL_KM - $features['km_since_service']);
        $daysLeft = max(0, self::SERVICE_INTERVAL_DAYS - $features['days_since_service']);

        // Use whichever threshold is closest
        $dailyKm = DB::table('fleet_odometer_logs')
            ->where('vehicle_id', $vehicle->id)
            ->where('reading_date', '>=', now()->subDays(30))
            ->orderBy('reading_date')
            ->pluck('reading')
            ->pipe(function ($readings) {
                if ($readings->count() < 2) return 30;
                $span = $readings->last() - $readings->first();
                return max(1, $span / 30);
            });

        $daysByKm  = $dailyKm > 0 ? (int) ($kmLeft / $dailyKm) : 365;
        $serviceIn = min($daysByKm, $daysLeft);

        return now()->addDays(max(0, $serviceIn))->toDateString();
    }

    // ── Notifications & job cards ──────────────────────────────────────────────

    private function sendAlert(FleetVehicle $vehicle, FleetVehiclePrediction $prediction): void
    {
        $managers = User::role(['fleet_manager', 'administrator'])->get();
        foreach ($managers as $manager) {
            $manager->notify(new FleetHighRiskVehicleNotification($vehicle, $prediction));
        }
    }

    private function createJobCard(FleetVehicle $vehicle, FleetVehiclePrediction $prediction): ?int
    {
        $reasons = collect($prediction->top_risk_factors)
            ->pluck('label')
            ->implode('; ');

        $job = DB::table('fleet_maintenance')->insertGetId([
            'vehicle_id'       => $vehicle->id,
            'maintenance_type' => 'service',
            'description'      => "AI-suggested service (risk score {$prediction->risk_score}). Factors: {$reasons}",
            'status'           => 'scheduled',
            'priority'         => $prediction->risk_score >= 85 ? 'critical' : 'high',
            'scheduled_date'   => $prediction->predicted_service_date,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $job ?: null;
    }
}
