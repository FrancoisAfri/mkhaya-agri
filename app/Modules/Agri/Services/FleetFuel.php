<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §7.4: fuel and trips. Fuel is bought into a farm's fuel tank like any other stock (an
 * order and a goods-received note), and issued from it to a machine as a stock movement whose
 * cost lands on the machine. Each issue carries the meter reading at the pump, so consumption can
 * be worked out fill to fill. Trips record the driver, the odometer and why the vehicle went.
 *
 * indicators() flags what deserves a look — consumption well above the machine's own norm, fuel
 * that left a tank without being issued, issues without a reading, maintenance costs out of line —
 * and says so plainly: an indicator to investigate, never proof that anyone did anything wrong.
 */
class FleetFuel
{
    /** How far above its norm a machine's consumption must be to be flagged (30 %). */
    public const OVER_NORM = 1.3;

    public function __construct(private readonly FarmStores $stores, private readonly MachineMaintenance $maintenance) {}

    public function issue(FarmMachine $machine, FarmStore $tank, FinStockItem $fuel, float $litres, array $data, User $actor): MachineUsageLog
    {
        if ($machine->status === FarmMachine::DISPOSED) {
            throw ValidationException::withMessages(['machine' => "{$machine->code} has been disposed of."]);
        }
        if ($tank->kind !== 'fuel_tank' || (int) $tank->farm_id !== (int) $machine->farm_id) {
            throw ValidationException::withMessages(['store_id' => "Fuel is issued from a fuel tank on {$machine->code}'s farm."]);
        }
        if ($litres <= 0) {
            throw ValidationException::withMessages(['litres' => 'Give the litres issued.']);
        }
        $this->maintenance->assertReadings($machine, $data['hours_reading'] ?? null, $data['odometer_km'] ?? null);
        $farm = Farm::withoutGlobalScopes()->findOrFail($machine->farm_id);

        try {
            return DB::transaction(function () use ($machine, $tank, $fuel, $litres, $data, $actor, $farm) {
                $log = MachineUsageLog::create(['company_id' => $machine->company_id, 'farm_id' => $machine->farm_id, 'vehicle_id' => $machine->id, 'kind' => 'fuel',
                    'used_on' => $data['date'] ?? today()->toDateString(), 'hours' => 0, 'hours_reading_after' => $data['hours_reading'] ?? null,
                    'odometer_after' => $data['odometer_km'] ?? null, 'fuel_litres' => $litres, 'operator_id' => $data['driver_id'] ?? null,
                    'warehouse_id' => $tank->warehouse_id, 'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id]);
                $issued = $this->stores->issue($farm, $fuel, $litres, [
                    'warehouse' => FinWarehouse::withoutGlobalScopes()->findOrFail($tank->warehouse_id), 'actor' => $actor,
                    'source_type' => 'agri_fuel', 'source_id' => $log->id, 'idempotency_key' => $data['idempotency_key'] ?? "agri-fuel-issue-{$log->uuid}",
                    'date' => $log->used_on->toDateString(), 'reference' => "{$farm->code} fuel {$machine->code}", 'reason_code' => 'AGRI_USE',
                    'expense_account' => config('finance.fuel_account', '6300'),
                    'dimensions' => AgriLedger::dimensions($farm, null, null, 'fuel') + ['agri_machine' => (string) $machine->id],
                ]);
                $log->update(['stock_movement_id' => $issued['movement']->id, 'fuel_cost' => $issued['value']]);
                $this->maintenance->moveMeters($machine, $data['hours_reading'] ?? null, $data['odometer_km'] ?? null);

                return $log;
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['litres' => $e->getMessage()]);
        }
    }

    public function trip(FarmMachine $machine, array $data, User $actor): MachineUsageLog
    {
        if ($machine->isUnavailable()) {
            throw ValidationException::withMessages(['machine' => "{$machine->code} is ".str_replace('_', ' ', $machine->status).'.']);
        }
        $start = (float) ($data['odometer_start'] ?? $machine->odometer_km ?? 0);
        $end = (float) $data['odometer_end'];
        if ($end <= $start) {
            throw ValidationException::withMessages(['odometer_end' => 'The odometer at the end must be past where it started.']);
        }
        $this->maintenance->assertReadings($machine, null, $end);

        return DB::transaction(function () use ($machine, $data, $actor, $start, $end) {
            $log = MachineUsageLog::create(['company_id' => $machine->company_id, 'farm_id' => $machine->farm_id, 'vehicle_id' => $machine->id, 'kind' => 'trip',
                'used_on' => $data['date'] ?? today()->toDateString(), 'hours' => $data['hours'] ?? 0, 'odometer_after' => $end, 'distance_km' => round($end - $start, 1),
                'trip_from' => $data['from'] ?? null, 'trip_to' => $data['to'] ?? null, 'purpose' => $data['purpose'], 'operator_id' => $data['driver_id'] ?? null,
                'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id]);
            $this->maintenance->moveMeters($machine, null, $end);

            return $log;
        });
    }

    /**
     * Consumption fill to fill: the litres put in at a fill, over the hours (or kilometres) worked
     * since the fill before it. Needs a reading at both fills.
     *
     * @return list<array{on: string, litres: float, hours: ?float, km: ?float, per_hour: ?float, per_100km: ?float}>
     */
    public function consumption(FarmMachine $machine, int $limit = 50): array
    {
        $fills = MachineUsageLog::withoutGlobalScopes()->where('vehicle_id', $machine->id)->where('kind', 'fuel')->orderBy('used_on')->orderBy('id')->limit($limit)->get();
        $out = [];
        $prev = null;
        foreach ($fills as $f) {
            if ($prev) {
                $h = $f->hours_reading_after !== null && $prev->hours_reading_after !== null ? (float) $f->hours_reading_after - (float) $prev->hours_reading_after : null;
                $km = $f->odometer_after !== null && $prev->odometer_after !== null ? (float) $f->odometer_after - (float) $prev->odometer_after : null;
                $l = (float) $f->fuel_litres;
                $out[] = ['on' => $f->used_on->toDateString(), 'log' => $f->uuid, 'litres' => $l, 'hours' => $h, 'km' => $km,
                    'per_hour' => $h > 0 ? round($l / $h, 2) : null, 'per_100km' => $km > 0 ? round($l / $km * 100, 2) : null];
            }
            $prev = $f;
        }

        return $out;
    }

    /** @return list<array{kind: string, machine: ?string, detail: string, basis: string}> */
    public function indicators(Farm $farm, ?string $from = null, ?string $to = null): array
    {
        $from ??= today()->subDays(90)->toDateString();
        $to ??= today()->toDateString();
        $flags = [];
        $machines = FarmMachine::withoutGlobalScopes()->where('farm_id', $farm->id)->where('status', '!=', FarmMachine::DISPOSED)->get();

        foreach ($machines as $m) {
            // Consumption above the machine's own norm.
            $c = collect($this->consumption($m, 200));
            foreach (['per_hour' => ['l/h', $m->expected_litres_per_hour], 'per_100km' => ['l/100 km', null]] as $key => [$unit, $expected]) {
                $points = $c->pluck($key)->filter();
                $norm = $expected ?: ($points->count() >= 4 ? $this->median($points->slice(0, -1)) : null);
                $last = $c->filter(fn ($r) => $r[$key] !== null && $r['on'] >= $from && $r['on'] <= $to);
                foreach ($last as $r) {
                    if ($norm && $r[$key] > $norm * self::OVER_NORM) {
                        $flags[] = ['kind' => 'high_consumption', 'machine' => "{$m->code} {$m->name}", 'on' => $r['on'],
                            'detail' => "{$r[$key]} {$unit} at the fill on {$r['on']}, against a norm of ".round($norm, 2)." {$unit}.",
                            'basis' => $expected ? "The machine's expected consumption, set on its record." : 'The median of the machine\'s own earlier fills ('.($points->count() - 1).').'];
                    }
                }
            }
            // Issues with no reading: consumption cannot be checked.
            $blind = MachineUsageLog::withoutGlobalScopes()->where('vehicle_id', $m->id)->where('kind', 'fuel')->whereBetween('used_on', [$from, $to])
                ->whereNull('hours_reading_after')->whereNull('odometer_after')->get();
            if ($blind->isNotEmpty()) {
                $flags[] = ['kind' => 'missing_reading', 'machine' => "{$m->code} {$m->name}", 'on' => $blind->max(fn ($b) => $b->used_on->toDateString()),
                    'detail' => $blind->count().' fuel issue(s) ('.round($blind->sum('fuel_litres'), 1).' l) without an hour-meter or odometer reading.',
                    'basis' => 'Fuel issues in the period with neither reading recorded.'];
            }
        }

        // Fuel that left a tank other than by an issue: count shortfalls and write-offs.
        $tanks = FarmStore::withoutGlobalScopes()->where('farm_id', $farm->id)->where('kind', 'fuel_tank')->get();
        foreach ($tanks as $t) {
            $lost = DB::table('fin_stock_movements')->where('warehouse_id', $t->warehouse_id)->whereBetween('movement_date', [$from, $to])
                ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('source_type', ['ADJUSTMENT', 'CYCLE_COUNT'])->where('qty', '<', 0))->orWhere('type', 'scrap'))
                ->get(['qty', 'value', 'movement_date', 'reference']);
            if ($lost->isNotEmpty()) {
                $flags[] = ['kind' => 'tank_loss', 'machine' => null, 'on' => (string) $lost->max('movement_date'),
                    'detail' => "{$t->name}: ".round(abs($lost->sum('qty')), 1).' l less in the tank than was received and issued (R'.number_format(abs($lost->sum('value')), 2).').',
                    'basis' => 'Stock counts and write-offs on the tank in the period. Evaporation, meter error and theft all look the same here.'];
            }
        }

        // Maintenance cost per hour well above similar machines.
        $costs = $machines->mapWithKeys(fn ($m) => [$m->id => $this->maintenance->costs($m, $from, $to)]);
        foreach ($machines->groupBy('machine_type') as $type => $group) {
            $rates = $group->map(fn ($m) => $costs[$m->id]['maintenance_per_hour'])->filter();
            if ($rates->count() < 3) {
                continue;
            }
            $norm = $this->median($rates);
            foreach ($group as $m) {
                $r = $costs[$m->id]['maintenance_per_hour'];
                if ($r !== null && $norm > 0 && $r > $norm * 2) {
                    $flags[] = ['kind' => 'maintenance_cost', 'machine' => "{$m->code} {$m->name}", 'on' => $to,
                        'detail' => 'R'.number_format($r, 2).' maintenance per hour worked, against R'.number_format($norm, 2).' for the farm\'s other '.str_replace('_', ' ', $type).'s.',
                        'basis' => 'Repair parts, labour and outside services per hour logged, set against the median of machines of the same kind ('.$rates->count().').'];
                }
            }
        }

        return array_map(fn ($f) => $f + ['note' => 'An indicator to look into, not proof that anything is wrong.'], $flags);
    }

    private function median(Collection $xs): float
    {
        $s = $xs->map(fn ($x) => (float) $x)->sort()->values();
        $n = $s->count();

        return $n === 0 ? 0.0 : ($n % 2 ? $s[intdiv($n, 2)] : ($s[$n / 2 - 1] + $s[$n / 2]) / 2);
    }
}
