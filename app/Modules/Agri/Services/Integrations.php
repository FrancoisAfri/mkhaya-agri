<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\MachineUsageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 2 §14: one way in for every integration message.
 *
 * Each message is kept with its idempotency key before anything is done with it. Sent again, it is
 * answered from the first time (and counted as a replay) — nothing is processed twice. If its
 * handler fails, the error is kept and the message waits with a back-off; after a few attempts it
 * is dead and waits for an administrator. A retry runs the same handler on the same message, and
 * the handlers are themselves idempotent, so a retry never duplicates a reading, a clock event or
 * a posting. reconciliation() shows, per integration, what came in and what became of it.
 */
class Integrations
{
    /** integration => handler(array $payload, IntegrationEvent $e): array */
    private function handlers(): array
    {
        return [
            'sensor_gateway' => fn (array $p, IntegrationEvent $e) => $this->sensorReading($p, $e),
            'telematics' => fn (array $p, IntegrationEvent $e) => $this->telematics($p, $e),
            'messaging' => fn (array $p, IntegrationEvent $e) => app(AgriMessaging::class)->respond($p),
            'weather' => fn (array $p, IntegrationEvent $e) => ['hours' => app(WeatherIntelligence::class)->refresh(Farm::withoutGlobalScope('farm_access')->findOrFail($p['farm_id']))],
            'satellite' => fn (array $p, IntegrationEvent $e) => app(SatelliteImagery::class)->handle($p, $e),
        ];
    }

    /** Outbound fetches: the farm asks an outside service, rather than receiving a message. */
    private const OUTBOUND = ['weather', 'satellite'];

    /**
     * Handlers that commit as they go. The satellite fetch stores each scene in its own transaction
     * and counts each paid request at once, so a failure part-way neither forgets what a request
     * cost nor throws away the scenes already stored — the retry carries on from there.
     */
    private const OWN_TRANSACTIONS = ['satellite'];

