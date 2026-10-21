<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropInputTemplate;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\OverheadRule;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §3.6: every production cycle costed and margined the same way, so crops, varieties,
 * fields, seasons and methods can be compared fairly.
 *
 * Rules, applied to every plan alike:
 *  - "In the books" is what was posted to the ledger with the crop's dimension. It agrees with the
 *    trial balance; a corrected input shows as its reversal and the new issue, netted.
 *  - "Operating" is cost recorded on the farm but not posted: approved labour (payroll pays it
 *    without farm dimensions), machine time at its hourly rate, irrigation water and energy.
 *  - Inputs are split into seed, fertiliser, chemicals and packaging by the crop's input
 *    templates; anything not on a template stays "other inputs".
 *  - Variable costs: seed, fertiliser, chemicals, packaging, transport, storage, water and energy,
 *    piecework. Contribution margin = revenue − variable costs. Gross margin = contribution −
 *    the other direct costs (time-paid labour, machinery). Net margin = gross − allocated overheads.
 *  - Overheads: each active rule, per hectare (per production cycle) or as a percentage of direct costs.
 */
class CropProfitability
{
    private const BOOK_GROUPS = [
        'labour' => 'labour', 'machinery' => 'machinery', 'fuel' => 'machinery', 'repairs' => 'machinery', 'contractors' => 'machinery',
        'irrigation' => 'water_energy', 'energy' => 'water_energy', 'utilities' => 'water_energy',
        'packaging' => 'packaging', 'transport' => 'transport', 'storage' => 'storage', 'overheads' => 'overheads_posted',
    ];

    private const VARIABLE = ['seed', 'fertiliser', 'chemical', 'other_inputs', 'packaging', 'transport', 'storage', 'water_energy', 'piecework'];

    public function forPlan(CropPlan $plan): array
    {
        $plan->loadMissing(['cropType', 'variety', 'season', 'field']);
        $key = 'crop_plan:'.$plan->id;
        $lines = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $plan->company_id)->where('j.status', '!=', 'draft')->whereIn('a.type', ['income', 'expense'])
            ->whereRaw("l.dimensions->>'agri_enterprise' = ?", [$key])
            ->selectRaw("a.type, COALESCE(l.dimensions->>'agri_category', 'other') as category, SUM(l.base_credit - l.base_debit) as net")
            ->groupByRaw("a.type, COALESCE(l.dimensions->>'agri_category', 'other')")->get();
        $revenue = round((float) $lines->where('type', 'income')->sum('net'), 2);
        $book = $lines->where('type', 'expense')->mapWithKeys(fn ($r) => [$r->category => -(float) $r->net]);

        // ── Costs, grouped ─────────────────────────────────────────────────────────────────
        $costs = array_fill_keys(['seed', 'fertiliser', 'chemical', 'other_inputs', 'packaging', 'transport', 'storage', 'water_energy',
            'piecework', 'labour', 'machinery', 'overheads_posted'], 0.0);
        foreach ($this->splitInputs($plan, (float) ($book['inputs'] ?? 0)) as $cat => $amount) {
            $costs[$cat] += $amount;
        }
        foreach ($book as $cat => $amount) {
            if ($cat === 'inputs' || $cat === 'harvest') {
                continue;
            }
            $costs[self::BOOK_GROUPS[$cat] ?? 'other_inputs'] += $amount;
        }
        $booked = array_sum($costs);

        // Operating costs, recorded on the farm but never posted.
        $operating = $this->operating($plan);
        $costs['labour'] += $operating['labour'];
        $costs['piecework'] += $operating['piecework'];
        $costs['machinery'] += $operating['machinery'];
        $costs['water_energy'] += $operating['water_energy'];
        $costs = array_map(fn ($v) => round($v, 2), $costs);

        $variable = round(array_sum(array_intersect_key($costs, array_flip(self::VARIABLE))), 2);
        $direct = round(array_sum($costs), 2);
        $overheads = $this->overheads($plan, $direct);
        $contribution = round($revenue - $variable, 2);
        $gross = round($revenue - $direct, 2);
        $net = round($gross - $overheads['total'], 2);

