<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Command;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Instructions to equipment — a fan, a heater set point, a water valve, lights, a feeder — and
 * the states they pass through: requested → (pending approval → approved) → sent → accepted →
 * executed, or rejected, failed, unconfirmed or cancelled.
 *
 * Nothing reaches a device that was not asked for by somebody allowed to, inside the limits the
 * device accepts. A device that needs approval waits for a second person; the requester cannot
 * approve their own command. An agent's recommendation is never a command by itself: it becomes
 * one only after a person approves the recommendation, and then it is held for approval like any
 * other. A command the gateway does not confirm in time is unconfirmed, and somebody is told —
 * nobody assumes the fan came on.
 */
class Commands
{
    public const CONFIRM_SECONDS = 120;

    public function __construct(private readonly Incidents $incidents) {}

    public function request(Device $device, string $command, array $parameters, User $actor, string $reason, ?Recommendation $from = null, ?string $key = null): Command
    {
        if ($key && ($seen = Command::query()->where('idempotency_key', $key)->first())) {
            return $seen;
        }
        $base = ['company_id' => $device->company_id, 'device_id' => $device->id, 'unit_id' => $device->unit_id, 'command' => $command,
            'parameters' => $parameters ?: null, 'reason' => $reason, 'requested_by' => $actor->id, 'idempotency_key' => $key,
            'origin' => $from ? 'recommendation' : 'manual', 'recommendation_id' => $from?->id];
        // Refused requests are kept: who tried to do what is part of the record.
        $refuse = function (string $why) use ($base) {
            Command::create($base + ['status' => 'rejected', 'failure_reason' => $why, 'history' => [$this->step('rejected', null, $why)]]);
            throw ValidationException::withMessages(['command' => $why]);
        };
        if (! $actor->can(RoleCatalog::REQUEST_COMMANDS)) {
            $refuse("{$actor->name} is not allowed to send commands to equipment.");
        }
        if (! $device->controllable || $device->status === 'retired') {
            $refuse("{$device->name} does not accept commands.");
        }
        if ($from && $from->status !== 'approved') {
            $refuse('A recommendation becomes a command only after a person approves it.');
        }
        $allowed = collect($device->allowed_commands ?? [])->firstWhere('command', $command);
        if (! $allowed) {
            $refuse("{$device->name} does not accept '{$command}'.");
        }
        $value = $parameters['value'] ?? null;
        if ($value !== null && ((isset($allowed['min']) && $value < $allowed['min']) || (isset($allowed['max']) && $value > $allowed['max']))) {
            $refuse("{$command} on {$device->name} must be between {$allowed['min']} and {$allowed['max']}.");
        }
        if ($device->status !== 'online') {
            $refuse("{$device->name} is {$device->status}; a command could not be confirmed.");
        }

        return DB::transaction(function () use ($device, $base, $actor) {
            $needs = $device->commands_need_approval;
            $c = Command::create($base + ['status' => $needs ? 'pending_approval' : 'approved', 'history' => [$this->step('requested', $actor)]]);
            if (! $needs) {
                $this->transition($c, 'approved', $actor, 'No approval needed for this device.');

                return $this->send($c);
            }

            return $c;
        });
    }

    public function approve(Command $c, User $approver, ?string $note = null): Command
    {
        if ($c->status !== 'pending_approval') {
            throw ValidationException::withMessages(['command' => "Command is {$c->status}."]);
        }
        if (! $approver->can(RoleCatalog::APPROVE_COMMANDS)) {
            throw ValidationException::withMessages(['command' => "{$approver->name} may not approve equipment commands."]);
        }
        if ((int) $c->requested_by === (int) $approver->id) {
            throw ValidationException::withMessages(['command' => 'A command is approved by somebody other than the person who asked for it.']);
        }
        $c->update(['approved_by' => $approver->id, 'approved_at' => now()]);
        $this->transition($c, 'approved', $approver, $note);

        return $this->send($c->fresh());
    }

    public function reject(Command $c, User $actor, string $why): Command
    {
        if (! in_array($c->status, ['pending_approval', 'requested'], true)) {
            throw ValidationException::withMessages(['command' => "Command is {$c->status}."]);
        }
        $c->update(['failure_reason' => $why]);

        return $this->transition($c, 'rejected', $actor, $why);
    }

