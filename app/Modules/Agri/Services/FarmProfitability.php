<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmBudgetLine;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\MachineUsageLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Income, costs and margin per farm and per enterprise (a crop plan or a livestock group), spec
 * Module E.
 *
 * ACTUAL figures come only from the shared ledger: posted journal lines carrying the farm's
 * dimensions. Labour and machine time that were recorded on activities but never posted as money
 * are shown separately as ESTIMATED operating costs — they are real costs, but they are not in the
 * books, and mixing the two would make the farm report disagree with the trial balance.
 */
class FarmProfitability
{
    /**
     * @return array{farm: array, period: array, enterprises: list<array>, totals: array, estimated: array, budget: array}
     */
    public function forFarm(Farm $farm, string $from, string $to): array
    {
        $lines = DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $farm->company_id)
            ->where('j.status', '!=', 'draft')
            ->whereBetween('j.journal_date', [$from, $to])
            ->whereIn('a.type', ['income', 'expense'])
            ->whereRaw("l.dimensions->>'agri_farm' = ?", [(string) $farm->id])
            ->selectRaw("COALESCE(l.dimensions->>'agri_enterprise', 'farm') as enterprise,
                COALESCE(l.dimensions->>'agri_category', 'other') as category,
                a.type, a.code, a.name,
                SUM(l.base_credit - l.base_debit) as net")
            ->groupByRaw("COALESCE(l.dimensions->>'agri_enterprise', 'farm'), COALESCE(l.dimensions->>'agri_category', 'other'), a.type, a.code, a.name")
            ->get();

        $estimated = $this->estimatedCosts($farm, $from, $to);
        $budget = FarmBudgetLine::query()->where('farm_id', $farm->id)
            ->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from)->get();

        $labels = $this->enterpriseLabels($farm);
        $keys = $lines->pluck('enterprise')->merge(array_keys($estimated['by_enterprise']))->merge($budget->pluck('enterprise')->map(fn ($e) => $e ?: 'farm'))
            ->merge(array_keys($labels))->unique()->values();

        $enterprises = $keys->map(function ($key) use ($lines, $estimated, $budget, $labels) {
            $rows = $lines->where('enterprise', $key);
            $income = round((float) $rows->where('type', 'income')->sum('net'), 2);
            $costs = round(-(float) $rows->where('type', 'expense')->sum('net'), 2);
            $est = round((float) ($estimated['by_enterprise'][$key] ?? 0), 2);
            $budgetCost = round((float) $budget->filter(fn ($b) => ($b->enterprise ?: 'farm') === $key && $b->category !== 'income')->sum('amount'), 2);
            $budgetIncome = round((float) $budget->filter(fn ($b) => ($b->enterprise ?: 'farm') === $key && $b->category === 'income')->sum('amount'), 2);
            $label = $labels[$key] ?? ['name' => $key === 'farm' ? 'Whole farm (not allocated)' : $key, 'kind' => 'farm', 'hectares' => null, 'head' => null, 'budget' => null];
            if ($label['budget'] !== null && $budgetCost == 0.0) {
                $budgetCost = round((float) $label['budget'], 2);
            }
            $margin = round($income - $costs, 2);

            return $label + [
                'key' => $key,
                'income' => $income,
                'costs' => $costs,
                'gross_margin' => $margin,
                'estimated_costs' => $est,
                'margin_after_estimates' => round($margin - $est, 2),
                'margin_per_ha' => $label['hectares'] ? round($margin / $label['hectares'], 2) : null,
                'budget_costs' => $budgetCost ?: null,
                'budget_income' => $budgetIncome ?: null,
                'budget_variance' => $budgetCost ? round($budgetCost - $costs, 2) : null,
                'by_category' => $rows->where('type', 'expense')->groupBy('category')->map(fn ($g) => round(-(float) $g->sum('net'), 2))->all(),
            ];
        })->filter(fn ($e) => $e['income'] != 0 || $e['costs'] != 0 || $e['estimated_costs'] != 0 || $e['budget_costs'] || $e['kind'] !== 'farm')
            ->sortByDesc('gross_margin')->values()->all();

        $income = round(array_sum(array_column($enterprises, 'income')), 2);
        $costs = round(array_sum(array_column($enterprises, 'costs')), 2);

