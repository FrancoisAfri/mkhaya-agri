<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\HR\Hr\Attendance\Models\ClockDevice as FarmClockDevice;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\HR\Hr\Attendance\Services\ClockDevices;
use App\Modules\Agri\Services\Integrations;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 §14: the integrations page — devices and their scopes, every message with its status,
 * the failed queue with retry, and the reconciliation per integration — and the telematics
 * endpoint, authenticated by the device's own secret and limited to its kind.
 */
class IntegrationController extends AgriController
{
    public function __construct(private readonly Integrations $integrations) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::MANAGE_INTEGRATIONS);
        $companyId = (int) $request->user()->company_id;
        $from = $request->date('from')?->toDateString() ?? today()->subDays(30)->toDateString();
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();
        $status = $request->string('status')->value() ?: null;

        return Inertia::render('Modules/Agri/Integrations', [
            'from' => $from, 'to' => $to, 'status' => $status,
            'reconciliation' => $this->integrations->reconciliation($companyId, $from, $to),
            'events' => IntegrationEvent::query()->when($status, fn ($q) => $q->where('status', $status))->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->orderByRaw("CASE status WHEN 'dead' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END")->orderByDesc('id')->limit(200)->get()
                ->map(fn ($e) => $e->only(['uuid', 'integration', 'direction', 'idempotency_key', 'status', 'attempts', 'replays', 'error', 'result']) + ['at' => $e->created_at?->format('Y-m-d H:i'),
                    'next_attempt_at' => $e->next_attempt_at?->format('Y-m-d H:i'), 'payload' => $e->payload]),
            'devices' => FarmClockDevice::query()->with('farm:id,name')->orderBy('name')->get()->map(fn ($d) => ['name' => $d->name, 'kind' => $d->kind, 'farm' => $d->farm?->name,
                'active' => (bool) $d->is_active, 'last_seen_at' => $d->last_seen_at?->format('Y-m-d H:i'), 'events' => $d->events_received,
                'scope' => ['biometric' => 'Clock events', 'access_control' => 'Clock events', 'kiosk' => 'Clock events', 'sensor_gateway' => 'Store readings', 'telematics' => 'Machine position and meters', 'weighbridge' => 'Grain weighings'][$d->kind] ?? $d->kind]),
        ]);
    }

    public function retry(Request $request, IntegrationEvent $event): RedirectResponse
    {
        $this->allow($request, R::MANAGE_INTEGRATIONS);
        $e = $this->integrations->retry($event, $request->user());

        return back()->with($e->status === 'processed' ? 'success' : 'error', $e->status === 'processed' ? 'Processed.' : "Still failing: {$e->error}");
    }

    /** POST /api/agri/devices/{uuid}/telematics — {"fixes": [{ref, recorded_at, latitude, longitude, engine_hours, odometer_km, id}]} */
    public function telematics(Request $request, string $uuid, TenantContext $tenants): JsonResponse
    {
        $device = app(ClockDevices::class)->authenticate($uuid, $request->bearerToken());
        if (! $device || $device->kind !== 'telematics') {
            return response()->json(['error' => 'Unknown or switched-off telematics device, or a wrong secret.'], 401);
        }
        $fixes = $request->input('fixes');
        if (! is_array($fixes) || ! $fixes || count($fixes) > 500) {
            return response()->json(['error' => 'Send "fixes": a list of 1 to 500 fixes.'], 422);
        }

        return $tenants->run((int) $device->company_id, function () use ($device, $fixes) {
            $out = [];
            foreach ($fixes as $k => $f) {
                $f = is_array($f) ? $f : [];
                $key = isset($f['id']) ? (string) $f['id'] : ($f['ref'] ?? '').'@'.($f['recorded_at'] ?? sha1(json_encode($f)));
                ['event' => $e, 'replayed' => $replayed] = $this->integrations->receive((int) $device->company_id, 'telematics', "{$device->id}:{$key}", $f, $device->id);
                $out[] = ['index' => $k, 'ok' => $e->status === 'processed', 'replayed' => $replayed, 'result' => $e->result, 'error' => $e->status === 'processed' ? null : $e->error];
            }
            $device->update(['last_seen_at' => now(), 'events_received' => (int) $device->events_received + count($fixes)]);

            return response()->json(['results' => $out]);
        });
    }
}
