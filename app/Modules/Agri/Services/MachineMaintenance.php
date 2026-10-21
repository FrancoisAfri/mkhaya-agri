<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\MachineFault;
use App\Modules\Agri\Models\MachinePrestartCheck;
use App\Modules\Agri\Models\MachineService;
use App\Modules\Agri\Models\MachineWarrantyClaim;
use App\Modules\Agri\Models\WorkOrderMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §7.3: keeping machines working. The operator's pre-start check; faults, reported from the
 * check, the field or the workshop; a repair work order that takes parts from the farm's stores and
 * the technicians' time like any other job, its cost landing on the machine; the downtime it caused;
 * the return to service, approved by someone authorised; and warranty claims.
 *
 * The moment a machine stops, every booking on it that is still to come is flagged and the jobs it
 * touches are listed on the fault (§7 acceptance), so nobody finds out on the morning of the job.
 */
class MachineMaintenance
{
    /** The daily check, by kind of machine. A critical item that fails stops the machine. */
    public const PRESTART = [
        'default' => [['item' => 'Engine oil level', 'critical' => true], ['item' => 'Coolant level', 'critical' => true], ['item' => 'Hydraulic oil and leaks', 'critical' => true],
            ['item' => 'Tyres and wheel nuts', 'critical' => true], ['item' => 'Brakes', 'critical' => true], ['item' => 'Lights and indicators', 'critical' => false],
            ['item' => 'Guards and PTO shield in place', 'critical' => true], ['item' => 'Fire extinguisher', 'critical' => false], ['item' => 'Seat belt and ROPS', 'critical' => true],
            ['item' => 'Greasing done', 'critical' => false]],
        'sprayer' => [['item' => 'Nozzles and filters', 'critical' => false], ['item' => 'Boom and hoses for leaks', 'critical' => true], ['item' => 'Calibration in date', 'critical' => true],
            ['item' => 'Clean-water tank full', 'critical' => true], ['item' => 'Tyres and hitch', 'critical' => true]],
        'pump' => [['item' => 'Oil level', 'critical' => true], ['item' => 'Seals and leaks', 'critical' => true], ['item' => 'Pressure gauge reading', 'critical' => false],
            ['item' => 'Electrical isolator and earth', 'critical' => true]],
        'irrigation' => [['item' => 'Tower alignment', 'critical' => true], ['item' => 'Tyres', 'critical' => false], ['item' => 'Sprinklers and end gun', 'critical' => false],
            ['item' => 'Panel and safety stop', 'critical' => true]],
        'generator' => [['item' => 'Oil and fuel level', 'critical' => true], ['item' => 'Battery', 'critical' => false], ['item' => 'Earth and changeover switch', 'critical' => true]],
    ];

    public function __construct(private readonly WorkOrders $orders) {}

    /** @return list<array{item: string, critical: bool}> */
    public function checklistFor(FarmMachine $machine): array
    {
        return self::PRESTART[$machine->machine_type] ?? self::PRESTART['default'];
    }

    /**
     * Record the check. Readings move the meters forward only. A failed critical item opens a fault
     * that stops the machine; a failed minor one is a fault that lets it keep working.
     *
     * @param  array<int, array{item: string, ok: bool, note?: ?string}>  $results
     */
    public function prestart(FarmMachine $machine, array $results, array $data, User $actor): MachinePrestartCheck
    {
        if ($machine->status === FarmMachine::DISPOSED) {
            throw ValidationException::withMessages(['machine' => "{$machine->code} has been disposed of."]);
        }
        $list = collect($this->checklistFor($machine))->keyBy('item');
        $items = collect($results)->map(fn ($r) => ['item' => $r['item'], 'ok' => (bool) $r['ok'], 'critical' => (bool) ($list[$r['item']]['critical'] ?? false),
            'note' => $r['note'] ?? null])->values();
        $missing = $list->keys()->diff($items->pluck('item'));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Check every item: '.$missing->implode(', ').'.']);
        }
        $this->assertReadings($machine, $data['hours_reading'] ?? null, $data['odometer_km'] ?? null);
        $failed = $items->where('ok', false);
        $critical = $failed->where('critical', true);

