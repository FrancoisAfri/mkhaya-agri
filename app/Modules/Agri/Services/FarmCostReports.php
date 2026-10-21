<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §6.3: cost at the levels a farm manages — per worker, per activity, per machine hour,
 * and the margin per buyer and sales channel. Field, crop, hectare, kilogram, grade and saleable
 * unit are CropProfitability's; animal and batch are LivestockIntelligence's.
 *
 * Every figure says where it comes from. Direct costs and allocated overheads are kept apart, and a
 * cost is counted at one level only: a machine's running cost is its own, and reaches a crop only
 * through the hours charged to the work done on it.
 */
class FarmCostReports
{
    /** What each person cost: approved time (and its overtime) and approved piecework. */
    public function perWorker(Farm $farm, string $from, string $to): array
    {
        $sheets = FarmTimesheet::query()->where('farm_id', $farm->id)->where('status', 'approved')->whereBetween('work_date', [$from, $to])->get()->groupBy('worker_id');
        $pieces = PieceworkEntry::query()->where('farm_id', $farm->id)->where('status', 'approved')->whereNotNull('worker_id')->whereBetween('work_date', [$from, $to])->get()->groupBy('worker_id');
        $ids = $sheets->keys()->merge($pieces->keys())->unique();
        $workers = FarmWorker::query()->whereIn('id', $ids)->with('person')->get()->keyBy('id');

        return $ids->map(function ($id) use ($sheets, $pieces, $workers) {
            $s = $sheets->get($id, collect());
            $p = $pieces->get($id, collect());
            $hours = $s->sum('worked_minutes') / 60;
            $cost = (float) $s->sum('cost_amount') + (float) $p->sum('amount');

            return ['worker' => $workers[$id]?->displayName() ?? "#{$id}", 'category' => $workers[$id]?->category, 'days' => $s->count(),
                'hours' => round($hours, 1), 'overtime_hours' => round($s->sum('overtime_minutes') / 60, 1),
                'time_cost' => round((float) $s->sum('cost_amount'), 2), 'piecework' => round((float) $p->sum('amount'), 2), 'total' => round($cost, 2),
                'per_hour' => $hours > 0 ? round($cost / $hours, 2) : null];
        })->sortByDesc('total')->values()->all();
    }

    /** Each completed field activity: inputs issued (corrections netted), labour, machine time — and per hectare covered. */
    public function perActivity(Farm $farm, string $from, string $to): array
    {
        $acts = FieldActivity::query()->where('farm_id', $farm->id)->where('status', FieldActivity::COMPLETED)
            ->whereBetween('completed_at', [$from.' 00:00:00', $to.' 23:59:59'])->with(['machine', 'cropPlan'])->get();
        $inputs = ActivityInput::query()->whereIn('activity_id', $acts->pluck('id'))->where('status', ActivityInput::ISSUED)->get()->groupBy('activity_id');
        $labour = FarmTimesheet::query()->where('farm_id', $farm->id)->where('status', 'approved')->whereBetween('work_date', [$from, $to])->whereNotNull('work_order_id')
            ->get()->groupBy('work_order_id');
        $woFor = DB::table('farm_work_orders')->whereIn('field_activity_id', $acts->pluck('id'))->pluck('id', 'field_activity_id');

        return $acts->map(function ($a) use ($inputs, $labour, $woFor) {
            $in = (float) ($inputs[$a->id] ?? collect())->sum('total_cost');
            $wo = $woFor[$a->id] ?? null;
            $lab = (float) $a->labour_cost + ($wo ? (float) ($labour[$wo] ?? collect())->sum('cost_amount') : 0);
            $mach = $a->machine && $a->machine->hourly_rate ? (float) $a->machine_hours * (float) $a->machine->hourly_rate : 0.0;
            $total = $in + $lab + $mach;
            $ha = (float) ($a->area_covered ?? 0);

            return ['activity' => $a->title, 'type' => $a->activity_type, 'crop' => $a->cropPlan?->code, 'on' => $a->completed_at?->toDateString(),
                'inputs' => round($in, 2), 'labour' => round($lab, 2), 'machinery' => round($mach, 2), 'total' => round($total, 2),
                'hectares' => $ha ?: null, 'per_ha' => $ha > 0 ? round($total / $ha, 2) : null];
        })->sortByDesc('total')->values()->all();
    }

