<?php

namespace App\Modules\HR\Hr\Attendance\Http\Controllers;

use App\Modules\HR\Hr\Attendance\Models\ClockDevice;
use App\Modules\HR\Hr\Attendance\Services\ClockDevices;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 2 §1.3: registering clock devices (screens), and the endpoint the devices themselves call.
 * A device's secret is returned once, to the person registering it, and never shown again.
 */
class ClockDeviceController extends EngineController
{
    public function __construct(private readonly ClockDevices $devices) {}

    public function store(Request $request): JsonResponse
    {
        $this->allow($request, R::MANAGE_LABOUR_RULES);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'name' => ['required', 'string', 'max:120'], 'kind' => ['required', Rule::in(ClockDevice::KINDS)]]);
        [$device, $secret] = $this->devices->register($this->workingFarm($request, $data['farm_id'] ?? null), $data['name'], $data['kind'], $request->user());

        return response()->json($this->issued($device, $secret));
    }

    public function renew(Request $request, ClockDevice $device): JsonResponse
    {
        $this->allow($request, R::MANAGE_LABOUR_RULES);
        $this->assertWritable($request, $device->farm_id);
        [$device, $secret] = $this->devices->newSecret($device);

        return response()->json($this->issued($device, $secret));
    }

    public function toggle(Request $request, ClockDevice $device): RedirectResponse
    {
        $this->allow($request, R::MANAGE_LABOUR_RULES);
        $this->assertWritable($request, $device->farm_id);
        $device->update(['is_active' => ! $device->is_active]);

        return back()->with('success', "{$device->name} ".($device->is_active ? 'can send attendance again.' : 'is switched off; it can no longer send attendance.'));
    }

    /**
     * Called by the device: `Authorization: Bearer <secret>` and `{"events": [{event_id, badge |
     * employee_number, kind: in|out|auto, occurred_at}]}`. Each event comes back applied or
     * rejected with the reason; an event already received is answered again, never applied twice.
     */
    public function receive(Request $request, string $uuid, TenantContext $tenants): JsonResponse
    {
        $device = $this->devices->authenticate($uuid, $request->bearerToken());
        if (! $device) {
            return response()->json(['error' => 'Unknown or switched-off device, or a wrong secret.'], 401);
        }
        $events = $request->input('events');
        if (! is_array($events) || ! $events || count($events) > ClockDevices::MAX_BATCH) {
            return response()->json(['error' => 'Send "events": a list of 1 to '.ClockDevices::MAX_BATCH.' clock events.'], 422);
        }

        return $tenants->run((int) $device->company_id, fn () => response()->json(['results' => $this->devices->receive($device, $events)]));
    }

    private function issued(ClockDevice $device, string $secret): array
    {
        return ['id' => $device->uuid, 'name' => $device->name, 'secret' => $secret, 'endpoint' => route('api.attendance.devices.clock', $device->uuid)];
    }
}