        return DB::transaction(function () use ($machine, $items, $data, $actor, $failed, $critical) {
            $check = MachinePrestartCheck::create(['company_id' => $machine->company_id, 'farm_id' => $machine->farm_id, 'vehicle_id' => $machine->id,
                'checked_on' => $data['checked_on'] ?? today()->toDateString(), 'operator_id' => $data['operator_id'] ?? $actor->id,
                'hours_reading' => $data['hours_reading'] ?? null, 'odometer_km' => $data['odometer_km'] ?? null, 'items' => $items->all(),
                'passed' => $critical->isEmpty(), 'recorded_by' => $actor->id]);
            $this->moveMeters($machine, $data['hours_reading'] ?? null, $data['odometer_km'] ?? null);
            if ($failed->isNotEmpty()) {
                $fault = $this->report($machine, $critical->isNotEmpty() ? 'critical' : 'minor',
                    'Pre-start check: '.$failed->map(fn ($f) => $f['item'].($f['note'] ? " ({$f['note']})" : ''))->implode('; '),
                    $critical->isNotEmpty(), $actor, 'prestart');
                $check->update(['fault_id' => $fault->id]);
            }

            return $check;
        });
    }

    /**
     * A fault. One that stops the machine takes it out of service, flags every booking still to
     * come and lists the jobs affected, with who to tell.
     */
    public function report(FarmMachine $machine, string $severity, string $description, bool $stops, User $actor, string $source = 'report'): MachineFault
    {
        if (! in_array($severity, MachineFault::SEVERITIES, true)) {
            throw ValidationException::withMessages(['severity' => 'Say how serious the fault is.']);
        }
        if ($machine->status === FarmMachine::DISPOSED) {
            throw ValidationException::withMessages(['machine' => "{$machine->code} has been disposed of."]);
        }

        return DB::transaction(function () use ($machine, $severity, $description, $stops, $actor, $source) {
            $stops = $stops || $severity === 'critical';
            $affected = $stops ? $this->orders->affectedBy($machine) : [];
            $fault = MachineFault::create(['company_id' => $machine->company_id, 'farm_id' => $machine->farm_id, 'vehicle_id' => $machine->id,
                'severity' => $severity, 'description' => mb_strimwidth($description, 0, 1000), 'source' => $source, 'stops_machine' => $stops,
                'status' => 'open', 'down_from' => $stops ? now() : null, 'reported_by' => $actor->id, 'affected_work_orders' => $affected ?: null]);
            if ($stops) {
                $this->takeOut($machine, "Fault: {$fault->description}");
            }

            return $fault;
        });
    }

    /** Out of service for a reason other than a fault — waiting for parts, an inspection lapsed, parked for the season. */
    public function takeOut(FarmMachine $machine, string $reason): array
    {
        if ($machine->status !== FarmMachine::MAINTENANCE) {
            $machine->update(['status' => FarmMachine::OUT_OF_SERVICE, 'out_of_service_reason' => mb_strimwidth($reason, 0, 300)]);
        }
        $affected = $this->orders->affectedBy($machine);
        WorkOrderMachine::withoutGlobalScopes()->where('machine_id', $machine->id)->whereIn('status', ['requested', 'approved'])->where('booked_to', '>', now())
            ->update(['conflict' => mb_strimwidth("{$machine->code} is out of service: {$reason}", 0, 300)]);
        if ($affected) {
            app(AgriNotifier::class)->machineDown($machine, $reason, $affected);
        }

        return $affected;
    }

    /**
     * The repair job: a work order on the machine (category machinery_service). Its parts come from
     * the farm's stores (the workshop first), its labour is the technicians' approved time, and its
     * cost is posted against the machine.
     */
    public function openRepair(MachineFault $fault, array $data, User $actor): WO
    {
        if (! in_array($fault->status, ['open'], true) || $fault->work_order_id) {
            throw ValidationException::withMessages(['fault' => 'That fault already has a repair job.']);
        }
        $machine = FarmMachine::withoutGlobalScopes()->findOrFail($fault->machine_id);

        return DB::transaction(function () use ($fault, $machine, $data, $actor) {
            $wo = $this->orders->create($machine->farm, [
                'category' => 'machinery_service', 'title' => $data['title'] ?? "Repair {$machine->code}: ".mb_strimwidth($fault->description, 0, 80, '…'),
                'instructions' => $data['instructions'] ?? $fault->description, 'priority' => $fault->severity === 'critical' ? 'urgent' : 'high',
                'planned_start' => $data['planned_start'] ?? now()->toDateTimeString(), 'planned_end' => $data['planned_end'] ?? now()->addDay()->toDateTimeString(),
                'estimated_hours' => $data['estimated_hours'] ?? null, 'machine_id' => $machine->id,
            ], $actor);
            $fault->update(['work_order_id' => $wo->id, 'status' => 'in_repair']);
            if ($fault->stops_machine) {
                $machine->update(['status' => FarmMachine::MAINTENANCE]);
            }

            return $wo;
        });
    }

    /** Called when a repair job is completed: its faults wait for someone to approve the return to service. */
    public function repairDone(WO $wo): void
    {
        MachineFault::withoutGlobalScopes()->where('work_order_id', $wo->id)->where('status', 'in_repair')->update(['status' => 'awaiting_return']);
    }

    /**
     * Back to work. A fault with a repair job returns only once the job is completed and verified;
     * the person approving is not the one who handed the repair in.
     */
    public function returnToService(MachineFault $fault, ?string $note, User $actor): MachineFault
    {
        if (! in_array($fault->status, MachineFault::OPEN, true)) {
            throw ValidationException::withMessages(['fault' => 'That fault is closed.']);
        }
        if ($fault->work_order_id) {
            $wo = WO::withoutGlobalScopes()->findOrFail($fault->work_order_id);
            if ($wo->status !== WO::COMPLETED) {
                throw ValidationException::withMessages(['fault' => "The repair job {$wo->reference} is {$wo->status}; it must be completed and verified first."]);
            }
            if ($wo->submitted_by && (int) $wo->submitted_by === (int) $actor->id) {
                throw ValidationException::withMessages(['fault' => 'Whoever handed in the repair does not also approve the machine back to work.']);
            }
        }

        return DB::transaction(function () use ($fault, $note, $actor) {
            $fault->update(['status' => 'closed', 'down_to' => $fault->down_from ? now() : null, 'returned_by' => $actor->id, 'return_note' => $note]);
            $machine = FarmMachine::withoutGlobalScopes()->findOrFail($fault->machine_id);
            $stillDown = MachineFault::withoutGlobalScopes()->where('vehicle_id', $machine->id)->whereIn('status', MachineFault::OPEN)->where('stops_machine', true)->exists();
            if (! $stillDown && in_array($machine->status, [FarmMachine::MAINTENANCE, FarmMachine::OUT_OF_SERVICE], true)) {
                $machine->update(['status' => FarmMachine::AVAILABLE, 'out_of_service_reason' => null]);
                WorkOrderMachine::withoutGlobalScopes()->where('machine_id', $machine->id)->whereNotNull('conflict')->update(['conflict' => null]);
            }

            return $fault;
        });
    }

    public function claim(FarmMachine $machine, array $data, User $actor): MachineWarrantyClaim
    {
        $fault = ! empty($data['fault_id']) ? MachineFault::withoutGlobalScopes()->where('vehicle_id', $machine->id)->findOrFail($data['fault_id']) : null;
        $on = Carbon::parse($data['claimed_on'] ?? today());
        if (! $machine->underWarranty($fault?->created_at ?? $on) && empty($data['supplier_id']) && empty($data['provider'])) {
            throw ValidationException::withMessages(['fault_id' => "{$machine->code} is not under warranty; name the supplier whose service is being claimed against."]);
        }

        return MachineWarrantyClaim::create(['company_id' => $machine->company_id, 'farm_id' => $machine->farm_id, 'vehicle_id' => $machine->id,
            'fault_id' => $fault?->id, 'supplier_id' => $data['supplier_id'] ?? null, 'provider' => $data['provider'] ?? $machine->warranty_provider,
            'claimed_on' => $on->toDateString(), 'amount' => $data['amount'] ?? 0, 'reference' => $data['reference'] ?? null, 'status' => 'submitted',
            'created_by' => $actor->id]);
    }

    public function settleClaim(MachineWarrantyClaim $claim, string $status, ?float $recovered, ?string $outcome): MachineWarrantyClaim
    {
        if (! in_array($status, ['accepted', 'rejected', 'settled'], true) || in_array($claim->status, ['rejected', 'settled'], true)) {
            throw ValidationException::withMessages(['status' => 'That claim cannot move to '.$status.'.']);
        }
        $claim->update(['status' => $status, 'recovered' => $recovered, 'outcome' => $outcome]);

        return $claim;
    }

    /**
     * What a machine has cost to keep running, by where it came from: parts and labour on its repair
     * jobs, outside services, fuel — every figure from the records that carry the machine.
     */
    public function costs(FarmMachine $machine, ?string $from = null, ?string $to = null): array
    {
        $from ??= today()->subYear()->toDateString();
        $to ??= today()->toDateString();
        $jobs = WO::withoutGlobalScopes()->where('machine_id', $machine->id)->where('status', WO::COMPLETED)->whereBetween('completed_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $services = MachineService::withoutGlobalScopes()->where('vehicle_id', $machine->id)->whereBetween('completed_date', [$from, $to])->sum('cost');
        $fuel = DB::table('fleet_usage_logs')->where('vehicle_id', $machine->id)->whereBetween('used_on', [$from, $to])->sum('fuel_cost');
        $recovered = MachineWarrantyClaim::withoutGlobalScopes()->where('vehicle_id', $machine->id)->whereIn('status', ['accepted', 'settled'])->whereBetween('claimed_on', [$from, $to])->sum('recovered');
        $down = MachineFault::withoutGlobalScopes()->where('vehicle_id', $machine->id)->whereNotNull('down_from')->where('down_from', '<=', $to.' 23:59:59')
            ->where(fn ($q) => $q->whereNull('down_to')->orWhere('down_to', '>=', $from))->get()->sum(fn ($f) => $f->downtimeHours());
        $parts = (float) $jobs->sum('actual_material_cost');
        $labour = (float) $jobs->sum('actual_labour_cost');
        $hours = (float) DB::table('fleet_usage_logs')->where('vehicle_id', $machine->id)->whereBetween('used_on', [$from, $to])->sum('hours');
        $maintenance = $parts + $labour + (float) $services - (float) $recovered;

        return ['period' => [$from, $to], 'repair_jobs' => $jobs->count(), 'parts' => round($parts, 2), 'labour' => round($labour, 2), 'services' => round((float) $services, 2),
            'warranty_recovered' => round((float) $recovered, 2), 'maintenance' => round($maintenance, 2), 'fuel' => round((float) $fuel, 2),
            'hours' => round($hours, 1), 'maintenance_per_hour' => $hours > 0 ? round($maintenance / $hours, 2) : null, 'downtime_hours' => round((float) $down, 1),
            'basis' => 'Parts and labour from completed repair jobs on the machine; outside services and fuel as recorded against it; less warranty recoveries.'];
    }

    public function assertReadings(FarmMachine $machine, $hours, $km): void
    {
        if ($hours !== null && $hours !== '' && (float) $hours + 0.01 < (float) $machine->hours_reading) {
            throw ValidationException::withMessages(['hours_reading' => "The hour meter reads {$machine->hours_reading}; it cannot go backwards."]);
        }
        if ($km !== null && $km !== '' && $machine->odometer_km !== null && (float) $km + 0.01 < (float) $machine->odometer_km) {
            throw ValidationException::withMessages(['odometer_km' => "The odometer reads {$machine->odometer_km}; it cannot go backwards."]);
        }
    }

    public function moveMeters(FarmMachine $machine, $hours, $km): void
    {
        $u = [];
        if ($hours !== null && $hours !== '' && (float) $hours > (float) $machine->hours_reading) {
            $u['hours_reading'] = $hours;
        }
        if ($km !== null && $km !== '' && (float) $km > (float) ($machine->odometer_km ?? 0)) {
            $u['odometer_km'] = $km;
        }
        if ($u) {
            $machine->update($u);
        }
    }
}
