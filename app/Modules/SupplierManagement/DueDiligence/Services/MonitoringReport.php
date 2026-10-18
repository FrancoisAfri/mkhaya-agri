<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierChangeEvent;
use App\Modules\SupplierManagement\Models\SupplierFinding;
use App\Modules\SupplierManagement\Models\SupplierMonitoringRun;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use Illuminate\Support\Carbon;

/**
 * The monitoring report: what the watching actually did over a period.
 *
 * Written to be defensible rather than flattering. The two numbers an executive will look for are
 * "how many suppliers do we monitor" and "how many alerts did we get", and both are easy to make
 * look good — count every supplier as monitored whether or not anything was searched, count every
 * finding as an alert whether or not anybody read it. So this reports coverage as the number
 * actually swept in the period, and separates alerts nobody has looked at from alerts that were
 * worked, because the first number is the one that matters and it is the one that gets buried.
 *
 * Risk movement is counted from the snapshots rather than from the current score, so a supplier that
 * rose and fell inside the period is not reported as unchanged.
 */
class MonitoringReport
{
    /**
     * @return array<string, mixed>
     */
    public function forPeriod(?Carbon $from = null, ?Carbon $to = null): array
    {
        $to ??= Carbon::now();
        $from ??= $to->copy()->startOfMonth();

        $activeSuppliers = Supplier::query()->where('is_active', 1)->count();

        $runs = SupplierMonitoringRun::query()
            ->whereBetween('started_at', [$from, $to])
            ->get();

        $swept = $runs->pluck('supplier_id')->unique();

        $changes = SupplierChangeEvent::query()
            ->whereBetween('detected_at', [$from, $to])
            ->get();

        $findings = SupplierFinding::query()
            ->whereBetween('first_seen_at', [$from, $to])
            ->get();

        $hits = ScreeningHit::query()
            ->whereBetween('first_seen_at', [$from, $to])
            ->get();

        $alerts = $changes->concat($findings->map(fn ($f) => (object) [
            'severity' => $f->severity ?: 'low',
            'status' => $f->status,
        ]))->concat($hits->map(fn ($h) => (object) [
            'severity' => $h->list_type === ScreeningHit::TYPE_SANCTIONS ? 'critical' : 'high',
            'status' => $h->status,
        ]));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],

            'coverage' => [
                'active_suppliers' => $activeSuppliers,
                // Swept in this period, not "on the books". A supplier nobody looked at is not a
                // supplier being monitored, however many rows the directory has.
                'monitored' => $swept->count(),
                'never_monitored' => Supplier::query()->where('is_active', 1)
                    ->whereNull('last_monitored_at')->count(),
                'runs' => $runs->count(),
                'runs_with_no_source' => $runs->where('sources_unavailable', '>', 0)->count(),
            ],

            'alerts' => [
                'total' => $alerts->count(),
                'critical' => $alerts->where('severity', 'critical')->count(),
                'high' => $alerts->where('severity', 'high')->count(),
                'medium' => $alerts->where('severity', 'medium')->count(),
                'low' => $alerts->whereIn('severity', ['low', 'none'])->count(),
                // The number that matters, and the one that gets buried.
                'unreviewed' => $alerts->whereIn('status', ['open', 'needs_review'])->count(),
                'false_positives' => $alerts->where('status', 'false_positive')->count(),
            ],

            'changes' => [
                'total' => $changes->count(),
                'by_category' => $changes->groupBy('category')->map->count(),
                'triggered_reassessment' => $changes->where('triggered_reassessment', true)->count(),
            ],

            'risk' => $this->riskMovement($from, $to),

            'attention' => [
                // Suppliers somebody has to do something about, listed rather than counted, because a
                // count of nine tells nobody which nine.
                'suppliers' => $this->needingAttention($from, $to),
            ],
        ];
    }

    /**
     * How risk moved over the period, from the snapshots.
     *
     * Compares the last snapshot before the period with the last inside it, per supplier — the
     * current score alone cannot tell you a supplier rose and fell back.
     */
    private function riskMovement(Carbon $from, Carbon $to): array
    {
        $increased = 0;
        $decreased = 0;
        $unchanged = 0;

        SupplierRiskScore::query()
            ->whereBetween('calculated_at', [$from, $to])
            ->get()
            ->groupBy('supplier_id')
            ->each(function ($snapshots) use (&$increased, &$decreased, &$unchanged) {
                $latest = $snapshots->sortByDesc('calculated_at')->first();
                $movement = $latest->movement();

                match (true) {
                    $movement === null || abs($movement) < 0.01 => $unchanged++,
                    $movement > 0 => $increased++,
                    default => $decreased++,
                };
            });

        return ['increased' => $increased, 'decreased' => $decreased, 'unchanged' => $unchanged];
    }

    /** @return array<int, array<string, mixed>> */
    private function needingAttention(Carbon $from, Carbon $to): array
    {
        $bySupplier = SupplierChangeEvent::query()
            ->unreviewed()
            ->serious()
            ->whereBetween('detected_at', [$from, $to])
            ->get()
            ->groupBy('supplier_id');

        return Supplier::query()
            ->whereIn('id', $bySupplier->keys())
            ->get()
            ->map(fn (Supplier $supplier) => [
                'supplier_id' => $supplier->id,
                'name' => $supplier->company_name,
                'risk_band' => $supplier->risk_band,
                'changes' => $bySupplier[$supplier->id]->count(),
                'worst' => $bySupplier[$supplier->id]->contains(fn ($c) => $c->severity === 'critical') ? 'critical' : 'high',
                'summary' => $bySupplier[$supplier->id]->first()->summary,
            ])
            ->sortByDesc(fn ($row) => $row['worst'] === 'critical' ? 1 : 0)
            ->values()
            ->all();
    }
}
