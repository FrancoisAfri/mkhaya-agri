<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\MachineFault;
use App\Modules\Agri\Models\MachinePrestartCheck;
use App\Modules\Agri\Models\MachineService;
use App\Modules\Agri\Models\MachineUsageLog;

/**
 * Phase 2 §7.5: which machines most need looking at before the next busy week. A points score from
 * what the farm knows — service overdue, breakdowns in the past year, failed pre-start items, age
 * against useful life, hours since the last service — never a date it will fail.
 *
 * Every assessment lists the factors that made it and a confidence that says how much history it
 * stands on: little history, low confidence, whatever the score. The recommendation is an
 * inspection, not a verdict.
 */
class MachineRisk
{
    public function assess(FarmMachine $m): array
    {
        $factors = [];
        $add = function (string $factor, int $points, string $evidence) use (&$factors) {
            $factors[] = ['factor' => $factor, 'points' => $points, 'evidence' => $evidence];
        };

        // Service overdue, by hours or by date.
        if ($m->next_service_hours !== null && (float) $m->hours_reading > (float) $m->next_service_hours) {
            $over = round((float) $m->hours_reading - (float) $m->next_service_hours, 1);
            $add('Service overdue (hours)', min(30, 10 + (int) ($over / 10)), "{$over} h past the service due at {$m->next_service_hours} h.");
        }
        if ($m->next_service_on && $m->next_service_on->lt(today())) {
            $add('Service overdue (date)', min(25, 10 + intdiv((int) $m->next_service_on->diffInDays(today()), 7)), 'Service was due on '.$m->next_service_on->toDateString().'.');
        }

        // Breakdowns in the past year: stopping faults count most.
        $faults = MachineFault::withoutGlobalScopes()->where('vehicle_id', $m->id)->where('created_at', '>=', now()->subYear())->get();
        $stops = $faults->where('stops_machine', true)->count();
        if ($stops) {
            $add('Breakdowns in the past year', min(30, $stops * 10), "{$stops} fault(s) that stopped the machine in the last 12 months.");
        }
        if ($faults->whereIn('status', MachineFault::OPEN)->where('stops_machine', false)->count()) {
            $add('Open minor faults', 5 * min(3, $faults->whereIn('status', MachineFault::OPEN)->where('stops_machine', false)->count()), 'Faults reported and not yet fixed.');
        }

        // Pre-start items failing recently.
        $checks = MachinePrestartCheck::withoutGlobalScopes()->where('vehicle_id', $m->id)->where('checked_on', '>=', today()->subDays(30))->get();
        $failedItems = $checks->flatMap(fn ($c) => collect($c->items)->where('ok', false)->pluck('item'))->countBy();
        if ($failedItems->isNotEmpty()) {
            $add('Pre-start items failing', min(20, $failedItems->sum() * 4), 'Failed in the last 30 days: '.$failedItems->map(fn ($n, $i) => "{$i} ×{$n}")->implode(', ').'.');
        }

        // Age and hours against useful life.
        if ($m->useful_life_hours && (float) $m->hours_reading > 0.8 * $m->useful_life_hours) {
            $add('Near the end of its working hours', 10, "{$m->hours_reading} h of a useful life of {$m->useful_life_hours} h.");
        }
        $age = $m->purchased_on ? $m->purchased_on->diffInYears(today()) : ($m->year ? (int) date('Y') - (int) $m->year : null);
        if ($m->useful_life_years && $age !== null && $age > $m->useful_life_years) {
            $add('Past its useful life', 10, "{$age} years old against a useful life of {$m->useful_life_years}.");
        }

        // Hours since the last service against its interval.
        $last = MachineService::withoutGlobalScopes()->where('vehicle_id', $m->id)->where('maintenance_type', 'service')->latest('completed_date')->first();
        if ($last && $m->service_interval_hours && $last->hours_at_service !== null) {
            $since = (float) $m->hours_reading - (float) $last->hours_at_service;
            if ($since > 0.9 * (float) $m->service_interval_hours && ! ($m->next_service_hours !== null && (float) $m->hours_reading > (float) $m->next_service_hours)) {
                $add('Close to its service interval', 5, round($since, 1)." h since the last service; the interval is {$m->service_interval_hours} h.");
            }
        }

        $score = min(100, array_sum(array_column($factors, 'points')));
        // Confidence: how much history the score rests on.
        $history = MachineUsageLog::withoutGlobalScopes()->where('vehicle_id', $m->id)->count() + MachineService::withoutGlobalScopes()->where('vehicle_id', $m->id)->count()
            + $faults->count() + $checks->count();
        $confidence = $history >= 30 ? 'medium' : 'low';   // never "high": breakdowns are rare events and the farm's own history is small
        $level = $score >= 40 ? 'high' : ($score >= 15 ? 'medium' : 'low');

        return ['machine' => ['id' => $m->uuid, 'code' => $m->code, 'name' => $m->name, 'type' => $m->machine_type, 'status' => $m->status],
            'score' => $score, 'risk' => $level, 'confidence' => $confidence, 'history_records' => $history, 'factors' => $factors,
            'recommendation' => match ($level) {
                'high' => 'Inspect before its next booking; consider a service now.',
                'medium' => 'Inspect at the next opportunity.',
                default => 'No action beyond the normal schedule.',
            },
            'basis' => 'A points score from the factors listed, out of 100. It ranks machines for inspection; it does not predict when or whether a machine will fail.'
                .($confidence === 'low' ? ' Little history is recorded for this machine, so treat the score as a rough guide.' : '')];
    }

    /** @return list<array<string, mixed>> */
    public function forFarm(Farm $farm): array
    {
        return FarmMachine::withoutGlobalScopes()->where('farm_id', $farm->id)->where('status', '!=', FarmMachine::DISPOSED)->orderBy('fleet_number')->get()
            ->map(fn ($m) => $this->assess($m))->sortByDesc('score')->values()->all();
    }
}
