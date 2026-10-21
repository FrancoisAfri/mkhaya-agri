<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Command;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gateways: the boxes on a farm that talk to its sensors and controllers.
 *
 * A gateway is issued a secret once (only its hash is kept) and authenticates every call with it.
 * It posts readings for the sensors on its own farm, collects the commands waiting for its farm,
 * and reports back what the equipment did. A reading for a device on another farm, or for a device
 * it does not know, is refused by name and never stored.
 */
class Gateways
{
    public const MAX_BATCH = 5000;

    public function __construct(private readonly Readings $readings, private readonly Commands $commands) {}

    /** A new secret for a gateway, shown once. */
    public function issueSecret(Device $gateway): string
    {
        $secret = 'prdg_'.Str::random(48);
        $gateway->forceFill(['secret_hash' => hash('sha256', $secret), 'secret_issued_at' => now()])->save();

        return $secret;
    }

    public function authenticate(string $uuid, ?string $secret): ?Device
    {
        if (! $secret || ! Str::isUuid($uuid)) {
            return null;
        }
        $g = Device::withoutGlobalScopes()->where('uuid', $uuid)->where('kind', 'gateway')->where('status', '!=', 'retired')->first();

        return $g && $g->secret_hash && hash_equals($g->secret_hash, hash('sha256', $secret)) ? $g : null;
    }

    /**
     * @param  list<array{device: string, message_id: string, value: float, measured_at: string, metric?: ?string}>  $readings
     * @return array{stored: int, duplicates: int, out_of_order: int, incidents: list<string>, refused: list<array{device: string, reason: string}>}
     */
    public function receive(Device $gateway, array $readings): array
    {
        if (count($readings) > self::MAX_BATCH) {
            throw ValidationException::withMessages(['readings' => 'Send at most '.self::MAX_BATCH.' readings at a time.']);
        }
        $gateway->forceFill(['last_seen_at' => now(), 'status' => 'online'])->save();
        $devices = Device::query()->where('farm_id', $gateway->farm_id)->whereIn('code', collect($readings)->pluck('device')->unique()->all())->get()->keyBy('code');
        $out = ['stored' => 0, 'duplicates' => 0, 'out_of_order' => 0, 'incidents' => [], 'refused' => []];
        foreach (collect($readings)->groupBy('device') as $code => $rows) {
            $device = $devices[$code] ?? null;
            if (! $device || $device->kind === 'gateway') {
                $out['refused'][] = ['device' => (string) $code, 'reason' => 'Not a sensor on this gateway\'s farm.'];

                continue;
            }
            $bad = $rows->first(fn ($r) => ! isset($r['message_id'], $r['value'], $r['measured_at']) || ! is_numeric($r['value']) || strtotime((string) $r['measured_at']) === false);
            if ($bad) {
                $out['refused'][] = ['device' => (string) $code, 'reason' => 'Each reading needs message_id, a numeric value and measured_at.'];

                continue;
            }
            $r = $this->readings->ingest($device, $rows->map(fn ($x) => collect($x)->only(['message_id', 'value', 'measured_at', 'metric'])->all())->values()->all());
            $out['stored'] += $r['stored'];
            $out['duplicates'] += $r['duplicates'];
            $out['out_of_order'] += $r['out_of_order'];
            $out['incidents'] = [...$out['incidents'], ...$r['incidents']];
        }

        return $out;
    }

    /** Commands waiting for this gateway's farm, oldest first. */
    public function outbox(Device $gateway): array
    {
        $gateway->forceFill(['last_seen_at' => now(), 'status' => 'online'])->save();

        return $this->commands->outbox($gateway)->map(fn (Command $c) => ['id' => $c->uuid, 'device' => Device::query()->whereKey($c->device_id)->value('code'),
            'command' => $c->command, 'parameters' => $c->parameters ?? (object) [], 'sent_at' => $c->sent_at?->toIso8601String(),
            'confirm_by' => $c->confirm_by?->toIso8601String()])->values()->all();
    }

    public function report(Device $gateway, string $commandUuid, string $state, ?string $detail): Command
    {
        $c = Command::query()->where('uuid', $commandUuid)->firstOrFail();
        abort_unless((int) Device::query()->whereKey($c->device_id)->value('farm_id') === (int) $gateway->farm_id, 404);

        return $this->commands->report($c, $state, $detail);
    }
}