    /**
     * Each machine's running cost per hour worked: fuel, repairs and services posted to it, over the
     * hours logged — set beside the rate it is charged out at, so an under-charged machine shows.
     */
    public function perMachineHour(Farm $farm, string $from, string $to): array
    {
        $posted = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $farm->company_id)->where('j.status', '!=', 'draft')->where('a.type', 'expense')->whereBetween('j.journal_date', [$from, $to])
            ->whereRaw("l.dimensions->>'agri_farm' = ?", [(string) $farm->id])->whereRaw("l.dimensions->>'agri_machine' IS NOT NULL")
            ->groupByRaw("l.dimensions->>'agri_machine', COALESCE(l.dimensions->>'agri_category', 'other')")
            ->selectRaw("l.dimensions->>'agri_machine' as machine_id, COALESCE(l.dimensions->>'agri_category', 'other') as category, SUM(l.base_debit - l.base_credit) as cost")
            ->get()->groupBy('machine_id');
        $hours = MachineUsageLog::query()->where('farm_id', $farm->id)->whereBetween('used_on', [$from, $to])->groupBy('vehicle_id')
            ->selectRaw('vehicle_id as machine_id, SUM(hours) as hours, SUM(COALESCE(fuel_litres, 0)) as litres')->get()->keyBy('machine_id');

        return FarmMachine::query()->where('farm_id', $farm->id)->where('status', '!=', 'disposed')->get()->map(function ($m) use ($posted, $hours) {
            // Costs posted to the machine, including any posted before it became a Fleet asset under another id.
            $c = $posted->get((string) $m->id, collect())->merge($m->legacy_farm_machine_id ? $posted->get((string) $m->legacy_farm_machine_id, collect()) : collect());
            $cost = (float) $c->sum('cost');
            $h = (float) ($hours[$m->id]->hours ?? 0);

            return ['machine' => "{$m->code} {$m->name}", 'hours' => round($h, 1), 'fuel_litres' => round((float) ($hours[$m->id]->litres ?? 0), 1),
                'costs' => $c->mapWithKeys(fn ($r) => [$r->category => round((float) $r->cost, 2)])->all(), 'total' => round($cost, 2),
                'per_hour' => $h > 0 ? round($cost / $h, 2) : null, 'charge_rate' => $m->hourly_rate ? (float) $m->hourly_rate : null,
                'under_charged' => $h > 0 && $m->hourly_rate && $cost / $h > (float) $m->hourly_rate];
        })->filter(fn ($r) => $r['hours'] > 0 || $r['total'] > 0)->values()->all();
    }

    /**
     * Margin by buyer and channel: what each buyer took (delivered against commitments) at its price,
     * less what that produce cost to grow (the crop's full cost per saleable unit). A buyer with no
     * commitment price is left at revenue only.
     */
    public function perBuyer(Farm $farm): array
    {
        $profit = app(CropProfitability::class);
        $unit = [];
        $rows = HarvestCommitment::query()->where('farm_id', $farm->id)->where('delivered_quantity', '>', 0)->get()
            ->map(function ($c) use ($profit, &$unit) {
                $plan = $c->crop_plan_id ? CropPlan::query()->find($c->crop_plan_id) : null;
                $perUnit = $plan ? ($unit[$plan->id] ??= $profit->forPlan($plan)['per_saleable_unit']) : null;
                $revenue = (float) $c->delivered_quantity * (float) $c->price_per_unit;
                $cost = $perUnit !== null ? (float) $c->delivered_quantity * $perUnit : null;

                return ['buyer' => $c->buyer, 'channel' => $c->channel ?: 'unspecified', 'quantity' => (float) $c->delivered_quantity, 'unit' => $c->unit,
                    'revenue' => round($revenue, 2), 'cost' => $cost !== null ? round($cost, 2) : null, 'margin' => $cost !== null ? round($revenue - $cost, 2) : null];
            });

        $group = fn (string $key) => $rows->groupBy($key)->map(fn ($g, $k) => [$key => $k, 'quantity' => round($g->sum('quantity'), 3),
            'revenue' => round($g->sum('revenue'), 2), 'margin' => $g->contains(fn ($r) => $r['margin'] === null) ? null : round($g->sum('margin'), 2),
            'margin_pct' => $g->sum('revenue') > 0 && ! $g->contains(fn ($r) => $r['margin'] === null) ? round($g->sum('margin') / $g->sum('revenue') * 100, 1) : null])->values()->all();

        return ['by_buyer' => $group('buyer'), 'by_channel' => $group('channel')];
    }
}
