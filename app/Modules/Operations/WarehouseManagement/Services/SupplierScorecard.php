<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier performance from what actually arrived (WMS-034): the evidence the existing appraisal
 * screens have never had, computed from receipts, discrepancies, inspections, invoices and returns.
 *
 * It feeds Supplier Management rather than keeping a parallel score: a supplier whose recent
 * deliveries fall below the thresholds gets an open `delivery_performance` risk flag on the record
 * Procurement already reads, and the flag resolves itself when the evidence recovers.
 */
class SupplierScorecard
{
    public const MIN_DELIVERIES = 5;

    public const ON_TIME_FLOOR = 0.8;

    public const DEFECT_CEILING = 0.05;

    /** @return list<array<string,mixed>> one row per supplier with deliveries in the period */
    public function scores(Carbon $from, Carbon $to, ?int $supplierId = null): array
    {
        $grns = DB::table('goods_received_notes as g')
            ->join('purchase_orders as o', 'o.id', '=', 'g.purchase_order_id')
            ->whereBetween('g.received_at', [$from, $to])
            ->when($supplierId, fn ($q) => $q->where('o.supplier_id', $supplierId))
            ->groupBy('o.supplier_id')
            ->selectRaw('o.supplier_id, COUNT(DISTINCT g.id) AS deliveries')
            ->selectRaw('COUNT(DISTINCT CASE WHEN o.expected_delivery IS NOT NULL THEN g.id END) AS with_due_date')
            ->selectRaw('COUNT(DISTINCT CASE WHEN o.expected_delivery IS NOT NULL AND g.received_at::date <= o.expected_delivery THEN g.id END) AS on_time')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (g.received_at - o.order_date::timestamp)) / 86400.0) AS lead_days')
            ->get()->keyBy('supplier_id');

        $lines = DB::table('goods_received_items as i')
            ->join('goods_received_notes as g', 'g.id', '=', 'i.goods_received_note_id')
            ->join('purchase_orders as o', 'o.id', '=', 'g.purchase_order_id')
            ->whereBetween('g.received_at', [$from, $to])
            ->when($supplierId, fn ($q) => $q->where('o.supplier_id', $supplierId))
            ->groupBy('o.supplier_id')
            ->selectRaw('o.supplier_id, COUNT(*) AS lines, SUM(i.received_qty) AS received, SUM(i.damaged_qty) AS damaged, SUM(i.rejected_qty) AS rejected')
            ->get()->keyBy('supplier_id');

        $disc = DB::table('wms_receipt_discrepancies as d')
            ->join('goods_received_notes as g', 'g.id', '=', 'd.grn_id')
            ->join('purchase_orders as o', 'o.id', '=', 'g.purchase_order_id')
            ->whereBetween('g.received_at', [$from, $to])
            ->when($supplierId, fn ($q) => $q->where('o.supplier_id', $supplierId))
            ->groupBy('o.supplier_id')
            ->selectRaw("o.supplier_id, COUNT(DISTINCT CASE WHEN d.kind IN ('OVER','UNDER','MISSING','WRONG_ITEM') AND NOT d.within_tolerance THEN d.grn_item_id END) AS qty_wrong_lines")
            ->selectRaw("COUNT(CASE WHEN d.kind IN ('DUPLICATE','UNEXPECTED') THEN 1 END) AS document_issues")
            ->selectRaw('AVG(CASE WHEN d.resolved_at IS NOT NULL AND NOT d.within_tolerance THEN EXTRACT(EPOCH FROM (d.resolved_at - d.created_at)) / 3600.0 END) AS resolution_hours')
            ->get()->keyBy('supplier_id');

        $inspections = DB::table('mfg_inspections')->where('stage', 'INCOMING')->whereNotNull('supplier_id')
            ->whereBetween('created_at', [$from, $to])->whereNotNull('disposition')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->groupBy('supplier_id')
            ->selectRaw("supplier_id, COUNT(*) AS decided, COUNT(CASE WHEN disposition IN ('ACCEPT','CONDITIONAL_ACCEPT') THEN 1 END) AS passed")
            ->get()->keyBy('supplier_id');

        $invoices = DB::table('supplier_invoices')->whereNull('deleted_at')->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->groupBy('supplier_id')
            ->selectRaw("supplier_id, COUNT(*) AS invoices, COUNT(CASE WHEN ABS(COALESCE(variance_amount, 0)) > 0.005 OR match_status IN ('mismatch','variance','exception') THEN 1 END) AS price_issues")
            ->get()->keyBy('supplier_id');

        $returns = DB::table('wms_supplier_returns')->whereBetween('created_at', [$from, $to])->where('status', '!=', 'CANCELLED')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->groupBy('supplier_id')->selectRaw('supplier_id, COUNT(*) AS returns')->pluck('returns', 'supplier_id');

        $names = DB::table('suppliers')->whereIn('id', $grns->keys())->pluck('company_name', 'id');
        $ratio = fn ($a, $b) => $b > 0 ? round($a / $b, 4) : null;

        return $grns->map(function ($g, $sid) use ($lines, $disc, $inspections, $invoices, $returns, $names, $ratio) {
            $l = $lines[$sid] ?? null;
            $d = $disc[$sid] ?? null;
            $i = $inspections[$sid] ?? null;
            $v = $invoices[$sid] ?? null;
            $received = (float) ($l->received ?? 0);

            return [
                'supplier_id' => (int) $sid, 'supplier' => $names[$sid] ?? '#'.$sid,
                'deliveries' => (int) $g->deliveries,
                'on_time_rate' => $ratio((int) $g->on_time, (int) $g->with_due_date),
                'quantity_accuracy' => $l && $l->lines > 0 ? round(1 - ((int) ($d->qty_wrong_lines ?? 0)) / (int) $l->lines, 4) : null,
                'damage_rate' => $ratio((float) ($l->damaged ?? 0), $received),
                'rejection_rate' => $ratio((float) ($l->rejected ?? 0), $received),
                'inspection_pass_rate' => $i ? $ratio((int) $i->passed, (int) $i->decided) : null,
                'price_discrepancy_rate' => $v ? $ratio((int) $v->price_issues, (int) $v->invoices) : null,
                'document_issues' => (int) ($d->document_issues ?? 0),
                'avg_lead_time_days' => $g->lead_days !== null ? round((float) $g->lead_days, 1) : null,
                'avg_resolution_hours' => isset($d->resolution_hours) && $d->resolution_hours !== null ? round((float) $d->resolution_hours, 1) : null,
                'returns' => (int) ($returns[$sid] ?? 0),
            ];
        })->sortBy('supplier')->values()->all();
    }

    /**
     * Raise or resolve the supplier's delivery-performance risk flag from the last 90 days. Below the
     * minimum number of deliveries there is no verdict either way -- three late trucks prove nothing.
     */
    public function refreshFlag(int $supplierId, ?int $companyId = null): void
    {
        $row = $this->scores(now()->subDays(90), now(), $supplierId)[0] ?? null;
        $open = DB::table('supplier_risk_flags')->where('supplier_id', $supplierId)->where('type', 'delivery_performance')
            ->where('status', 'open')->whereNull('deleted_at')->first();

        if (! $row || $row['deliveries'] < self::MIN_DELIVERIES) {
            return;
        }

        $problems = [];
        if ($row['on_time_rate'] !== null && $row['on_time_rate'] < self::ON_TIME_FLOOR) {
            $problems[] = round($row['on_time_rate'] * 100).'% on time';
        }
        $defects = (float) ($row['damage_rate'] ?? 0) + (float) ($row['rejection_rate'] ?? 0);
        if ($defects > self::DEFECT_CEILING) {
            $problems[] = round($defects * 100, 1).'% damaged or rejected';
        }

        if ($problems === [] && $open) {
            DB::table('supplier_risk_flags')->where('id', $open->id)->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);

            return;
        }
        if ($problems === []) {
            return;
        }

        $message = 'Deliveries in the last 90 days: '.implode(', ', $problems)." across {$row['deliveries']} deliveries.";
        if ($open) {
            DB::table('supplier_risk_flags')->where('id', $open->id)->update(['message' => $message, 'context' => json_encode($row), 'updated_at' => now()]);

            return;
        }
        DB::table('supplier_risk_flags')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'supplier_id' => $supplierId, 'type' => 'delivery_performance',
            'severity' => 'medium', 'message' => $message, 'context' => json_encode($row), 'status' => 'open',
            'company_id' => $companyId ?? DB::table('suppliers')->where('id', $supplierId)->value('company_id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
