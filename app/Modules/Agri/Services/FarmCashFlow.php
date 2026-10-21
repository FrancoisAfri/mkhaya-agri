<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmCapexPlan;
use App\Modules\Agri\Models\FarmLoan;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\Finance\Accounting\Services\CashFlowForecastService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §6.4: the organisation's cash, week by week, with the farm's own flows on top of
 * Finance's forecast (bank, receivables, payables, recurring invoices): harvests still to come and
 * when buyers pay for them, the labour bill, orders placed but not yet invoiced, inputs still to buy,
 * loan instalments and planned capital spending. Every flow carries the assumption behind it, and a
 * scenario changes the assumptions, never the records.
 */
class FarmCashFlow
{
    public const SCENARIO = ['harvest_delay_weeks' => 0, 'price_change_pct' => 0, 'input_cost_change_pct' => 0, 'yield_change_pct' => 0,
        'irrigation_cut_pct' => 0, 'payment_delay_days' => 0, 'labour_change_pct' => 0];

    public function forecast(int $companyId, int $weeks = 26, array $scenario = []): array
    {
        $s = array_merge(self::SCENARIO, array_intersect_key($scenario, self::SCENARIO));
        $base = app(CashFlowForecastService::class)->forecast($weeks, $companyId);
        $start = Carbon::parse($base['weeks'][0]['start'] ?? today()->toDateString());
        $buckets = [];
        foreach ($base['weeks'] as $w) {
            $buckets[] = ['week' => $w['week'], 'start' => $w['start'], 'end' => $w['end'], 'in' => ['finance' => (float) $w['inflow']], 'out' => ['finance' => (float) $w['outflow']]];
        }
        $assumptions = ["Opening cash and receivables, payables and recurring invoices come from Finance's forecast."];
        // §10 acceptance: the farm flows only for the farms this person can reach (null: every farm).
        $reach = app(FarmAccess::class)->farmIdsFor();
        $farmScope = fn ($q, string $col = 'farm_id') => $reach === null ? $q : $q->whereIn($col, $reach);
        if ($reach !== null) {
            $assumptions[] = 'Farm flows cover only the farms you can open.';
        }
        $add = function (string $dir, string $source, Carbon $on, float $amount) use (&$buckets, $start, $weeks) {
            if ($amount <= 0) {
                return;
            }
            $i = (int) max(0, floor($start->diffInDays($on, false) / 7));   // anything overdue lands in the first week
            if ($i >= $weeks) {
                return;
            }
            $buckets[$i][$dir][$source] = round(($buckets[$i][$dir][$source] ?? 0) + $amount, 2);
        };

        // ── Harvests still to come, paid on the buyer's terms ──────────────────────────────
        $terms = 30 + (int) $s['payment_delay_days'];
        $yieldF = (1 + $s['yield_change_pct'] / 100);
        $priceF = (1 + $s['price_change_pct'] / 100);
        foreach ($farmScope(CropPlan::withoutGlobalScopes()->where('company_id', $companyId))->whereIn('status', [CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING])->with('field')->get() as $p) {
            $f = HarvestForecast::withoutGlobalScopes()->where('crop_plan_id', $p->id)->latest('id')->first();
            $expected = $f?->quantity ?? ($p->expected_yield !== null ? (float) $p->expected_yield * $p->hectares() : null);
            if (! $expected) {
                continue;
            }
            $irrigated = $p->field && $p->field->irrigation && $p->field->irrigation !== 'dryland';
            $irrF = $irrigated ? 1 - ($s['irrigation_cut_pct'] / 100) * 0.5 : 1;
            $left = max(0, $expected * $yieldF * $irrF - (float) HarvestRecord::withoutGlobalScopes()->where('crop_plan_id', $p->id)->where('destination', '!=', 'loss')->sum('quantity'));
            $commit = HarvestCommitment::withoutGlobalScopes()->where('crop_plan_id', $p->id)->whereIn('status', ['open', 'short'])->get();
            $committedQty = min($left, (float) $commit->sum(fn ($c) => $c->quantity - $c->delivered_quantity));
            $committedPrice = $commit->isNotEmpty() && $commit->sum('quantity') > 0 ? $commit->sum(fn ($c) => $c->quantity * (float) $c->price_per_unit) / $commit->sum('quantity') : null;
            $price = (float) ($p->expected_price ?? $committedPrice ?? 0);
            $value = ($committedQty * ($committedPrice ?? $price) + ($left - $committedQty) * $price) * $priceF;
            $ready = ($f?->ready_on ?? $p->planned_harvest_on)->copy()->addWeeks((int) $s['harvest_delay_weeks']);
            $add('in', 'harvest', $ready->copy()->addDays(7 + $terms), $value);
        }
        $assumptions[] = "Harvests: the latest forecast (or expected yield × area) less what is already in, at the commitment price or the plan's expected price, paid {$terms} days after a week of harvesting."
            .($s['irrigation_cut_pct'] ? " Irrigated crops lose half the irrigation cut ({$s['irrigation_cut_pct']}%) in yield." : '');

        // ── Labour, at the last four weeks' approved run rate ──────────────────────────────
        $since = today()->subDays(28)->toDateString();
        $weekly = ((float) $farmScope(FarmTimesheet::withoutGlobalScopes()->where('company_id', $companyId))->where('status', 'approved')->where('work_date', '>=', $since)->sum('cost_amount')
            + (float) $farmScope(PieceworkEntry::withoutGlobalScopes()->where('company_id', $companyId))->where('status', 'approved')->where('work_date', '>=', $since)->sum('amount')) / 4
            * (1 + $s['labour_change_pct'] / 100);
        for ($i = 0; $i < $weeks; $i++) {
            $add('out', 'labour', $start->copy()->addWeeks($i), $weekly);
        }
        $assumptions[] = 'Farm labour: the last four weeks of approved time and piecework, paid weekly'.($s['labour_change_pct'] ? " (changed {$s['labour_change_pct']}%)" : '').'.';

        // ── Orders to farm stores not yet invoiced, paid on the supplier's terms ───────────
        $inputF = 1 + $s['input_cost_change_pct'] / 100;
        $orders = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')->leftJoin('suppliers as su', 'su.id', '=', 'o.supplier_id')
            ->whereIn('o.warehouse_id', $farmScope(FarmStore::withoutGlobalScopes()->where('company_id', $companyId))->pluck('warehouse_id'))
            ->whereNull('o.deleted_at')->whereNotIn('o.status', ['draft', 'cancelled', 'rejected', 'closed'])
            ->whereNotExists(fn ($q) => $q->from('supplier_invoices as v')->whereColumn('v.purchase_order_id', 'o.id')->whereNull('v.deleted_at'))
            ->get(['o.expected_delivery', 'o.order_date', 'su.payment_terms', 'i.quantity', 'i.unit_price']);
        foreach ($orders as $o) {
            $days = (int) (preg_match('/(\d+)/', (string) $o->payment_terms, $m) ? $m[1] : 30);
            $add('out', 'orders', Carbon::parse($o->expected_delivery ?? $o->order_date)->addDays($days), (float) $o->quantity * (float) $o->unit_price * $inputF);
        }
        $assumptions[] = "Orders to the farm's stores not yet invoiced: due on the supplier's payment terms after delivery".($s['input_cost_change_pct'] ? ", at {$s['input_cost_change_pct']}% changed input prices." : '.');

        // ── Inputs the crop plans still need to buy ────────────────────────────────────────
        $plans = $farmScope(CropPlan::withoutGlobalScopes()->where('company_id', $companyId))->whereIn('status', CropPlan::LIVE)->get();
        foreach (app(InputPlanning::class)->forPlans($plans) as $line) {
            if ($line['to_buy'] > 0 && $line['quantity'] > 0) {
                $add('out', 'inputs to buy', Carbon::parse($line['order_by'] ?? $line['earliest_need'] ?? today())->addDays(30), $line['cost'] * $line['to_buy'] / $line['quantity'] * $inputF);
            }
        }
        $assumptions[] = 'Inputs the approved rates call for that are neither in store nor on order: paid 30 days after they must be ordered.';

        // ── Loans and capital spending ─────────────────────────────────────────────────────
        $orgOrReach = fn ($q) => $reach === null ? $q : $q->where(fn ($w) => $w->whereNull('farm_id')->orWhereIn('farm_id', $reach));
        foreach ($orgOrReach(FarmLoan::withoutGlobalScopes()->where('company_id', $companyId))->where('is_active', true)->get() as $loan) {
            $step = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12, 'seasonal' => 6][$loan->frequency] ?? 1;
            for ($n = 0; $n < $loan->instalments; $n++) {
                $add('out', 'loans', $loan->first_payment_on->copy()->addMonths($n * $step), $loan->payment);
            }
        }
        foreach ($orgOrReach(FarmCapexPlan::withoutGlobalScopes()->where('company_id', $companyId))->whereIn('status', ['planned', 'committed'])->get() as $c) {
            $add('out', 'capital', $c->planned_on, $c->amount);
        }
        $assumptions[] = 'Loan instalments on their schedule; planned and committed capital spending on its planned date.';

        // ── Running balance ────────────────────────────────────────────────────────────────
        $running = (float) $base['opening'];
        $short = [];
        foreach ($buckets as &$b) {
            $b['inflow'] = round(array_sum($b['in']), 2);
            $b['outflow'] = round(array_sum($b['out']), 2);
            $running = round($running + $b['inflow'] - $b['outflow'], 2);
            $b['closing'] = $running;
            if ($running < 0) {
                $short[] = $b['start'];
            }
        }

        return ['opening' => (float) $base['opening'], 'weeks' => $buckets, 'closing' => $running, 'lowest' => collect($buckets)->min('closing'),
            'shortage_weeks' => $short, 'first_shortage' => $short[0] ?? null, 'scenario' => $s, 'assumptions' => $assumptions];
    }
}