    public function cancel(Command $c, User $actor, string $why): Command
    {
        if (! in_array($c->status, ['pending_approval', 'approved', 'sent'], true)) {
            throw ValidationException::withMessages(['command' => "Command is {$c->status}."]);
        }

        return $this->transition($c, 'cancelled', $actor, $why);
    }

    /** What a gateway reports back: accepted, executed or failed. */
    public function report(Command $c, string $state, ?string $detail = null): Command
    {
        if (! in_array($state, ['accepted', 'executed', 'failed'], true)) {
            throw ValidationException::withMessages(['state' => 'A gateway reports accepted, executed or failed.']);
        }
        if (in_array($c->status, Command::FINAL, true)) {
            return $c;                                             // a late duplicate report changes nothing
        }
        $c->update(match ($state) {
            'accepted' => ['accepted_at' => now(), 'confirm_by' => now()->addSeconds(self::CONFIRM_SECONDS)],
            'executed' => ['executed_at' => now(), 'confirm_by' => null],
            'failed' => ['failure_reason' => $detail ?? 'The device reported a failure.', 'confirm_by' => null],
        });
        $this->transition($c, $state, null, $detail);
        if ($state === 'failed') {
            $this->failed($c, $detail ?? 'The device reported a failure.');
        }

        return $c->fresh();
    }

    /** Commands sent or accepted but never confirmed in time. */
    public function sweepUnconfirmed(?Carbon $now = null): int
    {
        $now ??= now();
        $n = 0;
        Command::query()->whereIn('status', ['sent', 'accepted'])->where('confirm_by', '<', $now)->each(function (Command $c) use (&$n) {
            $this->transition($c, 'unconfirmed', null, 'No confirmation from the device in time.');
            $this->failed($c, 'Not confirmed by the device. Check it on site.');
            $n++;
        });

        return $n;
    }

    /** What is waiting for a gateway to collect. */
    public function outbox(Device $gateway)
    {
        return Command::query()->where('status', 'sent')->whereIn('device_id', Device::query()->where('farm_id', $gateway->farm_id)->pluck('id'))->orderBy('id')->get();
    }

    private function send(Command $c): Command
    {
        $c->update(['sent_at' => now(), 'confirm_by' => now()->addSeconds(self::CONFIRM_SECONDS)]);
        $c = $this->transition($c, 'sent', null, 'Queued for the gateway.');
        // A device (or its farm's gateway) with an endpoint is sent the command now as well; either
        // way it is only executed when the equipment says so.
        $device = Device::query()->find($c->device_id);
        $endpoint = $device?->endpoint_url ?: Device::query()->where('farm_id', $device?->farm_id)->where('kind', 'gateway')->whereNotNull('endpoint_url')->value('endpoint_url');
        if ($endpoint) {
            try {
                $r = \Illuminate\Support\Facades\Http::timeout(5)->acceptJson()->post($endpoint, ['id' => $c->uuid, 'device' => $device->code, 'command' => $c->command,
                    'parameters' => $c->parameters ?? (object) [], 'report_to' => url('/api/production/gateways/{gateway}/commands/'.$c->uuid)]);
                $c->update(['history' => [...($c->history ?? []), $this->step('sent', null, $r->successful() ? 'Delivered to the equipment.' : "Push refused ({$r->status()}); waiting for the gateway to collect it.")]]);
            } catch (\Throwable $e) {
                $c->update(['history' => [...($c->history ?? []), $this->step('sent', null, 'Push failed; waiting for the gateway to collect it.')]]);
            }
        }

        return $c->fresh();
    }

    private function failed(Command $c, string $why): void
    {
        $device = Device::query()->find($c->device_id);
        $this->incidents->raise($c->company_id, "command:{$c->id}", ['kind' => 'command_failed', 'severity' => 'warning',
            'title' => "Command {$c->command} on ".($device?->name ?? 'a device').' was not carried out', 'detail' => $why,
            'farm_id' => $device?->farm_id, 'unit_id' => $c->unit_id, 'device_id' => $c->device_id]);
    }

    private function transition(Command $c, string $to, ?User $actor, ?string $note = null): Command
    {
        $c->update(['status' => $to, 'history' => [...($c->history ?? []), $this->step($to, $actor, $note)]]);
        Incidents::broadcast((int) $c->company_id, "command.{$to}");

        return $c->fresh();
    }

    private function step(string $status, ?User $actor, ?string $note = null): array
    {
        return array_filter(['status' => $status, 'at' => now()->toIso8601String(), 'by' => $actor?->name, 'note' => $note]);
    }
}
