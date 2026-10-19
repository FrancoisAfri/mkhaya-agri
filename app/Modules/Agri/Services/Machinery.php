<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\MachineService;
use App\Modules\Agri\Models\MachineUsageLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tractors, implements, pumps and the rest (spec Module F): hours, fuel, services and disposal.
 *
 * Hour meters only go forward; a service resets the next-due point from the interval; a disposed
 * machine keeps its history and takes no new use. Fuel and service costs post to the shared ledger
 * against the farm, so machinery shows up in farm profitability without a second cost record.
 */
class Machinery
{
    public function __construct(private readonly FarmRegistry $registry, private readonly AgriLedger $ledger) {}

    public function register(Farm $farm, array $data): FarmMachine
    {
        $this->registry->assertOperational($farm);
        $taken = FarmMachine::withoutGlobalScope('farm_access')->whereRaw('lower(code) = ?', [mb_strtolower(trim($data['code']))])->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => "Machine code {$data['code']} is already used in this organisation."]);
        }
        $interval = $data['service_interval_hours'] ?? null;

        return FarmMachine::create($data + [
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'status' => FarmMachine::ACTIVE,
            'next_service_hours' => $interval ? (float) ($data['hours_reading'] ?? 0) + (float) $interval : null,
        ]);
    }

    public function logUse(FarmMachine $machine, array $data, User $actor): MachineUsageLog
    {
        $this->assertUsable($machine);
        $hours = (float) ($data['hours'] ?? 0);
        $after = isset($data['hours_reading_after']) && $data['hours_reading_after'] !== null
            ? (float) $data['hours_reading_after'] : (float) $machine->hours_reading + $hours;
        if ($after + 0.01 < (float) $machine->hours_reading) {
            throw ValidationException::withMessages(['hours_reading_after' => "The hour meter reads {$machine->hours_reading}; it cannot go backwards."]);
        }
        if ($hours <= 0 && empty($data['fuel_litres'])) {
            throw ValidationException::withMessages(['hours' => 'Record the hours worked or the fuel used.']);
        }

        return DB::transaction(function () use ($machine, $data, $actor, $hours, $after) {
            $log = MachineUsageLog::create([
                'company_id' => $machine->company_id, 'farm_id' => $machine->farm_id, 'machine_id' => $machine->id,
                'activity_id' => $data['activity_id'] ?? null, 'used_on' => $data['used_on'], 'hours' => $hours,
                'hours_reading_after' => $after, 'fuel_litres' => $data['fuel_litres'] ?? null,
                'fuel_cost' => $data['fuel_cost'] ?? null, 'operator_id' => $data['operator_id'] ?? null,
                'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id,
            ]);
            $machine->update(['hours_reading' => $after]);

            if ((float) ($data['fuel_cost'] ?? 0) > 0) {
                $this->ledger->expense($machine->farm, (float) $data['fuel_cost'], $data['used_on'], "Fuel {$machine->code}",
                    AgriLedger::dimensions($machine->farm, null, null, 'fuel') + ['agri_machine' => (string) $machine->id],
                    $data['settled_by'] ?? 'bank', config('finance.fuel_account', '6300'), "agri-fuel-{$log->uuid}");
            }

            return $log;
        });
    }

    public function service(FarmMachine $machine, array $data, User $actor): MachineService
    {
        if ($machine->status === FarmMachine::DISPOSED) {
            throw ValidationException::withMessages(['machine' => 'This machine has been disposed of.']);
        }

        return DB::transaction(function () use ($machine, $data, $actor) {
            $hoursAt = $data['hours_at_service'] ?? $machine->hours_reading;
            $record = MachineService::create([
                'company_id' => $machine->company_id, 'farm_id' => $machine->farm_id, 'machine_id' => $machine->id,
                'serviced_on' => $data['serviced_on'], 'service_type' => $data['service_type'] ?? 'service',
                'hours_at_service' => $hoursAt, 'description' => $data['description'],
                'provider' => $data['provider'] ?? null, 'cost' => $data['cost'] ?? null, 'recorded_by' => $actor->id,
            ]);

            $updates = ['status' => FarmMachine::ACTIVE];
            if (($data['service_type'] ?? 'service') === 'service') {
                $updates['next_service_hours'] = $machine->service_interval_hours ? (float) $hoursAt + (float) $machine->service_interval_hours : null;
                $updates['next_service_on'] = $data['next_service_on'] ?? null;
            }
            if ((float) $hoursAt > (float) $machine->hours_reading) {
                $updates['hours_reading'] = $hoursAt;
            }
            $machine->update($updates);

            if ((float) ($data['cost'] ?? 0) > 0) {
                $journal = $this->ledger->expense($machine->farm, (float) $data['cost'], $data['serviced_on'], "Service {$machine->code}: {$data['description']}",
                    AgriLedger::dimensions($machine->farm, null, null, 'repairs') + ['agri_machine' => (string) $machine->id],
                    $data['settled_by'] ?? 'payable', config('finance.repairs_account', '6400'), "agri-service-{$record->uuid}");
                $record->update(['journal_id' => $journal->id]);
            }

            return $record;
        });
    }

    public function dispose(FarmMachine $machine, string $date, string $reason, ?float $value): FarmMachine
    {
        if ($machine->status === FarmMachine::DISPOSED) {
            throw ValidationException::withMessages(['status' => 'Already disposed of.']);
        }
        $machine->update(['status' => FarmMachine::DISPOSED, 'disposed_on' => $date, 'disposal_reason' => $reason, 'disposal_value' => $value]);

        return $machine;
    }

    private function assertUsable(FarmMachine $machine): void
    {
        if ($machine->status === FarmMachine::DISPOSED) {
            throw ValidationException::withMessages(['machine' => "{$machine->code} has been disposed of and cannot be used."]);
        }
    }
}