    /** @return array{event: IntegrationEvent, replayed: bool} */
    public function receive(int $companyId, string $integration, string $key, array $payload, ?int $deviceId = null, ?int $farmId = null): array
    {
        $e = IntegrationEvent::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'integration' => $integration, 'idempotency_key' => mb_strimwidth($key, 0, 160)],
            ['direction' => in_array($integration, self::OUTBOUND, true) ? 'out' : 'in', 'device_id' => $deviceId, 'farm_id' => $farmId, 'payload' => $payload, 'status' => 'received']);
        if (! $e->wasRecentlyCreated && in_array($e->status, ['processed', 'dead'], true)) {
            $e->increment('replays');

            return ['event' => $e, 'replayed' => true];
        }
        $this->process($e);

        return ['event' => $e->fresh(), 'replayed' => false];
    }

    public function process(IntegrationEvent $e): IntegrationEvent
    {
        $handler = $this->handlers()[$e->integration] ?? null;
        if (! $handler) {
            $e->update(['status' => 'dead', 'error' => "No handler for {$e->integration}."]);

            return $e;
        }
        try {
            $result = in_array($e->integration, self::OWN_TRANSACTIONS, true) ? $handler($e->payload, $e) : DB::transaction(fn () => $handler($e->payload, $e));
            $e->update(['status' => 'processed', 'result' => $result, 'error' => null, 'processed_at' => now(), 'attempts' => $e->attempts + 1, 'next_attempt_at' => null]);
        } catch (Throwable $x) {
            $attempts = $e->attempts + 1;
            $e->update(['status' => $attempts >= IntegrationEvent::MAX_ATTEMPTS ? 'dead' : 'failed', 'attempts' => $attempts,
                'error' => mb_strimwidth(class_basename($x).': '.$x->getMessage(), 0, 1000), 'next_attempt_at' => now()->addMinutes(5 * 2 ** min(6, $attempts - 1))]);
        }

        return $e;
    }

    /** An administrator's retry of a failed or dead message. */
    public function retry(IntegrationEvent $e, User $actor): IntegrationEvent
    {
        if (! in_array($e->status, ['failed', 'dead'], true)) {
            throw ValidationException::withMessages(['event' => "That message is {$e->status}; only failed ones are retried."]);
        }
        $e->update(['retried_by' => $actor->id, 'status' => 'received']);

        return $this->process($e);
    }

    /** The scheduled retry: failed messages whose back-off has passed. */
    public function retryDue(int $companyId): int
    {
        $n = 0;
        foreach (IntegrationEvent::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'failed')->where('next_attempt_at', '<=', now())->limit(200)->get() as $e) {
            $this->process($e);
            $n++;
        }

        return $n;
    }

    public function reconciliation(int $companyId, string $from, string $to): array
    {
        return IntegrationEvent::withoutGlobalScopes()->where('company_id', $companyId)->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get()->groupBy('integration')->map(fn ($g, $name) => ['integration' => $name, 'received' => $g->count(), 'processed' => $g->where('status', 'processed')->count(),
                'failed' => $g->where('status', 'failed')->count(), 'dead' => $g->where('status', 'dead')->count(), 'replays_answered' => (int) $g->sum('replays'),
                'last' => $g->max('created_at')?->format('Y-m-d H:i')])->values()->all();
    }

    // ── handlers ─────────────────────────────────────────────────────────────────────────────

    private function sensorReading(array $p, IntegrationEvent $e): array
    {
        $store = FarmStore::withoutGlobalScopes()->where('company_id', $e->company_id)->where('sensor_ref', (string) ($p['sensor'] ?? ''))->first();
        if (! $store) {
            throw new \RuntimeException('No store has sensor reference "'.($p['sensor'] ?? '').'".');
        }
        if (! isset($p['temperature']) && ! isset($p['humidity'])) {
            throw new \RuntimeException('No temperature or humidity in the reading.');
        }
        $r = app(PostHarvest::class)->reading($store, ['temperature' => is_numeric($p['temperature'] ?? null) ? $p['temperature'] : null,
            'humidity' => is_numeric($p['humidity'] ?? null) ? $p['humidity'] : null, 'recorded_at' => $p['recorded_at'] ?? null,
            'other' => is_array($p['other'] ?? null) ? $p['other'] : null, 'client_id' => mb_strimwidth($e->idempotency_key, 0, 64)], null, 'sensor');

        return ['reading' => $r->uuid, 'out_of_range' => $r->out_of_range];
    }

    /**
     * A telematics fix: position, engine hours, odometer. Meters only move forward (an older fix
     * arriving late changes nothing); hours gained are logged as machine use.
     */
    private function telematics(array $p, IntegrationEvent $e): array
    {
        $m = FarmMachine::withoutGlobalScope('farm_access')->where('company_id', $e->company_id)->where('telematics_ref', (string) ($p['ref'] ?? ''))->first();
        if (! $m) {
            throw new \RuntimeException('No machine has telematics reference "'.($p['ref'] ?? '').'".');
        }
        $at = Carbon::parse($p['recorded_at'] ?? now());
        $hours = isset($p['engine_hours']) && is_numeric($p['engine_hours']) ? (float) $p['engine_hours'] : null;
        $gain = $hours !== null ? round($hours - (float) $m->hours_reading, 2) : 0;
        $fresher = ! $m->last_seen_at || $at->gt($m->last_seen_at);
        $u = $fresher ? ['last_latitude' => $p['latitude'] ?? $m->last_latitude, 'last_longitude' => $p['longitude'] ?? $m->last_longitude, 'last_seen_at' => $at] : [];
        if ($gain > 0 && $gain <= 24) {
            MachineUsageLog::withoutGlobalScopes()->create(['company_id' => $m->company_id, 'farm_id' => $m->farm_id, 'vehicle_id' => $m->id, 'kind' => 'use', 'used_on' => $at->toDateString(),
                'hours' => $gain, 'hours_reading_after' => $hours, 'notes' => 'Telematics']);
            $u['hours_reading'] = $hours;
        }
        if (isset($p['odometer_km']) && is_numeric($p['odometer_km']) && (float) $p['odometer_km'] > (float) ($m->odometer_km ?? 0)) {
            $u['odometer_km'] = (float) $p['odometer_km'];
        }
        if ($u) {
            $m->forceFill($u)->saveQuietly();
        }

        return ['machine' => $m->code, 'hours_gained' => $gain > 0 && $gain <= 24 ? $gain : 0, 'position_updated' => $fresher,
            'flag' => $gain > 24 ? "Engine hours jumped {$gain} h in one fix; not logged as use — check the meter." : null];
    }
}
