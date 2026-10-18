<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierScar;
use App\Modules\SupplierManagement\Models\SupplierScreening;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SupplierAnalyticsController extends Controller
{
    /** Supplier status (is_active) integer => human label. */
    private const STATUS_LABELS = [
        0 => 'Pending',
        1 => 'Active',
        2 => 'Refused',
        3 => 'Pending KYC',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $period   = (string) $request->input('period', 'year');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');
        $segment  = $request->input('segment');
        $status   = $request->input('status');

        [$from, $to] = $this->resolveRange($period, $dateFrom, $dateTo);

        // Base supplier query honouring segment/status filters.
        $base = fn () => Supplier::query()
            ->when($segment !== null && $segment !== '', fn ($q) => $q->where('segment', $segment))
            ->when($status !== null && $status !== '', fn ($q) => $q->where('is_active', (int) $status));

        // ── KPIs ────────────────────────────────────────────────────────────────
        $kpis = [
            'total'         => (int) $base()->count(),
            'active'        => (int) $base()->where('is_active', 1)->count(),
            'pending_kyc'   => (int) $base()->where('is_active', 3)->count(),
            'high_risk'     => (int) SupplierScreening::where('risk_level', 'high')->count(),
            'open_scars'    => (int) SupplierScar::open()->count(),
            'expiring_docs_90' => (int) SupplierDocument::query()
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [Carbon::today(), Carbon::today()->addDays(90)])
                ->count(),
        ];

        // ── By status ─────────────────────────────────────────────────────────────
        $statusCounts = $base()
            ->select('is_active', DB::raw('COUNT(*) as total'))
            ->groupBy('is_active')
            ->pluck('total', 'is_active');

        $byStatus = [];
        foreach (self::STATUS_LABELS as $value => $label) {
            $byStatus[] = ['status' => $label, 'count' => (int) ($statusCounts[$value] ?? 0)];
        }

        // ── By segment ──────────────────────────────────────────────────────────────
        $bySegment = $base()
            ->select(DB::raw("COALESCE(NULLIF(segment, ''), 'Unsegmented') as seg"), DB::raw('COUNT(*) as total'))
            ->groupBy('seg')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['segment' => (string) $r->seg, 'count' => (int) $r->total])
            ->all();

        // ── Risk by level (from screenings) ───────────────────────────────────────
        $riskByLevel = SupplierScreening::query()
            ->select(DB::raw("COALESCE(NULLIF(risk_level, ''), 'unknown') as lvl"), DB::raw('COUNT(*) as total'))
            ->groupBy('lvl')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['level' => (string) $r->lvl, 'count' => (int) $r->total])
            ->all();

        // ── Onboarding trend (suppliers created per month, within range) ──────────
        $onboardingTrend = $base()
            ->whereBetween('created_at', [$from, $to])
            ->select(DB::raw("to_char(created_at, 'YYYY-MM') as month"), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw("to_char(created_at, 'YYYY-MM')"))
            ->orderBy(DB::raw("to_char(created_at, 'YYYY-MM')"))
            ->get()
            ->map(fn ($r) => ['month' => (string) $r->month, 'count' => (int) $r->total])
            ->all();

        // ── Top spend (guarded) ───────────────────────────────────────────────────
        $topSpend = $this->topSpend();

        // ── Document expiry buckets ───────────────────────────────────────────────
        $docExpiryBuckets = $this->docExpiryBuckets();

        return Inertia::render('Modules/SupplierManagement/Analytics/Index', [
            'period'   => $period,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'filters'  => [
                'segment' => $segment,
                'status'  => $status !== null && $status !== '' ? (int) $status : null,
            ],
            'statusOptions'  => collect(self::STATUS_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all(),
            'segmentOptions' => Supplier::query()
                ->whereNotNull('segment')->where('segment', '!=', '')
                ->distinct()->orderBy('segment')->pluck('segment')->all(),
            'kpis'             => $kpis,
            'byStatus'         => $byStatus,
            'bySegment'        => $bySegment,
            'riskByLevel'      => $riskByLevel,
            'onboardingTrend'  => $onboardingTrend,
            'topSpend'         => $topSpend,
            'docExpiryBuckets' => $docExpiryBuckets,
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(string $period, $dateFrom, $dateTo): array
    {
        if ($dateFrom || $dateTo) {
            $from = $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : Carbon::now()->subYear()->startOfDay();
            $to   = $dateTo ? Carbon::parse($dateTo)->endOfDay() : Carbon::now()->endOfDay();

            return [$from, $to];
        }

        $now = Carbon::now();

        return match ($period) {
            'month'    => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'quarter'  => [$now->copy()->subMonths(3)->startOfDay(), $now->copy()->endOfDay()],
            'halfyear' => [$now->copy()->subMonths(6)->startOfDay(), $now->copy()->endOfDay()],
            'lastyear' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default    => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
        };
    }

    /** @return array<array{supplier:string,total:float}> */
    private function topSpend(): array
    {
        if (! Schema::hasTable('supplier_invoices')) {
            return [];
        }

        return DB::table('supplier_invoices')
            ->select('supplier_id', DB::raw('SUM(amount) as total'))
            ->whereNull('deleted_at')
            ->groupBy('supplier_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $supplier = Supplier::find($row->supplier_id, ['id', 'company_name']);

                return [
                    'supplier' => (string) ($supplier?->company_name ?? '—'),
                    'total'    => (float) $row->total,
                ];
            })
            ->all();
    }

    /** @return array<array{bucket:string,count:int}> */
    private function docExpiryBuckets(): array
    {
        $today = Carbon::today();

        $buckets = [
            '0-30'  => [$today->copy(), $today->copy()->addDays(30)],
            '31-60' => [$today->copy()->addDays(31), $today->copy()->addDays(60)],
            '61-90' => [$today->copy()->addDays(61), $today->copy()->addDays(90)],
        ];

        $out = [];
        foreach ($buckets as $label => [$start, $end]) {
            $out[] = [
                'bucket' => $label,
                'count'  => (int) SupplierDocument::query()
                    ->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [$start, $end])
                    ->count(),
            ];
        }

        return $out;
    }
}
