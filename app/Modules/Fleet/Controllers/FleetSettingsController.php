<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetSettingsController extends Controller
{
    private function defaultSettings(): array
    {
        return [
            'new_vehicle_approval'          => ['value' => 'false', 'cast' => 'boolean', 'label' => 'Require approval for new vehicles'],
            'booking_auto_approve'          => ['value' => 'false', 'cast' => 'boolean', 'label' => 'Auto-approve bookings'],
            'booking_require_security_check' => ['value' => 'true',  'cast' => 'boolean', 'label' => 'Require security check before checkout'],
            'fuel_alert_per_litre_threshold' => ['value' => '30',    'cast' => 'float',   'label' => 'Fuel alert — price per litre (R)'],
            'card_daily_limit_default'       => ['value' => '500',   'cast' => 'float',   'label' => 'Default card daily limit (R)'],
            'card_monthly_limit_default'     => ['value' => '5000',  'cast' => 'float',   'label' => 'Default card monthly limit (R)'],
            'doc_expiry_alert_days'          => ['value' => '30',    'cast' => 'integer', 'label' => 'Document expiry alert (days before)'],
            'maintenance_alert_days'         => ['value' => '7',     'cast' => 'integer', 'label' => 'Maintenance due alert (days before)'],
            'odometer_unit'                  => ['value' => 'km',    'cast' => 'string',  'label' => 'Odometer unit (km / miles)'],
            'booking_reference_prefix'       => ['value' => 'BK',    'cast' => 'string',  'label' => 'Booking reference prefix'],
        ];
    }

    public function index(): Response
    {
        $stored = DB::table('fleet_settings')->pluck('value', 'key');
        $defaults = $this->defaultSettings();

        $settings = collect($defaults)->map(fn($def, $key) => [
            'key'   => $key,
            'label' => $def['label'],
            'value' => $stored[$key] ?? $def['value'],
            'cast'  => $def['cast'],
        ])->values();

        return Inertia::render('Modules/Fleet/Settings/Index', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings'         => 'required|array',
            'settings.*.key'   => 'required|string',
            'settings.*.value' => 'nullable|string',
        ]);

        foreach ($data['settings'] as $setting) {
            DB::table('fleet_settings')->updateOrInsert(
                ['key' => $setting['key']],
                ['value' => $setting['value'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return back()->with('success', 'Fleet settings saved.');
    }
}
