<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetNotificationPreference;
use App\Modules\Fleet\Models\FleetPushSubscription;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FleetMobileController extends Controller
{
    // ── FR-5.4 Notification preferences ───────────────────────────────────────

    public function notificationPreferences(): Response
    {
        $userId = Auth::id();

        $saved = FleetNotificationPreference::where('user_id', $userId)
            ->get()
            ->keyBy(fn($p) => "{$p->channel}.{$p->event_type}");

        $matrix = [];
        foreach (FleetNotificationPreference::CHANNELS as $channel) {
            foreach (FleetNotificationPreference::EVENTS as $eventKey => $eventLabel) {
                $key = "{$channel}.{$eventKey}";
                $matrix[$channel][$eventKey] = [
                    'label'   => $eventLabel,
                    'enabled' => isset($saved[$key]) ? $saved[$key]->enabled : true,
                ];
            }
        }

        return Inertia::render('Modules/Fleet/Notifications/Preferences', [
            'matrix'   => $matrix,
            'channels' => FleetNotificationPreference::CHANNELS,
            'events'   => FleetNotificationPreference::EVENTS,
            'vapidKey' => config('fleet.vapid_public_key', ''),
        ]);
    }

    public function saveNotificationPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences'            => 'required|array',
            'preferences.*.channel'  => 'required|in:' . implode(',', FleetNotificationPreference::CHANNELS),
            'preferences.*.event'    => 'required|string',
            'preferences.*.enabled'  => 'required|boolean',
        ]);

        $userId = Auth::id();

        foreach ($data['preferences'] as $pref) {
            FleetNotificationPreference::updateOrCreate(
                ['user_id' => $userId, 'channel' => $pref['channel'], 'event_type' => $pref['event']],
                ['enabled' => $pref['enabled']]
            );
        }

        return response()->json(['saved' => true]);
    }

    // ── FR-5.1 Web Push subscriptions ─────────────────────────────────────────

    public function subscribePush(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint'     => 'required|url',
            'p256dh_key'   => 'required|string',
            'auth_key'     => 'required|string',
            'device_label' => 'nullable|string|max:100',
        ]);

        FleetPushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id'      => Auth::id(),
                'p256dh_key'   => $data['p256dh_key'],
                'auth_key'     => $data['auth_key'],
                'device_label' => $data['device_label'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return response()->json(['subscribed' => true]);
    }

    public function unsubscribePush(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string']);

        FleetPushSubscription::where('user_id', Auth::id())
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['unsubscribed' => true]);
    }

    // ── FR-5.2 QR code page (data returned; QR rendered client-side) ──────────

    public function vehicleQr(FleetVehicle $vehicle): Response
    {
        return Inertia::render('Modules/Fleet/Vehicles/QrCode', [
            'vehicle' => [
                'id'                  => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'make'                => $vehicle->make,
                'model'               => $vehicle->model,
                'year'                => $vehicle->year,
                'color'               => $vehicle->color,
            ],
            'qrValue' => url("/fleet/vehicles/{$vehicle->id}"),
        ]);
    }

    // ── FR-5.1 Mobile inspection page ─────────────────────────────────────────

    public function mobileInspection(int $bookingId): Response
    {
        return Inertia::render('Modules/Fleet/Mobile/Inspection', [
            'bookingId' => $bookingId,
        ]);
    }
}
