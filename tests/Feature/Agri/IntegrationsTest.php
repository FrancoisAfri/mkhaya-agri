<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Agri\Models\StorageReading;
use App\Modules\HR\Hr\Attendance\Services\ClockDevices;
use App\Modules\Agri\Services\FarmInventory;
use App\Modules\Agri\Services\Integrations;

/**
 * Phase 2 §14: integrations are authenticated per device and limited to its kind; every message is
 * logged with its idempotency key; a repeat is answered from the first; a failure waits in the
 * queue and an administrator's retry recovers it without duplicating anything; and the
 * reconciliation shows what came in and what became of it.
 */
class IntegrationsTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    public function test_messages_are_logged_once_failures_queue_and_a_retry_recovers_them_without_duplicates(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $cold = $this->asCompany($this->company, fn () => app(FarmInventory::class)->addStore($farm, ['kind' => 'cold_room', 'name' => 'Cold room']));
        $this->asCompany($this->company, fn () => $cold->update(['sensor_ref' => 'CR1', 'temperature_min' => 0, 'temperature_max' => 4]));
        [$gateway, $gs] = $this->asCompany($this->company, fn () => app(ClockDevices::class)->register($farm, 'Gateway', 'sensor_gateway', $this->manager));
        [$tracker, $ts] = $this->asCompany($this->company, fn () => app(ClockDevices::class)->register($farm, 'Tracker', 'telematics', $this->manager));

        // Scopes: a sensor gateway cannot send telematics, nor the reverse.
        $this->withToken($gs)->postJson(route('api.agri.devices.telematics', $gateway->uuid), ['fixes' => [['ref' => 'X']]])->assertUnauthorized();
        $this->withToken($ts)->postJson(route('api.agri.devices.readings', $tracker->uuid), ['readings' => [['sensor' => 'CR1', 'temperature' => 2]]])->assertUnauthorized();

        // A reading for a sensor nobody has set up yet fails and waits; the good one is processed; a repeat is answered from the first.
        $body = ['readings' => [['sensor' => 'CR1', 'temperature' => 2.5, 'client_id' => 'r-1'], ['sensor' => 'CR2', 'temperature' => 3.1, 'client_id' => 'r-2']]];
        $r = $this->withToken($gs)->postJson(route('api.agri.devices.readings', $gateway->uuid), $body)->assertOk()->json('results');
        $this->assertTrue($r[0]['ok']);
        $this->assertFalse($r[1]['ok']);
        $this->assertStringContainsString('CR2', $r[1]['error']);
        $again = $this->withToken($gs)->postJson(route('api.agri.devices.readings', $gateway->uuid), $body)->assertOk()->json('results');
        $this->assertTrue($again[0]['replayed']);
        $this->assertSame(1, StorageReading::withoutGlobalScopes()->count());
        $failed = IntegrationEvent::withoutGlobalScopes()->where('status', 'failed')->sole();
        $this->assertSame(2, $failed->attempts, 'Sent twice, tried twice — still one message.');

        // The store is set up; an administrator retries: processed once, and retrying again is refused.
        $this->asCompany($this->company, fn () => app(FarmInventory::class)->addStore($farm, ['kind' => 'cold_room', 'name' => 'Cold room 2'])->update(['sensor_ref' => 'CR2']));
        $this->actingAs($this->worker)->post(route('agri.integrations.retry', $failed->uuid))->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.integrations.retry', $failed->uuid))->assertSessionHasNoErrors();
        $this->assertSame('processed', $failed->fresh()->status);
        $this->actingAs($this->manager)->post(route('agri.integrations.retry', $failed->uuid))->assertSessionHasErrors('event');
        $this->assertSame(2, StorageReading::withoutGlobalScopes()->count());

        // Telematics: hours forward become use; an older fix arriving late moves nothing back; an implausible jump is flagged.
        $tractor = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm, ['code' => 'T1', 'name' => 'Tractor', 'machine_type' => 'tractor', 'hours_reading' => 100]));
        $this->asCompany($this->company, fn () => $tractor->update(['telematics_ref' => 'JD-8R-01']));
        $fix = fn (string $at, float $h, string $id) => ['ref' => 'JD-8R-01', 'recorded_at' => $at, 'latitude' => -26.1, 'longitude' => 28.0, 'engine_hours' => $h, 'id' => $id];
        $this->withToken($ts)->postJson(route('api.agri.devices.telematics', $tracker->uuid), ['fixes' => [$fix(now()->subHour()->toIso8601String(), 103.5, 'f1'),
            $fix(now()->subHours(3)->toIso8601String(), 101, 'f0'), $fix(now()->toIso8601String(), 160, 'f2')]])->assertOk();
        $this->assertEqualsWithDelta(103.5, (float) $tractor->fresh()->hours_reading, 0.01);
        $this->assertEqualsWithDelta(3.5, (float) MachineUsageLog::withoutGlobalScopes()->where('machine_id', $tractor->id)->sum('hours'), 0.01);
        $this->assertNotNull($tractor->fresh()->last_seen_at);
        $jump = IntegrationEvent::withoutGlobalScopes()->where('integration', 'telematics')->where('idempotency_key', 'like', '%:f2')->sole();
        $this->assertStringContainsString('jumped', $jump->result['flag']);

        // Reconciliation.
        $rec = collect($this->asCompany($this->company, fn () => app(Integrations::class)->reconciliation($this->company->id, today()->toDateString(), today()->toDateString())))->keyBy('integration');
        $this->assertSame(2, $rec['sensor_gateway']['received']);
        $this->assertSame(2, $rec['sensor_gateway']['processed']);
        $this->assertSame(1, $rec['sensor_gateway']['replays_answered']);
        $this->assertSame(3, $rec['telematics']['processed']);
        $this->actingAs($this->manager)->get(route('agri.integrations.index'))->assertOk();
        $this->artisan('agri:integrations-retry', ['--company' => $this->company->id])->assertSuccessful();
    }
}
