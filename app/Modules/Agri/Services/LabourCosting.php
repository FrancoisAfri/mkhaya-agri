<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\Farm;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use Illuminate\Support\Carbon;

/**
 * Labour cost by crop, herd, field, work order and worker (Phase 2 §1.6, LAB-012).
 *
 * Built only from approved records — approved timesheets at their costing (rate × rules, priced at
 * approval) and approved piecework at the rate frozen at approval — so the report reconciles line
 * for line with those records. It is a costing view: payroll decides pay, and payroll's own journals
 * carry no farm dimensions, so nothing here is counted twice in farm profitability.
 */
class LabourCosting
{
    /**
     * @return array{rows: list<array>, totals: array, by_enterprise: array<string, float>}
     */
    public function forFarm(Farm $farm, Carbon $from, Carbon $to): array
    {
        $sheets = FarmTimesheet::withoutGlobalScopes()->where('farm_id', $farm->id)->where('status', FarmTimesheet::APPROVED)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();
        $pieces = PieceworkEntry::withoutGlobalScopes()->where('farm_id', $farm->id)->where('status', 'approved')
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();

        $key = fn ($r) => $r->crop_plan_id ? 'crop_plan:'.$r->crop_plan_id : ($r->livestock_group_id ?? null ? 'livestock_group:'.$r->livestock_group_id : 'farm');
        $rows = [];
        foreach ($sheets as $s) {
            $k = $key($s);
            $rows[$k]['time_cost'] = ($rows[$k]['time_cost'] ?? 0) + (float) $s->cost_amount;
            $rows[$k]['hours'] = ($rows[$k]['hours'] ?? 0) + $s->worked_minutes / 60;
            $rows[$k]['overtime_hours'] = ($rows[$k]['overtime_hours'] ?? 0) + $s->overtime_minutes / 60;
            $rows[$k]['uncosted_hours'] = ($rows[$k]['uncosted_hours'] ?? 0) + ($s->cost_amount === null ? $s->worked_minutes / 60 : 0);
        }
        foreach ($pieces as $p) {
            $k = $key($p);
            $rows[$k]['piecework'] = ($rows[$k]['piecework'] ?? 0) + (float) $p->amount;
            $rows[$k]['units'][$p->unit] = ($rows[$k]['units'][$p->unit] ?? 0) + (float) $p->approved_quantity;
        }

        $out = [];
        $byEnterprise = [];
        foreach ($rows as $k => $r) {
            $total = round(($r['time_cost'] ?? 0) + ($r['piecework'] ?? 0), 2);
            $byEnterprise[$k] = $total;
            $units = collect($r['units'] ?? [])->map(fn ($q, $u) => ['unit' => $u, 'quantity' => round($q, 3),
                'cost_per_unit' => $q > 0 ? round($total / $q, 4) : null])->values()->all();
            $out[] = ['enterprise' => $k, 'hours' => round($r['hours'] ?? 0, 2), 'overtime_hours' => round($r['overtime_hours'] ?? 0, 2),
                'time_cost' => round($r['time_cost'] ?? 0, 2), 'piecework' => round($r['piecework'] ?? 0, 2), 'total' => $total,
                'uncosted_hours' => round($r['uncosted_hours'] ?? 0, 2), 'units' => $units];
        }

        return [
            'rows' => $out,
            'by_enterprise' => $byEnterprise,
            'totals' => [
                'hours' => round($sheets->sum('worked_minutes') / 60, 2),
                'overtime_hours' => round($sheets->sum('overtime_minutes') / 60, 2),
                'time_cost' => round((float) $sheets->sum('cost_amount'), 2),
                'piecework' => round((float) $pieces->sum('amount'), 2),
                'total' => round((float) $sheets->sum('cost_amount') + (float) $pieces->sum('amount'), 2),
                'timesheets' => $sheets->count(), 'attendance_piecework_entries' => $pieces->count(),
            ],
        ];
    }
}
