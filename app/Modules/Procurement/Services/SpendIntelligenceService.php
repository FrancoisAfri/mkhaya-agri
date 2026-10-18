<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\ProcurementInsight;
use App\Modules\Procurement\Models\ProcurementPolicyRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The spend-intelligence detection engine. Rule-based detectors mine the live P2P
 * data (requests, POs, invoices, budgets, suppliers, marketplace) for fraud, waste
 * and savings, persisting each finding as a ProcurementInsight (deduped by a stable
 * signature so re-runs update rather than duplicate).
 */
class SpendIntelligenceService
{
    public function __construct(private readonly BudgetService $budgets) {}

    /** Run every detector and persist. @return array{count:int,by_type:array} */
    public function run(): array
    {
        $insights = array_merge(
            $this->detectDuplicateInvoices(),
            $this->detectSplitOrders(),
            $this->detectInflatedPricing(),
            $this->detectEmergencyAbuse(),
            $this->detectSupplierDependency(),
            $this->detectBudgetLeakage(),
            $this->detectOffContract(),
            $this->detectSavings(),
            $this->detectForecastOverruns(),
        );

        foreach ($insights as $i) {
            ProcurementInsight::updateOrCreate(
                ['signature' => $i['signature']],
                array_merge($i, [
                    'data' => isset($i['data']) ? json_encode($i['data']) : null,
                ]),
            );
        }

        $byType = collect($insights)->countBy('type')->all();

        return ['count' => count($insights), 'by_type' => $byType];
    }

    private function splitThreshold(): float
    {
        $min = ProcurementPolicyRule::where('type', 'approval_threshold')->where('active', true)->min('min_amount');

        return (float) ($min ?: 10000);
    }

    // ── Fraud / waste detectors ─────────────────────────────────────────────────

    /** Same supplier + identical amount on multiple invoices (possible double-billing). */
    private function detectDuplicateInvoices(): array
    {
        if (! Schema::hasTable('supplier_invoices')) {
            return [];
        }

        $dupes = DB::table('supplier_invoices')
            ->select('supplier_id', 'amount', DB::raw('count(*) as c'), DB::raw('string_agg(invoice_number, \', \') as numbers'))
            ->whereNull('deleted_at')
            ->where('amount', '>', 0)
            ->groupBy('supplier_id', 'amount')
            ->havingRaw('count(*) > 1')
            ->get();

        $out = [];
        foreach ($dupes as $d) {
            $supplier = $this->supplierName($d->supplier_id);
            $out[] = [
                'type' => 'duplicate', 'category' => 'risk', 'severity' => 'high',
                'score' => 80, 'amount_impact' => (float) $d->amount * ($d->c - 1),
                'title' => "Possible duplicate invoices from {$supplier}",
                'description' => "{$d->c} invoices for the same amount of ".$this->money($d->amount)." ({$d->numbers}).",
                'entity_type' => 'supplier', 'entity_id' => $d->supplier_id,
                'signature' => 'dupe-inv:'.$d->supplier_id.':'.$d->amount,
                'data' => ['count' => $d->c, 'amount' => $d->amount, 'numbers' => $d->numbers],
            ];
        }

        return $out;
    }

    /** Multiple requests by one requester just under the approval threshold in a short window. */
    private function detectSplitOrders(): array
    {
        if (! Schema::hasTable('purchase_requests')) {
            return [];
        }
        $threshold = $this->splitThreshold();
        $floor     = $threshold * 0.6;

        $rows = DB::table('purchase_requests')
            ->select('requester_id', 'department', DB::raw('count(*) as c'), DB::raw('sum(estimated_total) as total'),
                DB::raw("to_char(created_at, 'IYYY-IW') as week"))
            ->whereNull('deleted_at')
            ->whereBetween('estimated_total', [$floor, $threshold])
            ->groupBy('requester_id', 'department', 'week')
            ->havingRaw('count(*) >= 3')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $who = $this->userName($r->requester_id) ?: ($r->department ?: 'Unknown');
            $out[] = [
                'type' => 'split', 'category' => 'risk', 'severity' => 'high', 'score' => 75,
                'amount_impact' => (float) $r->total,
                'title' => "Possible order splitting by {$who}",
                'description' => "{$r->c} requests just under the ".$this->money($threshold)." approval threshold in week {$r->week} (total ".$this->money($r->total).").",
                'entity_type' => 'user', 'entity_id' => $r->requester_id,
                'signature' => 'split:'.$r->requester_id.':'.$r->week,
                'data' => ['count' => $r->c, 'total' => $r->total, 'threshold' => $threshold, 'week' => $r->week],
            ];
        }

