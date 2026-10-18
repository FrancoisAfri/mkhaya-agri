<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaimsTravelController extends Controller
{
    // Peak hour windows (South Africa): weekday 06:30-09:30 and 15:30-18:30
    private const PEAK_WINDOWS = [
        ['start' => '06:30', 'end' => '09:30'],
        ['start' => '15:30', 'end' => '18:30'],
    ];

    private const PEAK_SURCHARGE_RATE = 0.20; // 20 % on base amount

    public function calculateDistance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin'         => 'required|string|max:500',
            'destination'    => 'required|string|max:500',
            'trip_date'      => 'required|date',
            'departure_time' => 'nullable|string',
            'km_rate'        => 'required|numeric|min:0.01',
        ]);

        $tripDate      = Carbon::parse($data['trip_date']);
        $departureTime = $data['departure_time'] ?? '08:00';
        $kmRate        = (float) $data['km_rate'];

        $isPeakHour = $this->isPeakHour($tripDate, $departureTime);

        $mapsResult = $this->callGoogleMaps(
            $data['origin'],
            $data['destination'],
            $tripDate,
            $departureTime
        );

        if (! $mapsResult['ok']) {
            return response()->json(['error' => $mapsResult['message']], 422);
        }

        $distanceKm              = $mapsResult['distance_km'];
        $durationMinutes         = $mapsResult['duration_minutes'];
        $durationInTrafficMinutes= $mapsResult['duration_in_traffic_minutes'];
        $trafficCondition        = $this->trafficCondition($durationMinutes, $durationInTrafficMinutes);

        $baseAmount    = round($distanceKm * $kmRate, 2);
        $peakSurcharge = $isPeakHour ? round($baseAmount * self::PEAK_SURCHARGE_RATE, 2) : 0.00;
        $totalAmount   = $baseAmount + $peakSurcharge;

        return response()->json([
            'distance_km'                   => $distanceKm,
            'duration_minutes'              => $durationMinutes,
            'duration_in_traffic_minutes'   => $durationInTrafficMinutes,
            'is_peak_hour'                  => $isPeakHour,
            'traffic_condition'             => $trafficCondition,
            'km_rate'                       => $kmRate,
            'base_amount'                   => $baseAmount,
            'peak_surcharge'                => $peakSurcharge,
            'total_amount'                  => $totalAmount,
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function callGoogleMaps(string $origin, string $destination, Carbon $date, string $time): array
    {
        $apiKey = AppSetting::get('google_maps_api_key') ?: config('services.google_maps.key');

        if (! $apiKey) {
            return [
                'ok'      => false,
                'message' => 'Google Maps API key not configured. Add it under Settings → Company Setup → API Integrations.',
            ];
        }

        try {
            $departureCarbon = Carbon::parse("{$date->toDateString()} {$time}");
            $isFuture        = $departureCarbon->isFuture();

            $params = [
                'origins'      => $origin,
                'destinations' => $destination,
                'units'        => 'metric',
                'key'          => $apiKey,
            ];

            // departure_time + traffic_model only work for future timestamps
            if ($isFuture) {
                $params['departure_time'] = $departureCarbon->timestamp;
                $params['traffic_model']  = 'best_guess';
            }

            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/distancematrix/json', $params);

            $body = $response->json();

            if (($body['status'] ?? '') !== 'OK') {
                Log::warning('Google Maps Distance Matrix non-OK', ['status' => $body['status'] ?? 'unknown', 'body' => $body]);
                return ['ok' => false, 'message' => 'Maps API error: ' . ($body['status'] ?? 'unknown')];
            }

            $element = $body['rows'][0]['elements'][0] ?? null;

            if (! $element || ($element['status'] ?? '') !== 'OK') {
                return ['ok' => false, 'message' => 'Route not found between the two addresses.'];
            }

            $distanceM   = $element['distance']['value'];       // metres
            $durationSec = $element['duration']['value'];       // seconds (free-flow)
            // duration_in_traffic only present when departure_time was sent (future trips)
            $trafficSec  = $element['duration_in_traffic']['value'] ?? $durationSec;

            return [
                'ok'                           => true,
                'distance_km'                  => round($distanceM / 1000, 3),
                'duration_minutes'             => (int) round($durationSec / 60),
                'duration_in_traffic_minutes'  => (int) round($trafficSec / 60),
            ];
        } catch (\Throwable $e) {
            Log::error('Google Maps API exception', ['message' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Failed to reach Maps API. Please try again.'];
        }
    }

    private function isPeakHour(Carbon $date, string $time): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        $t = Carbon::parse($time)->format('H:i');

        foreach (self::PEAK_WINDOWS as $window) {
            if ($t >= $window['start'] && $t <= $window['end']) {
                return true;
            }
        }

        return false;
    }

    private function trafficCondition(int $freeFlowMinutes, int $trafficMinutes): string
    {
        if ($freeFlowMinutes === 0) {
            return 'unknown';
        }

        $ratio = $trafficMinutes / $freeFlowMinutes;

        if ($ratio >= 1.5) {
            return 'heavy';
        }

        if ($ratio >= 1.15) {
            return 'moderate';
        }

        return 'light';
    }
}
