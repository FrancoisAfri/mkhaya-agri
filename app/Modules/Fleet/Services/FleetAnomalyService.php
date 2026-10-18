<?php

namespace App\Modules\Fleet\Services;

use App\Core\User\Models\User;
use App\Modules\Fleet\Models\FleetFuelAnomaly;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Notifications\FleetAnomalyDetectedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FleetAnomalyService
{
    // ── Run anomaly detection for all vehicles ─────────────────────────────────

    public function detectForAllVehicles(): array
    {
        $vehicles = FleetVehicle::whereNotIn('status', ['disposed'])->get();
        $flagged  = 0;

        foreach ($vehicles as $vehicle) {
            try {
                $flagged += $this->detectForVehicle($vehicle);
            } catch (\Throwable $e) {
                Log::error("Anomaly detection failed for vehicle {$vehicle->id}: {$e->getMessage()}");
            }
        }

        return ['flagged' => $flagged];
    }

    public function detectForVehicle(FleetVehicle $vehicle): int
    {
        $before  = FleetFuelAnomaly::where('vehicle_id', $vehicle->id)->count();

        $this->checkTankCapacityExceeded($vehicle);
        $this->checkFuelEfficiencySpike($vehicle);
        $this->checkExcessiveDriverSpend($vehicle);
        $this->checkTollgateMismatch($vehicle);

        // Notify fleet managers for each newly created anomaly
        $newAnomalies = FleetFuelAnomaly::where('vehicle_id', $vehicle->id)
            ->latest()
            ->skip($before)
            ->take(20)
            ->get();

        $managers = User::role(['fleet_manager', 'administrator'])->get();
        foreach ($newAnomalies as $anomaly) {
            $managers->each->notify(new FleetAnomalyDetectedNotification($anomaly));
        }

        return $newAnomalies->count();
    }

    // ── FR-4.3.3 Flag fills exceeding tank capacity ────────────────────────────

    private function checkTankCapacityExceeded(FleetVehicle $vehicle): int
    {
        $tankCapacity = $vehicle->tank_capacity_litres ?? 80;

        $overFills = DB::table('fleet_fuel_logs')
            ->where('vehicle_id', $vehicle->id)
            ->where('litres', '>', $tankCapacity)
            ->where('filled_at', '>=', now()->subDays(90))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('fleet_fuel_anomalies')
                  ->whereColumn('fleet_fuel_anomalies.fuel_log_id', 'fleet_fuel_logs.id')
                  ->where('anomaly_type', 'exceeds_tank');
            })
            ->get(['id', 'litres', 'driver_id']);

        $flagged = 0;
        foreach ($overFills as $fill) {
            FleetFuelAnomaly::create([
                'fuel_log_id'    => $fill->id,
                'vehicle_id'     => $vehicle->id,
                'driver_id'      => $fill->driver_id,
                'anomaly_type'   => 'exceeds_tank',
                'description'    => "Fill of {$fill->litres}L exceeds tank capacity of {$tankCapacity}L.",
                'severity'       => 'high',
                'expected_value' => $tankCapacity,
                'actual_value'   => $fill->litres,
            ]);
            $flagged++;
        }

        return $flagged;
    }

    // ── FR-4.3.1 / FR-4.3.2 Fuel efficiency spike ─────────────────────────────

    private function checkFuelEfficiencySpike(FleetVehicle $vehicle): int
    {
        $logs = DB::table('fleet_fuel_logs')
            ->where('vehicle_id', $vehicle->id)
            ->where('filled_at', '>=', now()->subDays(180))
            ->orderBy('filled_at')
            ->get(['id', 'litres', 'odometer_at_fill', 'driver_id', 'filled_at']);

        if ($logs->count() < 4) return 0;

        // Build L/100km series
        $series = [];
        for ($i = 1; $i < $logs->count(); $i++) {
            $km = ($logs[$i]->odometer_at_fill ?? 0) - ($logs[$i - 1]->odometer_at_fill ?? 0);
            if ($km > 0 && $logs[$i]->litres > 0) {
                $series[] = [
                    'log_id'    => $logs[$i]->id,
                    'driver_id' => $logs[$i]->driver_id,
                    'eff'       => ($logs[$i]->litres / $km) * 100,
                ];
            }
        }

        if (count($series) < 3) return 0;

        $values = array_column($series, 'eff');
        $mean   = array_sum($values) / count($values);
        $std    = $this->stdDev($values);

        if ($std < 0.1) return 0;

        $flagged = 0;
        foreach ($series as $point) {
            // Flag > 2 std deviations above mean (excess consumption)
            if ($point['eff'] > $mean + (2 * $std)) {
                $alreadyFlagged = FleetFuelAnomaly::where('fuel_log_id', $point['log_id'])
                    ->where('anomaly_type', 'efficiency_spike')
                    ->exists();

                if (!$alreadyFlagged) {
                    FleetFuelAnomaly::create([
                        'fuel_log_id'    => $point['log_id'],
                        'vehicle_id'     => $vehicle->id,
                        'driver_id'      => $point['driver_id'],
                        'anomaly_type'   => 'efficiency_spike',
                        'description'    => sprintf(
                            'Fuel consumption %.1f L/100km is %.1f stddev above vehicle average (%.1f L/100km).',
                            $point['eff'],
                            ($point['eff'] - $mean) / $std,
                            $mean
                        ),
                        'severity'       => $point['eff'] > $mean + (3 * $std) ? 'high' : 'medium',
                        'expected_value' => round($mean, 2),
                        'actual_value'   => round($point['eff'], 2),
                    ]);
                    $flagged++;
                }
            }
        }

        return $flagged;
    }

    // ── FR-4.3.2 Excessive driver spend (vs driver baseline) ──────────────────

    private function checkExcessiveDriverSpend(FleetVehicle $vehicle): int
    {
        $flagged = 0;

        // Per-driver spend over last 30 days vs prior 30 days
        $drivers = DB::table('fleet_fuel_logs')
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('driver_id')
            ->where('filled_at', '>=', now()->subDays(60))
            ->select('driver_id', DB::raw('SUM(amount) as total'), DB::raw("date_trunc('day', filled_at) as day"))
            ->groupBy('driver_id')
            ->havingRaw('SUM(amount) > 0')
            ->get();

        foreach ($drivers as $row) {
            $recent = (float) DB::table('fleet_fuel_logs')
                ->where('vehicle_id', $vehicle->id)
                ->where('driver_id', $row->driver_id)
                ->where('filled_at', '>=', now()->subDays(30))
                ->sum('amount');

            $prior = (float) DB::table('fleet_fuel_logs')
                ->where('vehicle_id', $vehicle->id)
                ->where('driver_id', $row->driver_id)
                ->whereBetween('filled_at', [now()->subDays(60), now()->subDays(30)])
                ->sum('amount');

            if ($prior > 0 && $recent > $prior * 1.5) {
                $alreadyFlagged = FleetFuelAnomaly::where('vehicle_id', $vehicle->id)
                    ->where('driver_id', $row->driver_id)
                    ->where('anomaly_type', 'excessive_driver_spend')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->exists();

                if (!$alreadyFlagged) {
                    FleetFuelAnomaly::create([
                        'vehicle_id'     => $vehicle->id,
                        'driver_id'      => $row->driver_id,
                        'anomaly_type'   => 'excessive_driver_spend',
                        'description'    => sprintf(
                            'Driver spend R%.2f in last 30 days vs R%.2f prior period (%.0f%% increase).',
                            $recent, $prior, (($recent - $prior) / $prior) * 100
                        ),
                        'severity'       => 'medium',
                        'expected_value' => round($prior, 2),
                        'actual_value'   => round($recent, 2),
                    ]);
                    $flagged++;
                }
            }
        }

        return $flagged;
    }

    // ── FR-4.3.5 Tollgate amount inconsistent with destination ────────────────

    private function checkTollgateMismatch(FleetVehicle $vehicle): int
    {
        $flagged = 0;

        // Flag tollgates where the cumulative amount > R500 but booking is marked "local trip"
        $suspicious = DB::table('fleet_booking_tollgates as t')
            ->join('fleet_bookings as b', 'b.id', '=', 't.booking_id')
            ->where('t.vehicle_id', $vehicle->id)
            ->where('b.is_local_trip', true)
            ->where('t.amount', '>', 100)
            ->where('t.created_at', '>=', now()->subDays(90))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('fleet_fuel_anomalies')
                  ->whereColumn('fleet_fuel_anomalies.tollgate_id', 't.id')
                  ->where('anomaly_type', 'tollgate_mismatch');
            })
            ->get(['t.id as tollgate_id', 't.amount', 'b.id as booking_id', 'b.destination']);

        foreach ($suspicious as $row) {
            FleetFuelAnomaly::create([
                'tollgate_id'  => $row->tollgate_id,
                'vehicle_id'   => $vehicle->id,
                'anomaly_type' => 'tollgate_mismatch',
                'description'  => sprintf(
                    'Tollgate of R%.2f on a local trip booking (destination: %s) — inconsistent.',
                    $row->amount, $row->destination ?? 'N/A'
                ),
                'severity'       => 'medium',
                'expected_value' => 0,
                'actual_value'   => $row->amount,
            ]);
            $flagged++;
        }

        return $flagged;
    }

    // ── Weekly digest summary ──────────────────────────────────────────────────

    public function weeklyDigestData(): array
    {
        $since = now()->subDays(7);

        $byType = FleetFuelAnomaly::where('created_at', '>=', $since)
            ->select('anomaly_type', DB::raw('COUNT(*) as count'))
            ->groupBy('anomaly_type')
            ->pluck('count', 'anomaly_type')
            ->toArray();

        $bySeverity = FleetFuelAnomaly::where('created_at', '>=', $since)
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $topVehicles = FleetFuelAnomaly::where('created_at', '>=', $since)
            ->select('vehicle_id', DB::raw('COUNT(*) as count'))
            ->groupBy('vehicle_id')
            ->orderByDesc('count')
            ->limit(5)
            ->with('vehicle:id,registration_number,make,model')
            ->get()
            ->map(fn($a) => [
                'vehicle' => $a->vehicle?->registration_number . ' ' . $a->vehicle?->make . ' ' . $a->vehicle?->model,
                'count'   => $a->count,
            ])
            ->toArray();

        return [
            'total'       => array_sum($byType),
            'by_type'     => $byType,
            'by_severity' => $bySeverity,
            'top_vehicles'=> $topVehicles,
            'unreviewed'  => FleetFuelAnomaly::unreviewed()->count(),
        ];
    }

    private function stdDev(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;
        $mean = array_sum($values) / $n;
        $sq   = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values));
        return sqrt($sq / ($n - 1));
    }
}
