<?php

namespace App\Modules\HR\Hr\Attendance\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Model;
use App\Modules\HR\Hr\Attendance\Models\ClockDevice;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §1.3: biometric readers, access-control gates and kiosks that send attendance on their
 * own. Each device belongs to one farm and has its own secret, shown once when it is registered;
 * only its hash is kept. A device sends a batch of events, each with its own id, so a batch sent
 * twice is applied once. The device identifies people by their badge or employee number; it never
 * sends or stores biometric templates — matching happens on the device.
 */
class ClockDevices
{
    public const MAX_BATCH = 500;

    public function __construct(private readonly Attendance $attendance) {}

    /** @return array{0: ClockDevice, 1: string} the device and its secret (shown once) */
    public function register(?Model $site, string $name, string $kind, User $actor): array
    {
        Sites::assertOperational($site);
        if (! in_array($kind, ClockDevice::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown device type.']);
        }
        $secret = 'agd_'.Str::random(40);
        $device = ClockDevice::create(['company_id' => $site?->company_id ?? $actor->company_id, 'farm_id' => $site?->id, 'name' => $name, 'kind' => $kind,
            'token_hash' => hash('sha256', $secret), 'created_by' => $actor->id]);

        return [$device, $secret];
    }

    /** @return array{0: ClockDevice, 1: string} */
    public function newSecret(ClockDevice $device): array
    {
        $secret = 'agd_'.Str::random(40);
        $device->update(['token_hash' => hash('sha256', $secret)]);

        return [$device, $secret];
    }

    /** The device a secret belongs to, if it is active. Looked up before any tenant is known. */
    public function authenticate(string $uuid, ?string $secret): ?ClockDevice
    {
        $device = ClockDevice::withoutGlobalScopes()->where('uuid', $uuid)->where('is_active', true)->first();

        return $device && $secret && hash_equals($device->token_hash, hash('sha256', $secret)) ? $device : null;
    }

    /**
     * @param  list<array{event_id?: string, badge?: string, employee_number?: string, kind?: string, occurred_at?: string}>  $events
     * @return list<array{event_id: ?string, status: string, message: string}>
     */
    public function receive(ClockDevice $device, array $events): array
    {
        $method = ['biometric' => 'biometric', 'access_control' => 'gate', 'kiosk' => 'kiosk'][$device->kind];
        $results = [];
        foreach (array_slice($events, 0, self::MAX_BATCH) as $e) {
            $id = isset($e['event_id']) ? (string) $e['event_id'] : null;
            try {
                if (! $id || ! Str::isUuid($id)) {
                    throw ValidationException::withMessages(['event_id' => 'Each event needs its own event_id (a UUID).']);
                }
                $at = isset($e['occurred_at']) ? Carbon::parse($e['occurred_at']) : null;
                if (! $at) {
                    throw ValidationException::withMessages(['occurred_at' => 'occurred_at is required.']);
                }
                $worker = $this->worker($device, $e);
                $kind = in_array($e['kind'] ?? 'auto', ['in', 'out'], true) ? $e['kind'] : $this->attendance->nextKind($worker, $at);
                $event = $this->attendance->clock($worker, $kind, $at, $method, ['farm_id' => $device->farm_id, 'client_id' => $id,
                    'device_ref' => mb_strimwidth($device->name, 0, 80), 'supervisor_confirmed' => true], null);
                $results[] = ['event_id' => $id, 'status' => 'applied', 'message' => "{$worker->displayName()} clocked {$event->kind} at ".$event->occurred_at->format('H:i')
                    .($event->verification !== 'verified' ? ' (flagged: '.$event->verification_note.')' : '')];
            } catch (ValidationException $ex) {
                $results[] = ['event_id' => $id, 'status' => 'rejected', 'message' => collect($ex->errors())->flatten()->first()];
            }
        }
        $device->forceFill(['last_seen_at' => now(), 'events_received' => $device->events_received + count($results)])->save();

        return $results;
    }

    /** By badge, or by HR employee number — only someone registered on this organisation's farms. */
    private function worker(ClockDevice $device, array $e): Worker
    {
        $q = Worker::withoutGlobalScopes()->where('company_id', $device->company_id);
        if (! empty($e['badge'])) {
            $worker = $q->where('badge_token', preg_replace('/^MKAGRI:/', '', trim((string) $e['badge'])))->first();
        } elseif (! empty($e['employee_number'])) {
            $worker = $q->whereHas('person', fn ($p) => $p->where('company_id', $device->company_id)->where('employee_number', (string) $e['employee_number']))->first();
        } else {
            $worker = null;
        }
        if (! $worker) {
            throw ValidationException::withMessages(['worker' => 'Nobody with that badge or employee number is registered on these farms.']);
        }

        return $worker;
    }
}