        return [
            'farm' => ['id' => $farm->uuid, 'code' => $farm->code, 'name' => $farm->name, 'currency' => $farm->currency],
            'period' => ['from' => $from, 'to' => $to],
            'enterprises' => $enterprises,
            'totals' => [
                'income' => $income, 'costs' => $costs, 'gross_margin' => round($income - $costs, 2),
                'estimated_costs' => round($estimated['total'], 2),
                'margin_after_estimates' => round($income - $costs - $estimated['total'], 2),
                'budget_costs' => round(array_sum(array_map(fn ($e) => (float) ($e['budget_costs'] ?? 0), $enterprises)), 2),
            ],
            'estimated' => $estimated,
            'by_category' => $lines->where('type', 'expense')->groupBy('category')->map(fn ($g) => round(-(float) $g->sum('net'), 2))->sortDesc()->all(),
        ];
    }

    /** Totals for every farm the user can reach, for the dashboard and the organisation view. */
    public function summary(Collection $farms, string $from, string $to): array
    {
        return $farms->map(fn (Farm $f) => ['farm' => $f->name, 'code' => $f->code, 'id' => $f->uuid] + $this->forFarm($f, $from, $to)['totals'])->values()->all();
    }

    /** Labour and machine time recorded on completed work but not posted as money. */
    private function estimatedCosts(Farm $farm, string $from, string $to): array
    {
        $by = [];
        $labour = 0.0;
        $machine = 0.0;
        $activities = FieldActivity::query()->where('farm_id', $farm->id)->where('status', FieldActivity::COMPLETED)
            ->whereBetween('completed_at', [$from.' 00:00:00', $to.' 23:59:59'])->with('machine')->get();
        foreach ($activities as $a) {
            $key = $a->crop_plan_id ? 'crop_plan:'.$a->crop_plan_id : ($a->livestock_group_id ? 'livestock_group:'.$a->livestock_group_id : 'farm');
            $l = (float) ($a->labour_cost ?? 0);
            $m = $a->machine && $a->machine->hourly_rate ? (float) $a->machine_hours * (float) $a->machine->hourly_rate : 0.0;
            $by[$key] = ($by[$key] ?? 0) + $l + $m;
            $labour += $l;
            $machine += $m;
        }
        // Machine time logged without an activity is the farm's.
        $loose = MachineUsageLog::query()->where('farm_id', $farm->id)->whereNull('activity_id')
            ->whereBetween('used_on', [$from, $to])->with('machine')->get()
            ->sum(fn ($u) => $u->machine && $u->machine->hourly_rate ? (float) $u->hours * (float) $u->machine->hourly_rate : 0);
        if ($loose > 0) {
            $by['farm'] = ($by['farm'] ?? 0) + $loose;
            $machine += $loose;
        }

        return ['labour' => round($labour, 2), 'machine' => round($machine, 2), 'total' => round($labour + $machine, 2), 'by_enterprise' => $by];
    }

    /** @return array<string, array{name: string, kind: string, hectares: ?float, head: ?int, budget: ?float}> */
    private function enterpriseLabels(Farm $farm): array
    {
        $out = [];
        CropPlan::query()->where('farm_id', $farm->id)->whereIn('status', [...CropPlan::WORKABLE, CropPlan::COMPLETED])
            ->with(['cropType', 'field'])->get()
            ->each(function (CropPlan $p) use (&$out) {
                $out['crop_plan:'.$p->id] = ['name' => "{$p->code} · {$p->cropType?->name} on {$p->field?->code}", 'kind' => 'crop',
                    'hectares' => round($p->hectares(), 4), 'head' => null, 'budget' => $p->budget_amount !== null ? (float) $p->budget_amount : null,
                    'record_id' => $p->uuid, 'status' => $p->status];
            });
        LivestockGroup::query()->where('farm_id', $farm->id)->with('species')->get()
            ->each(function (LivestockGroup $g) use (&$out) {
                $out['livestock_group:'.$g->id] = ['name' => "{$g->code} · {$g->name}", 'kind' => 'livestock', 'hectares' => null,
                    'head' => $g->currentHeadCount(), 'budget' => $g->budget_amount !== null ? (float) $g->budget_amount : null,
                    'record_id' => $g->uuid, 'status' => $g->status];
            });

        return $out;
    }
}