        // ── Output and unit costs ──────────────────────────────────────────────────────────
        $records = HarvestRecord::query()->where('crop_plan_id', $plan->id)->get();
        $harvested = (float) $records->where('destination', '!=', 'loss')->sum('quantity');
        $saleable = $harvested - (float) $records->sum('rejected_quantity') - (float) $records->sum('spoiled_quantity');
        $kg = $this->toKg($harvested, $plan->yield_unit);
        $ha = $plan->hectares();
        $all = $direct + $overheads['total'];

        return [
            'plan' => ['id' => $plan->uuid, 'code' => $plan->code, 'crop' => $plan->cropType?->name, 'variety' => $plan->variety?->name,
                'season' => $plan->season?->name, 'field' => $plan->field?->code, 'method' => $plan->production_method ?: 'unspecified',
                'hectares' => round($ha, 4), 'status' => $plan->status, 'unit' => $plan->yield_unit],
            'revenue' => $revenue,
            'revenue_by_grade' => $this->revenueByGrade($records),
            'revenue_by_buyer' => $this->revenueByBuyer($plan),
            'costs' => $costs, 'booked_costs' => round($booked, 2), 'operating_costs' => round($operating['total'], 2),
            'overheads' => $overheads,
            'variable_costs' => $variable, 'direct_costs' => $direct, 'total_costs' => round($all, 2),
            'contribution_margin' => $contribution, 'gross_margin' => $gross, 'net_margin' => $net,
            'harvested' => round($harvested, 3), 'saleable' => round($saleable, 3),
            'per_ha' => $ha > 0 ? ['cost' => round($all / $ha, 2), 'revenue' => round($revenue / $ha, 2), 'net_margin' => round($net / $ha, 2)] : null,
            'per_kg' => $kg > 0 ? round($all / $kg, 4) : null,
            'per_saleable_unit' => $saleable > 0 ? round($all / $saleable, 4) : null,
        ];
    }

    /**
     * Plans side by side, grouped by variety, field, season or production method, with the same
     * rules applied to each; per-hectare figures make different sizes comparable.
     *
     * @return list<array<string, mixed>>
     */
    public function compare(Collection $plans, string $by = 'variety'): array
    {
        $rows = $plans->map(fn (CropPlan $p) => $this->forPlan($p));
        $key = fn ($r) => match ($by) {
            'field' => $r['plan']['field'] ?? '—', 'season' => $r['plan']['season'] ?? '—', 'method' => $r['plan']['method'],
            'crop' => $r['plan']['crop'] ?? '—', default => trim(($r['plan']['crop'] ?? '').' '.($r['plan']['variety'] ?? '')) ?: '—',
        };

        return $rows->groupBy($key)->map(function ($g, $name) {
            $ha = $g->sum(fn ($r) => $r['plan']['hectares']);
            $sum = fn (string $k) => round($g->sum($k), 2);

            return ['group' => $name, 'plans' => $g->pluck('plan.code')->all(), 'hectares' => round($ha, 2),
                'revenue' => $sum('revenue'), 'total_costs' => $sum('total_costs'), 'contribution_margin' => $sum('contribution_margin'),
                'gross_margin' => $sum('gross_margin'), 'net_margin' => $sum('net_margin'), 'harvested' => round($g->sum('harvested'), 3),
                'cost_per_ha' => $ha > 0 ? round($g->sum('total_costs') / $ha, 2) : null,
                'net_margin_per_ha' => $ha > 0 ? round($g->sum('net_margin') / $ha, 2) : null,
                'yield_per_ha' => $ha > 0 ? round($g->sum('harvested') / $ha, 3) : null];
        })->sortByDesc('net_margin_per_ha')->values()->all();
    }

    /**
     * The books' "inputs" total split by what the inputs were. The ledger stays the total; the
     * issued activity inputs (corrections netted: a reversed line no longer counts) give the shares.
     *
     * @return array<string, float>
     */
    private function splitInputs(CropPlan $plan, float $bookTotal): array
    {
        if ($bookTotal == 0.0) {
            return [];
        }
        $inputs = ActivityInput::query()->where('status', ActivityInput::ISSUED)
            ->whereIn('activity_id', FieldActivity::query()->where('crop_plan_id', $plan->id)->pluck('id'))->get(['stock_item_id', 'total_cost']);
        $kinds = CropInputTemplate::query()->where('crop_type_id', $plan->crop_type_id)->whereNotNull('stock_item_id')->pluck('category', 'stock_item_id');
        $by = [];
        foreach ($inputs as $i) {
            $cat = $kinds[$i->stock_item_id] ?? 'other';
            $cat = in_array($cat, ['seed', 'fertiliser', 'chemical', 'packaging'], true) ? $cat : 'other_inputs';
            $by[$cat] = ($by[$cat] ?? 0) + (float) $i->total_cost;
        }
        $sum = array_sum($by);
        if ($sum <= 0) {
            return ['other_inputs' => $bookTotal];
        }

        return array_map(fn ($v) => $bookTotal * $v / $sum, $by);
    }

    /** @return array{labour: float, piecework: float, machinery: float, water_energy: float, total: float} */
    private function operating(CropPlan $plan): array
    {
        $labour = (float) FarmTimesheet::query()->where('crop_plan_id', $plan->id)->where('status', FarmTimesheet::APPROVED)->sum('cost_amount');
        $piece = (float) PieceworkEntry::query()->where('crop_plan_id', $plan->id)->where('status', 'approved')->sum('amount');
        $acts = FieldActivity::query()->where('crop_plan_id', $plan->id)->where('status', FieldActivity::COMPLETED)->with('machine')->get();
        $labour += (float) $acts->sum('labour_cost');
        $machinery = (float) $acts->sum(fn ($a) => $a->machine && $a->machine->hourly_rate ? (float) $a->machine_hours * (float) $a->machine->hourly_rate : 0);
        $water = app(Irrigation::class)->forPlan($plan);
        $waterEnergy = (float) $water['water_cost'] + (float) $water['energy_cost'];

        return ['labour' => $labour, 'piecework' => $piece, 'machinery' => $machinery, 'water_energy' => $waterEnergy,
            'total' => $labour + $piece + $machinery + $waterEnergy];
    }

    /** @return array{total: float, lines: list<array{name: string, amount: float}>} */
    private function overheads(CropPlan $plan, float $direct): array
    {
        $start = $plan->planted_on ?? $plan->planned_planting_on;
        $rules = OverheadRule::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $plan->farm_id))
            ->whereDate('valid_from', '<=', $start)->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $start))->get();
        $lines = $rules->map(fn ($r) => ['name' => $r->name, 'basis' => $r->basis,
            'amount' => round($r->basis === 'per_ha' ? $r->rate * $plan->hectares() : $direct * $r->rate / 100, 2)])->values()->all();

        return ['total' => round(array_sum(array_column($lines, 'amount')), 2), 'lines' => $lines];
    }

    private function revenueByGrade(Collection $records): array
    {
        return $records->whereIn('destination', ['sold', 'store'])->filter(fn ($r) => $r->unit_value)
            ->groupBy(fn ($r) => $r->grade ?: 'ungraded')->map(fn ($g) => round($g->sum(fn ($r) => (float) $r->quantity * (float) $r->unit_value), 2))->all();
    }

    private function revenueByBuyer(CropPlan $plan): array
    {
        return HarvestCommitment::query()->where('crop_plan_id', $plan->id)->where('delivered_quantity', '>', 0)->get()
            ->groupBy(fn ($c) => $c->buyer.($c->channel ? " ({$c->channel})" : ''))
            ->map(fn ($g) => round($g->sum(fn ($c) => (float) $c->delivered_quantity * (float) $c->price_per_unit), 2))->all();
    }

    private function toKg(float $qty, ?string $unit): float
    {
        return match ($unit) { 't' => $qty * 1000, 'kg' => $qty, default => 0.0 };
    }
}