        return $out;
    }

    /** Invoice line unit prices significantly above the historical median for that item. */
    private function detectInflatedPricing(): array
    {
        if (! Schema::hasTable('supplier_invoice_items')) {
            return [];
        }

        $medians = DB::table('supplier_invoice_items')
            ->select(DB::raw('lower(name) as k'), DB::raw('percentile_cont(0.5) within group (order by unit_price) as median'), DB::raw('count(*) as c'))
            ->where('unit_price', '>', 0)
            ->groupBy(DB::raw('lower(name)'))
            ->havingRaw('count(*) >= 3')
            ->get()->keyBy('k');

        if ($medians->isEmpty()) {
            return [];
        }

        $items = DB::table('supplier_invoice_items')
            ->select('id', 'supplier_invoice_id', 'name', 'unit_price')
            ->where('unit_price', '>', 0)->get();

        $out = [];
        foreach ($items as $it) {
            $m = $medians[strtolower($it->name)] ?? null;
            if (! $m || $m->median <= 0) {
                continue;
            }
            $ratio = $it->unit_price / $m->median;
            if ($ratio >= 1.3) {
                $out[] = [
                    'type' => 'inflated_price', 'category' => 'risk',
                    'severity' => $ratio >= 1.75 ? 'high' : 'medium',
                    'score' => min(95, round($ratio * 50)),
                    'amount_impact' => round(($it->unit_price - $m->median), 2),
                    'title' => "Inflated price for '{$it->name}'",
                    'description' => 'Unit price '.$this->money($it->unit_price).' is '.round(($ratio - 1) * 100).'% above the median '.$this->money($m->median).'.',
                    'entity_type' => 'supplier_invoice', 'entity_id' => $it->supplier_invoice_id,
                    'signature' => 'inflated:item:'.$it->id,
                    'data' => ['unit_price' => $it->unit_price, 'median' => $m->median, 'ratio' => round($ratio, 2)],
                ];
            }
        }

        return $out;
    }

    /** Requesters/departments with a high share of emergency requests (control bypass). */
    private function detectEmergencyAbuse(): array
    {
        if (! Schema::hasTable('purchase_requests')) {
            return [];
        }

        $rows = DB::table('purchase_requests')
            ->select('requester_id', DB::raw('count(*) as total'),
                DB::raw('sum(case when is_emergency then 1 else 0 end) as emergencies'))
            ->whereNull('deleted_at')
            ->groupBy('requester_id')
            ->havingRaw('sum(case when is_emergency then 1 else 0 end) >= 3')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $share = $r->total > 0 ? $r->emergencies / $r->total : 0;
            if ($share < 0.4) {
                continue;
            }
            $who = $this->userName($r->requester_id) ?: 'Unknown';
            $out[] = [
                'type' => 'emergency_abuse', 'category' => 'risk',
                'severity' => $share >= 0.7 ? 'high' : 'medium',
                'score' => round($share * 100),
                'title' => "Frequent emergency purchases by {$who}",
                'description' => "{$r->emergencies} of {$r->total} requests (".round($share * 100)."%) flagged emergency, bypassing normal sourcing.",
                'entity_type' => 'user', 'entity_id' => $r->requester_id,
                'signature' => 'emergency:'.$r->requester_id,
                'data' => ['emergencies' => $r->emergencies, 'total' => $r->total, 'share' => round($share, 2)],
            ];
        }

        return $out;
    }

    /** A single supplier holding a dominant share of total PO spend (dependency/favoritism). */
    private function detectSupplierDependency(): array
    {
        if (! Schema::hasTable('purchase_orders')) {
            return [];
        }

        $total = (float) DB::table('purchase_orders')->whereNull('deleted_at')->where('status', '!=', 'cancelled')->sum('amount');
        if ($total <= 0) {
            return [];
        }

        $bySupplier = DB::table('purchase_orders')
            ->select('supplier_id', DB::raw('sum(amount) as spend'), DB::raw('count(*) as c'))
            ->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->groupBy('supplier_id')->get();

        $out = [];
        foreach ($bySupplier as $s) {
            $share = $s->spend / $total;
            if ($share < 0.5 || $s->c < 2) {
                continue;
            }
            $supplier = $this->supplierName($s->supplier_id);
            $out[] = [
                'type' => 'dependency', 'category' => 'risk',
                'severity' => $share >= 0.7 ? 'high' : 'medium',
                'score' => round($share * 100), 'amount_impact' => (float) $s->spend,
                'title' => "Supplier concentration risk — {$supplier}",
                'description' => $supplier.' holds '.round($share * 100).'% of total PO spend ('.$this->money($s->spend).') — single-supplier dependency.',
                'entity_type' => 'supplier', 'entity_id' => $s->supplier_id,
                'signature' => 'dependency:'.$s->supplier_id,
                'data' => ['spend' => $s->spend, 'share' => round($share, 2), 'orders' => $s->c],
            ];
        }

        return $out;
    }

    /** Budget waste: approved overrides, stale uncommitted reservations, over-consumption. */
    private function detectBudgetLeakage(): array
    {
        $out = [];
        foreach (Budget::where('status', 'active')->get() as $budget) {
            $a = $this->budgets->amounts($budget);

            // Over-committed/consumed beyond allocation (excluding approved overrides).
            $usedBeyond = round(($a['committed'] + $a['actual']) - $a['allocated'], 2);
            if ($usedBeyond > 0) {
                $out[] = [
                    'type' => 'budget_leakage', 'category' => 'risk',
                    'severity' => $usedBeyond > $a['allocated'] * 0.1 ? 'high' : 'medium',
                    'score' => 70, 'amount_impact' => $usedBeyond,
                    'title' => "Budget over-spend — {$budget->name}",
                    'description' => 'Committed + actual exceed the allocation by '.$this->money($usedBeyond).' (relying on overrides).',
                    'entity_type' => 'budget', 'entity_id' => $budget->id,
                    'signature' => 'leakage:over:'.$budget->id,
                    'data' => $a,
                ];
            }

            // Stale reservations tying up funds (>30 days, never committed).
            $stale = (float) $budget->reservations()->where('status', 'active')
                ->where('created_at', '<', Carbon::now()->subDays(30))->sum('amount');
            if ($stale > 0) {
                $out[] = [
                    'type' => 'budget_leakage', 'category' => 'risk', 'severity' => 'medium',
                    'score' => 55, 'amount_impact' => $stale,
                    'title' => "Stale reservations — {$budget->name}",
                    'description' => $this->money($stale).' reserved for over 30 days without a purchase order — tied-up funds.',
                    'entity_type' => 'budget', 'entity_id' => $budget->id,
                    'signature' => 'leakage:stale:'.$budget->id,
                    'data' => ['stale_reserved' => $stale],
                ];
            }
        }

        return $out;
    }

    /** POs to suppliers with no active contract (off-contract spend), if contracts are tracked. */
    private function detectOffContract(): array
    {
        if (! Schema::hasTable('purchase_orders') || ! Schema::hasTable('contracts') || ! Schema::hasColumn('contracts', 'supplier_id')) {
            return [];
        }
        $threshold = $this->splitThreshold();

        $contracted = DB::table('contracts')->whereNull('deleted_at')
            ->when(Schema::hasColumn('contracts', 'status'), fn ($q) => $q->whereIn('status', ['active', 'signed', 'executed']))
            ->pluck('supplier_id')->filter()->unique()->all();

        $rows = DB::table('purchase_orders')
            ->select('supplier_id', DB::raw('sum(amount) as spend'), DB::raw('count(*) as c'))
            ->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->where('amount', '>=', $threshold)
            ->when($contracted, fn ($q) => $q->whereNotIn('supplier_id', $contracted))
            ->groupBy('supplier_id')->get();

        $out = [];
        foreach ($rows as $r) {
            $supplier = $this->supplierName($r->supplier_id);
            $out[] = [
                'type' => 'off_contract', 'category' => 'risk', 'severity' => 'medium',
                'score' => 60, 'amount_impact' => (float) $r->spend,
                'title' => "Off-contract spend — {$supplier}",
                'description' => $this->money($r->spend).' across '.$r->c.' PO(s) to a supplier with no active contract.',
                'entity_type' => 'supplier', 'entity_id' => $r->supplier_id,
                'signature' => 'offcontract:'.$r->supplier_id,
                'data' => ['spend' => $r->spend, 'orders' => $r->c],
            ];
        }

        return $out;
    }

    // ── Savings recommendations ──────────────────────────────────────────────────

    /** Invoiced/ordered items priced above an available marketplace alternative. */
    private function detectSavings(): array
    {
        if (! Schema::hasTable('marketplace_products') || ! Schema::hasTable('supplier_invoice_items')) {
            return [];
        }

        $market = DB::table('marketplace_products')->where('price', '>', 0)
            ->select(DB::raw('lower(name) as k'), DB::raw('min(price) as best'))
            ->groupBy(DB::raw('lower(name)'))->get()->keyBy('k');
        if ($market->isEmpty()) {
            return [];
        }

        $items = DB::table('supplier_invoice_items')->where('unit_price', '>', 0)
            ->select('id', 'supplier_invoice_id', 'name', 'unit_price', 'quantity')->get();

        $out = [];
        foreach ($items as $it) {
            $m = $market[strtolower($it->name)] ?? null;
            if (! $m || $m->best <= 0 || $it->unit_price <= $m->best * 1.05) {
                continue;
            }
            $saving = round(($it->unit_price - $m->best) * max(1, (float) $it->quantity), 2);
            $out[] = [
                'type' => 'saving', 'category' => 'saving', 'severity' => 'low',
                'score' => 50, 'amount_impact' => $saving,
                'title' => "Cheaper marketplace option for '{$it->name}'",
                'description' => 'Paid '.$this->money($it->unit_price).' vs marketplace '.$this->money($m->best).' — potential saving '.$this->money($saving).'.',
                'entity_type' => 'supplier_invoice', 'entity_id' => $it->supplier_invoice_id,
                'signature' => 'saving:market:'.$it->id,
                'data' => ['paid' => $it->unit_price, 'market_best' => $m->best, 'saving' => $saving],
            ];
        }

        return $out;
    }

    // ── Forecast ─────────────────────────────────────────────────────────────────

    /** Run-rate projection per active budget; flag those trending to exceed allocation. */
    private function detectForecastOverruns(): array
    {
        $out = [];
        foreach (Budget::where('status', 'active')->get() as $budget) {
            $a       = $this->budgets->amounts($budget);
            $elapsed = $this->elapsedFraction($budget);
            if ($elapsed <= 0.05 || $a['allocated'] <= 0) {
                continue;
            }
            $consumed  = $a['committed'] + $a['actual'];
            $projected = round($consumed / $elapsed, 2);
            if ($projected <= $a['allocated']) {
                continue;
            }
            $overrun = round($projected - $a['allocated'], 2);
            $out[] = [
                'type' => 'forecast', 'category' => 'forecast',
                'severity' => $overrun > $a['allocated'] * 0.2 ? 'high' : 'medium',
                'score' => min(95, round(($projected / max(1, $a['allocated'])) * 50)),
                'amount_impact' => $overrun,
                'title' => "Projected overrun — {$budget->name}",
                'description' => 'At the current run-rate ('.round($elapsed * 100).'% of period elapsed) spend is projected at '.$this->money($projected).' vs allocation '.$this->money($a['allocated']).'.',
                'entity_type' => 'budget', 'entity_id' => $budget->id,
                'signature' => 'forecast:'.$budget->id,
                'data' => ['projected' => $projected, 'allocated' => $a['allocated'], 'elapsed' => round($elapsed, 2)],
            ];
        }

        return $out;
    }

    private function elapsedFraction(Budget $budget): float
    {
        $start = $budget->start_date ? Carbon::parse($budget->start_date) : Carbon::parse($budget->created_at);
        $end   = $budget->end_date ? Carbon::parse($budget->end_date) : (clone $start)->addYear();
        $now   = Carbon::now();

        $total = $start->diffInDays($end) ?: 365;
        $done  = $start->diffInDays($now->min($end));

        return max(0.0, min(1.0, $done / $total));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function supplierName(?int $id): string
    {
        if (! $id) {
            return 'Unknown supplier';
        }

        return (string) (DB::table('suppliers')->where('id', $id)->value('company_name') ?: "Supplier #{$id}");
    }

    private function userName(?int $id): ?string
    {
        if (! $id) {
            return null;
        }
        if (Schema::hasTable('hr_people')) {
            $name = DB::table('hr_people')->where('user_id', $id)
                ->selectRaw("trim(concat(coalesce(first_name,''),' ',coalesce(surname,''))) as n")->value('n');
            if ($name && trim($name) !== '') {
                return trim($name);
            }
        }

        return DB::table('users')->where('id', $id)->value('email');
    }

    private function money(float|string|null $v): string
    {
        return 'R'.number_format((float) $v, 2);
    }
}
