<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ContractAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contract::class);

        $period = (string) $request->input('period', 'year');
        $status = $request->input('status');
        $type = $request->input('type');

        [$from, $to] = $this->resolveRange($period, $request->input('date_from'), $request->input('date_to'));

        // Base query honouring filters (used for most aggregates).
        $base = function () use ($status, $type, $from, $to) {
            $q = Contract::query();
            if ($status) {
                $q->where('contract_status', $status);
            }
            if ($type) {
                $q->where('contract_type_id', $type);
            }
            if ($from) {
                $q->where('contracts.created_at', '>=', $from);
            }
            if ($to) {
                $q->where('contracts.created_at', '<=', $to);
            }

            return $q;
        };

        $now = Carbon::now();
        $expiringLimit = $now->copy()->addDays(90)->endOfDay();

        // ── KPIs ─────────────────────────────────────────────────────────────
        $total = (clone $base())->count();
        $active = (clone $base())->where('contract_status', 'active')->count();
        $expiring90 = (clone $base())
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$now->copy()->startOfDay(), $expiringLimit])
            ->count();
        $totalValue = (float) (clone $base())->sum('contract_value');
        $avgValue = $total > 0 ? round($totalValue / $total, 2) : 0.0;
        $highRisk = (clone $base())
            ->join('contract_ai', 'contract_ai.contract_id', '=', 'contracts.id')
            ->where('contract_ai.risk_score', '>=', 67)
            ->count();

        $kpis = [
            'total' => $total,
            'active' => $active,
            'expiring_90' => $expiring90,
            'total_value' => round($totalValue, 2),
            'avg_value' => $avgValue,
            'high_risk' => $highRisk,
        ];

        // ── By status ────────────────────────────────────────────────────────
        $byStatus = (clone $base())
            ->selectRaw("COALESCE(NULLIF(contract_status, ''), 'unspecified') as status, COUNT(*) as count")
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['status' => (string) $r->status, 'count' => (int) $r->count])
            ->values();

        // ── By type ──────────────────────────────────────────────────────────
        $byType = (clone $base())
            ->leftJoin('contract_types', 'contract_types.id', '=', 'contracts.contract_type_id')
            ->selectRaw("COALESCE(contract_types.name, 'Unassigned') as type, COUNT(*) as count, COALESCE(SUM(contracts.contract_value),0) as value")
            ->groupBy('type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['type' => (string) $r->type, 'count' => (int) $r->count, 'value' => round((float) $r->value, 2)])
            ->values();

        // ── Value by status ──────────────────────────────────────────────────
        $valueByStatus = (clone $base())
            ->selectRaw("COALESCE(NULLIF(contract_status, ''), 'unspecified') as status, COALESCE(SUM(contract_value),0) as value")
            ->groupBy('status')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($r) => ['status' => (string) $r->status, 'value' => round((float) $r->value, 2)])
            ->values();

        // ── Monthly trend (created vs expiring) over last 12 months ──────────
        $monthlyTrend = $this->monthlyTrend($status, $type);

        // ── Top suppliers ────────────────────────────────────────────────────
        $bySupplier = $this->bySupplier($base());

        // ── Risk distribution from contract_ai.risk_score ────────────────────
        $riskDistribution = $this->riskDistribution($status, $type, $from, $to);

        return Inertia::render('Modules/ContractManagement/Analytics/Index', [
            'period' => $period,
            'dateFrom' => $request->input('date_from'),
            'dateTo' => $request->input('date_to'),
            'filters' => [
                'status' => $status,
                'type' => $type ? (int) $type : null,
            ],
            'filterOptions' => [
                'statuses' => (clone Contract::query())
                    ->selectRaw("DISTINCT COALESCE(NULLIF(contract_status, ''), 'unspecified') as status")
                    ->orderBy('status')
                    ->pluck('status')
                    ->map(fn ($s) => ['value' => $s, 'label' => ucfirst((string) $s)])
                    ->values(),
                'types' => DB::table('contract_types')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name])
                    ->values(),
            ],
            'kpis' => $kpis,
            'byStatus' => $byStatus,
            'byType' => $byType,
            'valueByStatus' => $valueByStatus,
            'monthlyTrend' => $monthlyTrend,
            'bySupplier' => $bySupplier,
            'riskDistribution' => $riskDistribution,
        ]);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function resolveRange(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom || $dateTo) {
            return [
                $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : null,
                $dateTo ? Carbon::parse($dateTo)->endOfDay() : null,
            ];
        }

        $now = Carbon::now();

        return match ($period) {
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter' => [$now->copy()->subMonths(3)->startOfDay(), $now->copy()->endOfDay()],
            'halfyear' => [$now->copy()->subMonths(6)->startOfDay(), $now->copy()->endOfDay()],
            'lastyear' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            'all' => [null, null],
            default => [$now->copy()->startOfYear(), $now->copy()->endOfYear()], // 'year'
        };
    }

    private function monthlyTrend(?string $status, ?string $type): array
    {
        $start = Carbon::now()->subMonths(11)->startOfMonth();
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'created' => 0, 'expiring' => 0];
        }

        $createdQ = Contract::query()->where('created_at', '>=', $start);
        $expiringQ = Contract::query()->whereNotNull('end_date')->where('end_date', '>=', $start);
        if ($status) {
            $createdQ->where('contract_status', $status);
            $expiringQ->where('contract_status', $status);
        }
        if ($type) {
            $createdQ->where('contract_type_id', $type);
            $expiringQ->where('contract_type_id', $type);
        }

        foreach ($createdQ->get(['created_at']) as $c) {
            $k = Carbon::parse($c->created_at)->format('Y-m');
            if (isset($months[$k])) {
                $months[$k]['created']++;
            }
        }
        foreach ($expiringQ->get(['end_date']) as $c) {
            $k = Carbon::parse($c->end_date)->format('Y-m');
            if (isset($months[$k])) {
                $months[$k]['expiring']++;
            }
        }

        return array_values($months);
    }

    private function bySupplier(Builder $base): array
    {
        $hasSuppliers = Schema::hasTable('suppliers');
        $nameCol = $hasSuppliers && Schema::hasColumn('suppliers', 'company_name') ? 'company_name' : 'name';

        $q = (clone $base)->whereNotNull('contracts.supplier_id');

        if ($hasSuppliers) {
            $q->leftJoin('suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
                ->selectRaw("COALESCE(suppliers.{$nameCol}, CONCAT('Supplier #', contracts.supplier_id)) as supplier, COUNT(*) as count, COALESCE(SUM(contracts.contract_value),0) as value");
        } else {
            $q->selectRaw("CONCAT('Supplier #', contracts.supplier_id) as supplier, COUNT(*) as count, COALESCE(SUM(contracts.contract_value),0) as value");
        }

        return $q->groupBy('supplier')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['supplier' => (string) $r->supplier, 'count' => (int) $r->count, 'value' => round((float) $r->value, 2)])
            ->values()
            ->all();
    }

    private function riskDistribution(?string $status, ?string $type, ?Carbon $from, ?Carbon $to): array
    {
        $q = Contract::query()
            ->join('contract_ai', 'contract_ai.contract_id', '=', 'contracts.id')
            ->whereNotNull('contract_ai.risk_score');
        if ($status) {
            $q->where('contracts.contract_status', $status);
        }
        if ($type) {
            $q->where('contracts.contract_type_id', $type);
        }
        if ($from) {
            $q->where('contracts.created_at', '>=', $from);
        }
        if ($to) {
            $q->where('contracts.created_at', '<=', $to);
        }

        $low = (clone $q)->where('contract_ai.risk_score', '<', 34)->count();
        $medium = (clone $q)->whereBetween('contract_ai.risk_score', [34, 66])->count();
        $high = (clone $q)->where('contract_ai.risk_score', '>=', 67)->count();

        return [
            ['band' => 'Low', 'count' => $low],
            ['band' => 'Medium', 'count' => $medium],
            ['band' => 'High', 'count' => $high],
        ];
    }
}
